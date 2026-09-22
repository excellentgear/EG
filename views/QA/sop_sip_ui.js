/**
 * sop_sip_ui.js — 作業標準書(SOP)／標準檢驗指導書(SIP) 前端
 * 建立：2026-09-21　｜　2026-09-21（二次）依使用者交辦那一批大改
 *
 * 由 sop_sip.php 提供：SS_API／SS_CSRF／SS_PERMS／SS_KINDS／SS_SCOPES／SS_SLOTS／SS_SLOTS_D／SS_STATUSES／SS_TODAY
 *   SS_SLOTS＝簽核的先後順序（製表→審核→核准）；SS_SLOTS_D＝簽章格由左到右的排法（核准→審核→製表）
 * 規則的唯一來源在後端 sopsip_lib.php，這裡只做「即時提示」，存檔仍以後端回覆為準（鐵律8）。
 *
 * 這一版的幾個重點
 *   ① 製程＝紙本上的「工程名稱」，一律從製程主檔模糊搜尋挑（209 筆，不可用攤開的下拉）
 *   ② 設備操作說明書綁「機台型號」，選了型號自動把在用的機台全部帶進來、可逐台勾掉
 *   ③ 綁定對象一選好就即時檢查「是不是已經有一份了」，有就擋下並直接給連結去更新那一份
 *   ④ 文件名稱自動產生（使用者改過就不再蓋掉）
 *   ⑤ 圖面與段落附件可旋轉——**只轉這份文件，原檔不動**（料號附件那張圖全站都看得到）
 */
var TAB = 'sop';            // 目前分頁
var ROWS = [];              // 目前清單（全部，分頁在前端做）
var PAGE = 1, PER = 8;   // 每頁預設 8 筆（使用者 2026-09-21 指定）
var CUR = null;             // 目前打開的文件 detail
var NEW = {};               // 新增跳窗目前的狀態（綁定對象、自動名稱有沒有被改過）

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
/* 點跳窗外面（遮罩）才關閉，而且**一定要「按下」與「放開」都在遮罩上**。
   只看 click 的話有兩種情況會把跳窗莫名其妙關掉，使用者看起來就是「按了沒反應」：
   ⑴ 原生下拉（select）彈出的選單是瀏覽器自己畫的視窗，點選項時底下的頁面可能收到一個
      落在遮罩上的 click；⑵ 在跳窗裡框選文字、滑鼠放開時滑到跳窗外面。 */
var MASK_DOWN = null;
$(document).on('mousedown', '.ss-mask', function (e) { MASK_DOWN = (e.target === this) ? this : null; });
$(document).on('click', '.ss-mask', function (e) {
    if (e.target === this && MASK_DOWN === this) $(this).removeClass('on');
    MASK_DOWN = null;
});

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

/* ══════════════════════ 共用：打字搜尋的建議清單 ══════════════════════ */
/*
 * 建議清單一律用 position:fixed 由 JS 定位——跳窗的 .m-body 是 overflow:auto 的捲動容器，
 * absolute 會被整個裁掉，症狀是「打了字卻沒有清單可以選」，只數 DOM 節點的測試抓不到。
 * 另外**手動改字就自動解除綁定**，否則會出現「畫面寫 A、實際綁著 B」而且完全看不出來。
 */
var $AC = $('<div class="ac-list"></div>').appendTo('body');
var acTimer = null, acSeq = 0, acCur = null;

function acAttach(inputSel, opt) {
    $(document).on('input', inputSel, function () {
        var $in = $(this);
        if (opt.hidden) $(opt.hidden).val('');
        if (opt.onClear) opt.onClear();
        var kw = $in.val().trim();
        clearTimeout(acTimer);
        if (!kw) { $AC.hide(); return; }
        var my = ++acSeq;
        acTimer = setTimeout(function () {
            var act = (typeof opt.action === 'function') ? opt.action() : opt.action;
            api(act, opt.params ? opt.params(kw) : { kw: kw }, function (res) {
                if (my !== acSeq) return;          // 打字很快時只採用最後一次查詢的結果
                var rows = res.rows || [], h = '';
                $.each(rows, function (i, r) { h += '<div class="it" data-i="' + i + '">' + opt.row(r) + '</div>'; });
                acCur = opt;
                $AC.html(h || '<div class="it muted-help">查無資料</div>').data('rows', rows);
                var o = $in.offset(), st = $(window).scrollTop();
                $AC.css({ left: o.left, top: o.top - st + $in.outerHeight() + 2,
                          width: Math.max(320, $in.outerWidth()) }).show();
            });
        }, 180);
    });
}
$AC.on('click', '.it', function () {
    var rows = $AC.data('rows') || [], r = rows[num($(this).data('i'))];
    $AC.hide();
    if (!r || !acCur) return;
    acCur.pick(r);
});
$(document).on('click', function (e) {
    if (!$(e.target).closest('input').length && !$(e.target).closest($AC).length) $AC.hide();
});

/* ══════════════════════ 清單 ══════════════════════ */

function kindOptions(sel, withAll) {
    var h = withAll ? '<option value="">全部</option>' : '';
    $.each(SS_KINDS, function (k, d) {
        if (d.tab !== TAB) return;
        h += '<option value="' + k + '">' + esc(d.label) + '</option>';
    });
    $(sel).html(h);
}

/** keepPage=true 時留在目前這一頁（存完檔重新載入清單不該把人踢回第一頁） */
function load(keepPage) {
    var want = keepPage ? PAGE : 1;
    api('list', {
        tab: TAB, kind: $('#fKind').val() || '', scope: $('#fScope').val() || '',
        status: $('#fStatus').val() || '', year: $('#fYear').val() || '', kw: $('#fKw').val() || ''
    }, function (res) {
        ROWS = res.rows || [];
        PAGE = Math.max(1, want);
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
        var bind;
        if (r.scope === 'machine') {
            bind = '<b>' + esc(r.machine_model || '') + '</b>'
                 + (r.asset_text ? '<br><span class="muted-help">' + esc(r.asset_text) + '</span>' : '')
                 + (num(r.machine_missing_cnt)
                     ? '<br><span class="muted-help" style="color:#A4541A;">同型號還有 '
                       + num(r.machine_missing_cnt) + ' 台未納入</span>' : '');
        } else if (r.scope === 'part') {
            bind = esc(r.part_no_text || '');
        } else {
            bind = '<span class="muted-help">通用</span>';
        }
        h += '<tr data-doc="' + num(r.doc_id) + '" data-ver="' + num(r.ver_id) + '">'
          + '<td>' + esc(r.kind_label) + '<br><span class="muted-help">' + esc(r.tab_label) + '｜' + esc(r.scope_label) + '</span></td>'
          + '<td>' + bind + '</td>'
          + '<td><b>' + esc(r.title) + '</b></td>'
          + '<td class="c">' + esc(r.proc_name || '') + '</td>'
          + '<td>' + esc(r.customer_name || '') + '</td>'
          + '<td class="c">' + esc(r.ver_no) + (num(r.ver_cnt) > 1 ? '<br><span class="muted-help">共 ' + num(r.ver_cnt) + ' 版</span>' : '') + '</td>'
          + '<td class="c">' + dispDate(r.form_date) + '</td>'
          + '<td class="c"><span class="st st-' + esc(r.status) + '">' + esc(r.status_label) + '</span></td>'
          + '<td class="c">' + num(r.sign_cnt) + ' / ' + num(r.sign_total) + '</td>'
          + '<td class="c">'
              + '<button class="btn btn-xs btn-warm-o act-open">開啟</button> '
              + '<button class="btn btn-xs btn-warm-o act-print">列印</button> '
              + '<button class="btn btn-xs act-del">刪除</button>'
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
        + [5, 8, 10, 20, 50].map(function (n) { return '<option value="' + n + '"' + (n === PER ? ' selected' : '') + '>' + n + '</option>'; }).join('')
        + '</select>';
    $('#pagerTop').html(pg);   // 下方那排已移除，右上角一排就夠
}

$(document).on('click', '.pager button[data-pg]', function () { PAGE = num($(this).data('pg')); renderList(); });
$(document).on('change', '#perSel', function () { PER = num($(this).val()) || 8; PAGE = 1; renderList(); });
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
    var $t = $(e.target);
    if ($t.hasClass('act-print')) { doPrint(num($(this).data('ver'))); return; }
    if ($t.hasClass('act-del')) { delDoc(num($(this).data('doc')), $(this).find('td').eq(2).text()); return; }
    openDoc(num($(this).data('ver')));
});

/** 刪除：能不能刪由後端判定（管理員一律可刪；一般人只能刪自己建立、還沒核准過的） */
function delDoc(docId, title) {
    if (!docId) return;
    if (!confirm('確定刪除「' + title + '」這份文件？\n\n連同它的所有版次都會從清單上移除。')) return;
    post('doc_delete', { doc_id: docId }, function () { load(true); });
}

/* ══════════════════════ 新增 ══════════════════════ */

$('#btnNew').on('click', function () {
    kindOptions('#nKind', false);
    NEW = { titleTouched: false, machines: [], dups: [] };
    $('#nErr').text(''); $('#nDup').html('');
    $('#nModel').val(''); $('#nModelVal').val('');
    $('#nTool').val(''); $('#nToolId').val('');
    $('#nPart').val(''); $('#nPartId').val('');
    $('#nProc').val(''); $('#nProcNo').val('');
    $('#nCus').val(''); $('#nCusId').val('');
    $('#nTitle').val(''); $('#nVer').val('01');
    $('#nDate').val(SS_TODAY); $('#nNote').val('初訂');
    $('#nApplyTpl').prop('checked', true);
    $('#nMachines').html('先選機台型號。').addClass('muted-help');
    syncScope();
    openMask('maskNew');
});

/** 版面換了就重算「這個版面可以用哪些適用範圍」，並把分頁（SOP／SIP）標出來 */
function syncScope() {
    var kind = $('#nKind').val(), h = '';
    var allow = (window.SS_KIND_SCOPES && SS_KIND_SCOPES[kind])
             || ((kind === 'equip') ? ['machine', 'tool'] : ['general', 'part']);
    $.each(allow, function (i, s) { h += '<option value="' + s + '">' + esc(SS_SCOPES[s]) + '</option>'; });
    $('#nScope').html(h);
    var tab = (SS_KINDS[kind] ? SS_KINDS[kind].tab : 'sop');
    $('#nTabTag').text(tab === 'sip' ? 'SIP 標準檢驗指導書' : 'SOP 作業標準書');
    syncScopeFields();
}

/** 適用範圍換了就決定哪幾列要出現（機台／料號／檢驗項目代入） */
function syncScopeFields() {
    var kind = $('#nKind').val(), s = $('#nScope').val();
    $('.mrow').toggle(s === 'machine');
    $('.trow').toggle(s === 'tool');
    $('.prow').toggle(s === 'part');
    $('.srow').toggle(kind === 'sip');
    // 客戶：綁料號時由料號主檔帶入，欄位唯讀；通用型才可以自己挑
    var byPart = (s === 'part');
    $('#nCus').prop('readonly', byPart).toggleClass('ro-auto', byPart);
    $('#nCusHint').text(byPart ? '綁了料號就由料號主檔自動帶入，不用也不可以自己打。'
                               : '通用型文件可以自己挑客戶（打編號或簡稱從清單選），不挑也可以。');
    // 設備操作說明書不綁製程：machine_list 本來就有製程類別，再綁一次就是同一份資訊兩個來源
    $('#nProcLab,#nProc').closest('.frm').find('#nProcLab').toggle(kind !== 'equip');
    $('#nProc').closest('.ac-wrap').toggle(kind !== 'equip');
    if (kind === 'equip') { $('#nProc').val(''); $('#nProcNo').val(''); }
    probe();
}
$('#nKind').on('change', syncScope);
$('#nScope').on('change', function () {
    $('#nPart').val(''); $('#nPartId').val('');
    $('#nTool').val(''); $('#nToolId').val('');
    $('#nModel').val(''); $('#nModelVal').val('');
    $('#nMachines').html('先選機台型號。').addClass('muted-help');
    NEW.machines = [];
    syncScopeFields();
});

/* 綁定對象：機台型號 */
acAttach('#nModel', {
    action: 'machine_models', hidden: '#nModelVal',
    onClear: function () { NEW.machines = []; $('#nMachines').html('先選機台型號。').addClass('muted-help'); probe(); },
    row: function (r) {
        return '<span class="hit">' + esc(r.machine_model) + '</span>　' + esc(r.machine || '')
             + '　<span class="muted-help">' + num(r.cnt) + ' 台：' + esc(r.asset_nos || '') + '</span>';
    },
    pick: function (r) {
        $('#nModel').val(r.machine_model);
        $('#nModelVal').val(r.machine_model);
        loadModelMachines(r.machine_model);
    }
});

/** 選了型號就把該型號在用的機台全部帶進來（使用者拍板），再逐台勾掉不適用的 */
function loadModelMachines(model) {
    api('machines_by_model', { model: model }, function (res) {
        NEW.machines = res.rows || [];
        var h = '';
        $.each(NEW.machines, function (i, m) {
            h += '<label><input type="checkbox" class="nmchk" value="' + num(m.machine_id) + '" checked> '
               + '<span class="mno">' + esc(m.asset_no || '(未編號)') + '</span> ' + esc(m.field_no || '') + '</label>';
        });
        $('#nMachines').removeClass('muted-help')
            .html(h || '<span class="muted-help">這個型號目前沒有在用的機台。</span>');
        probe();
    });
}

/* 綁定對象：量具（檢驗設備一覽表） */
acAttach('#nTool', {
    action: 'search_tool', hidden: '#nToolId', onClear: probe,
    row: function (r) {
        return '<span class="hit">' + esc(r.tool_no) + '</span>　' + esc(r.tool_type || '')
             + '　<span class="muted-help">' + esc(r.spec_desc || r.manufacturer || '') + '</span>';
    },
    pick: function (r) { $('#nTool').val(r.tool_no); $('#nToolId').val(r.tool_id); probe(); }
});

/* 綁定對象：料號 */
acAttach('#nPart', {
    action: 'search_part', hidden: '#nPartId',
    onClear: function () { $('#nCus').val(''); $('#nCusId').val(''); probe(); },
    row: function (r) {
        return '<span class="hit">' + esc(r.D_Setting_Id) + '</span>　' + esc(r.customer || r.Customer_Id || '')
             + '　<span class="muted-help">#' + num(r.d_id) + '</span>';
    },
    pick: function (r) { $('#nPart').val(r.D_Setting_Id); $('#nPartId').val(r.d_id); probe(); }
});

/* 製程（＝工程名稱） */
acAttach('#nProc', {
    action: 'search_process', hidden: '#nProcNo',
    onClear: probe,
    row: function (r) {
        return '<span class="hit">' + esc(r.process_name) + '</span>　<span class="muted-help">編號 '
             + num(r.process_no) + '　' + esc(r.process_type || '') + '</span>';
    },
    pick: function (r) { $('#nProc').val(r.process_name); $('#nProcNo').val(r.process_no); probe(); }
});

/* 客戶（只有通用型用得到） */
acAttach('#nCus', {
    action: 'search_customer', hidden: '#nCusId',
    row: function (r) {
        return '<span class="hit">' + esc(r.customer_id) + '</span>　' + esc(r.customer || '')
             + '　<span class="muted-help">' + esc(r.customer_full || '') + '</span>';
    },
    pick: function (r) { $('#nCus').val(r.customer); $('#nCusId').val(r.customer_id); }
});

/* 使用者自己動過文件名稱就不再自動蓋掉 */
$(document).on('input', '#nTitle', function () { NEW.titleTouched = true; });
$(document).on('change', '.nmchk', probe);

/**
 * 綁定對象一變就問後端三件事：會不會撞到既有文件、自動名稱是什麼、（綁料號時）客戶是誰。
 * 放同一支是刻意的——分兩支會送兩次一樣的參數。
 */
var probeTimer = null;
function probe() {
    clearTimeout(probeTimer);
    probeTimer = setTimeout(function () {
        var kind = $('#nKind').val(), s = $('#nScope').val();
        if (!kind || !s) return;
        var p = {
            kind: kind, scope: s,
            machine_model: $('#nModelVal').val() || '',
            part_d_id: num($('#nPartId').val()),
            tool_id: num($('#nToolId').val()),
            process_no: num($('#nProcNo').val())
        };
        if (s === 'machine' && !p.machine_model) { $('#nDup').html(''); return; }
        if (s === 'tool' && !p.tool_id) { $('#nDup').html(''); return; }
        if (s === 'part' && !p.part_d_id) { $('#nDup').html(''); return; }
        api('bind_probe', p, function (res) {
            NEW.dups = res.dups || [];
            if (!NEW.titleTouched && res.title) $('#nTitle').val(res.title);
            if (res.customer) {
                $('#nCus').val(res.customer.name || '');
                $('#nCusId').val(res.customer.id || '');
            }
            if (kind === 'sip') {
                var n = (res.default_items || []).length;
                var cfg = res.proc_cfg || {};
                $('#nApplyTpl').prop('checked', n > 0 && num(cfg.auto_apply) === 1);
                $('#nTplHint').text(n > 0
                    ? '這個製程目前有 ' + n + ' 個預設項目（含標準項目），代入之後仍然可以逐列刪掉不要的。'
                    : '這個製程還沒有設定預設的檢驗項目（要設定請按右上角「設定」→ 檢驗項目預設值）。');
            }
            renderDup();
        });
    }, 200);
}

/** 重複一律擋下（使用者要求不可有兩份一樣的），並直接給連結去更新既有那一份 */
function renderDup() {
    if (!NEW.dups || !NEW.dups.length) { $('#nDup').html(''); return; }
    var h = '<div class="dup-box"><div class="t">這個對象＋這個製程已經有文件了，不可以再建一份</div>';
    $.each(NEW.dups, function (i, d) {
        h += '<div class="row"><b>' + esc(d.title) + '</b>　版次 ' + esc(d.ver_no || '')
           + '　' + esc(d.status_label || '') + '　<span class="muted-help">'
           + esc(d.created_by_name || '') + ' 建立於 ' + dispDate(d.created_at) + '</span>　'
           + '<button class="btn btn-xs btn-warm dup-go" data-ver="' + num(d.ver_id) + '">開啟並更新這一份</button></div>';
    });
    h += '<div class="muted-help" style="margin-top:5px;">'
       + '要建立不同製程的文件，請在上面的「製程」挑另一個製程。</div></div>';
    $('#nDup').html(h);
}
$(document).on('click', '.dup-go', function () {
    var v = num($(this).data('ver'));
    closeMask('maskNew');
    if (v) openDoc(v);
});

$('#nSave').on('click', function () {
    var kind = $('#nKind').val(), s = $('#nScope').val(), err = [];
    if (!$('#nTitle').val().trim()) err.push('文件名稱');
    if (!$('#nDate').val()) err.push('表單日期');
    if (s === 'machine' && !$('#nModelVal').val()) err.push('機台型號（要從清單挑，打字不選不算）');
    if (s === 'tool' && !num($('#nToolId').val())) err.push('量具（要從清單挑，打字不選不算）');
    if (s === 'part' && !num($('#nPartId').val())) err.push('料號（要從清單挑，打字不選不算）');
    if ($('#nProc').val().trim() && !num($('#nProcNo').val())) err.push('製程（打了字但沒有從清單挑）');
    if (err.length) { $('#nErr').text('還沒填：' + err.join('、')); return; }
    if (NEW.dups && NEW.dups.length) {
        $('#nErr').text('已經有一份同樣的文件了，請直接更新那一份（或換一個製程）。');
        return;
    }
    $('#nErr').text('');

    var ids = [];
    $('.nmchk:checked').each(function () { ids.push(num($(this).val())); });
    if (s === 'machine' && !ids.length) { $('#nErr').text('至少要勾一台機器編號。'); return; }

    post('doc_save', {
        kind: kind, scope: s,
        machine_model: s === 'machine' ? $('#nModelVal').val() : '',
        machine_ids: JSON.stringify(ids),
        tool_id: s === 'tool' ? num($('#nToolId').val()) : 0,
        part_d_id: s === 'part' ? num($('#nPartId').val()) : 0,
        process_no: num($('#nProcNo').val()),
        customer_id: s === 'part' ? '' : ($('#nCusId').val() || ''),
        title: $('#nTitle').val(),
        ver_no: $('#nVer').val(), form_date: $('#nDate').val(), rev_note: $('#nNote').val(),
        apply_default: $('#nApplyTpl').is(':checked') ? 1 : 0
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

/**
 * 綁定狀態小籤（唯一實作）。使用者 2026-09-22 要求：需要綁定的欄位，真的綁到主檔之後
 * 要看得到打勾與被綁定的編號，才確認得了。**只打字沒從清單挑不算綁定**——那種情況
 * 客戶會被後端安靜地不存、製程會被擋下，畫面上原本一點跡象都沒有。
 * @param ok   有沒有真的綁到（一律看「存下來的 id」，不是看輸入框有沒有字）
 * @param code 綁到什麼（客戶編號／製程編號／主檔 id），印出來給人核對
 * @param why  沒綁到時的原因或下一步
 */
function bindTag(ok, code, why) {
    return ok ? '<span class="bt bt-ok" title="已經綁到主檔">✓ 已綁定　' + esc(code) + '</span>'
              : '<span class="bt bt-no">未綁定' + (why ? '　' + esc(why) : '') + '</span>';
}

/** 製程與客戶是打字挑的，隨時可能變，所以每次改動都重畫一次小籤 */
function refreshBindTags() {
    if ($('#btProc').length) {
        var pno = num($('#fProcNo').val()), ptx = ($('#fProc').val() || '').trim();
        $('#btProc').html(bindTag(pno > 0, '製程編號 ' + pno,
            ptx ? '打了字沒從清單挑，存檔會被擋下' : '尚未選擇'));
    }
    if ($('#btCus').length) {
        var cid = ($('#fCusId').val() || '').trim(), ctx = ($('#fCus').val() || '').trim();
        $('#btCus').html(bindTag(cid !== '', '客戶編號 ' + cid,
            ctx ? '打了字沒從清單挑，存檔不會存到客戶' : '尚未選擇'));
    }
}
/* 挑完之後又自己改字＝綁定已經對不上了，一律把綁定解除（不解除就會「畫面寫 A、實際綁著 B」，
   而且完全看不出來——溝通管理那個自動完成踩過同一個坑）。 */
$(document).on('input', '#fProc', function () {
    if (($(this).val() || '') !== ($(this).data('picked') || '')) $('#fProcNo').val('');
    refreshBindTags();
});
$(document).on('input', '#fCus', function () {
    if (($(this).val() || '') !== ($(this).data('picked') || '')) $('#fCusId').val('');
    refreshBindTags();
});

/** 表頭：三種版面各自的欄位 */
function headHtml() {
    var v = CUR.ver, d = CUR.doc, ro = CUR.can_edit ? '' : ' readonly';
    var h = '<div class="sec"><h5>表頭</h5><div class="frm">';
    h += '<label>文件名稱</label><div class="wide"><input id="fTitle" value="' + esc(d.title) + '"' + ro + '></div>';

    if (d.scope === 'tool') {
        var tm = CUR.machine_meta || {};
        h += '<label>量具編號</label><div class="bindline"><input value="' + esc(tm.asset_text || '') + '" readonly>'
           + bindTag(num(d.tool_id) > 0, '量具主檔 #' + num(d.tool_id)) + '</div>'
           + '<label>量具種類</label><div><input value="' + esc(tm.m_name || '') + '" readonly></div>';
    } else if (d.scope === 'machine') {
        var mm = CUR.machine_meta || {};
        var mcnt = (CUR.machines || []).length;
        h += '<label>機台型號</label><div class="bindline"><input value="' + esc(d.machine_model || '') + '" readonly>'
           + bindTag(!!(d.machine_model || ''), '機台主檔　' + mcnt + ' 台') + '</div>'
           + '<label>機器編號</label><div><input value="' + esc(mm.asset_text || '') + '" readonly></div>';
        if ((CUR.machine_missing || []).length) {
            var mis = [];
            $.each(CUR.machine_missing, function (i, m) { mis.push(m.asset_no || m.field_no); });
            h += '<div class="full muted-help" style="color:#A4541A;">同型號還有 ' + mis.length
               + ' 台沒有納入這份 SOP：' + esc(mis.join('、'))
               + (CUR.can_edit ? '　<button class="btn btn-xs btn-warm-o" id="btnEditMachines">調整機器編號</button>' : '')
               + '</div>';
        } else if (CUR.can_edit) {
            h += '<div class="full"><button class="btn btn-xs btn-warm-o" id="btnEditMachines">調整機器編號</button></div>';
        }
    } else if (d.scope === 'part') {
        h += '<label>產品料號</label><div class="bindline"><input value="' + esc(d.part_no_text || '') + '" readonly>'
           + bindTag(num(d.part_d_id) > 0, '料號主檔 #' + num(d.part_d_id)) + '</div>'
           + '<label>客戶名稱</label><div class="bindline"><input class="ta-c" value="' + esc(d.customer_name || '') + '" readonly '
           + 'title="綁了料號就由料號主檔決定，不可手打">'
           + bindTag(!!(d.customer_id || ''), '客戶編號 ' + (d.customer_id || ''), '這個料號的主檔沒有綁客戶') + '</div>';
    } else {
        h += '<label>適用範圍</label><div><input value="通用（不綁特定機台或料號）" readonly></div>'
           + '<label>客戶名稱</label><div class="bindline"><span class="ac-wrap"><input id="fCus" class="ta-c" value="'
           + esc(d.customer_name || '') + '"' + ro + ' data-eg-hint="打客戶編號或簡稱"></span>'
           + '<input type="hidden" id="fCusId" value="' + esc(d.customer_id || '') + '">'
           + '<span id="btCus"></span></div>';
    }

    // 製程＝紙本上的「工程名稱」。設備操作說明書不綁製程（機台主檔本來就有製程類別）
    if (CUR.kind !== 'equip') {
        h += '<label>製程</label><div class="bindline"><span class="ac-wrap"><input id="fProc" value="'
           + esc(d.proc_name || '') + '"' + ro + ' data-eg-hint="打製程名稱或編號"></span>'
           + '<input type="hidden" id="fProcNo" value="' + num(d.process_no) + '">'
           + '<span id="btProc"></span></div>';
    }
    // 自動建立的文件可能綁錯適用範圍，開放管理員改（使用者 2026-09-22 要求）
    if (SS_PERMS.canAdmin && CUR.can_edit) {
        h += '<label>適用範圍</label><div class="wide">'
           + '<span class="muted-help">目前是「' + esc(SS_SCOPES[d.scope] || d.scope) + '」　</span>'
           + '<button class="btn btn-xs btn-warm-o" id="btnReScope">改綁定對象／適用範圍</button></div>';
    }
    // 列印紙張（預設：綁機台或量具＝A4 直式、料號相關＝A3 橫式）
    var pp = CUR.paper || {};
    h += '<label>列印紙張</label><div class="wide">'
       + '<select id="fPaper"' + (CUR.can_edit ? '' : ' disabled') + '>';
    $.each(CUR.papers || { A4: 'A4', A3: 'A3' }, function (k, lab) {
        h += '<option value="' + k + '"' + (k === pp.size ? ' selected' : '') + '>' + esc(lab) + '</option>';
    });
    h += '</select> <select id="fOrient"' + (CUR.can_edit ? '' : ' disabled') + '>';
    $.each(CUR.orients || { portrait: '直式', landscape: '橫式' }, function (k, lab) {
        h += '<option value="' + k + '"' + (k === pp.orient ? ' selected' : '') + '>' + esc(lab) + '</option>';
    });
    h += '</select><span class="muted-help">　沒特別改就用預設：綁機台或量具＝A4 直式，綁料號＝A3 橫式。</span></div>';
    h += '<label>版次</label><div><input id="fVer" value="' + esc(v.ver_no) + '"' + ro + '></div>'
       + '<label>表單日期</label><div><input type="date" id="fDate" value="' + esc(v.form_date || '') + '"' + ro + '></div>'
       + '<label>修改說明</label><div class="wide"><input id="fRev" value="' + esc(v.rev_note || '') + '"' + ro + '>'
       + '<div class="muted-help" style="margin-top:3px;">修改記錄上，<b>最舊的那一版固定印「制訂」</b>，'
       + '其餘印「修訂　＋　這裡填的內容」。匯入時自動填的「紙本匯入」「初訂」不會印出來。</div></div>';

    if (CUR.kind === 'equip') {
        h += '<label>機器製造商</label><div><input id="f_m_maker" value="' + esc(v.m_maker || '') + '"' + ro + '></div>'
           + '<label>機器名稱</label><div><input id="f_m_name" value="' + esc(v.m_name || '') + '"' + ro + '></div>'
           + '<label>型式規格</label><div><input id="f_m_spec" value="' + esc(v.m_spec || '') + '"' + ro + '></div>'
           + '<label>加工適用範圍</label><div><input id="f_m_range" value="' + esc(v.m_range || '') + '"' + ro + '></div>';
        if (CUR.can_edit) {
            h += '<div class="full muted-help">這四欄建立時已由機台主檔自動帶入，可以改成紙本上的寫法；'
               + '要重新照主檔帶一次請按 <button class="btn btn-xs btn-warm-o" id="btnRefillMachine">重新帶入</button></div>';
        }
    } else if (CUR.kind === 'process') {
        h += '<label>使用設備</label><div class="wide"><input id="f_use_equip" value="' + esc(v.use_equip || '') + '"' + ro + '>'
           + (CUR.can_edit ? '<div class="muted-help" style="margin-top:3px;">'
               + '<button class="btn btn-xs btn-warm-o" id="btnPickEquip">從機台挑（可複選機器編號）</button>'
               + '　也可以直接打字。</div>' : '') + '</div>'
           + '<label>預計工時</label><div><input id="f_est_hours" value="' + esc(v.est_hours || '') + '"' + ro + '></div>';
    }
    h += '</div></div>';
    return h;
}

/* ───────────── 段落（文字 ＋ 可加附件圖，列印時接在該段下方） ───────────── */

function secBox(id, key, label, val, hint) {
    var ro = CUR.can_edit ? '' : ' readonly';
    return '<div class="sec"><h5>' + esc(label) + '<span class="muted-help">' + esc(hint) + '</span></h5>'
         + '<textarea id="' + id + '" style="width:100%;min-height:96px;border:1px solid var(--line);'
         + 'border-radius:4px;padding:5px 7px;font-size:13px;line-height:1.7;"' + ro + '>' + esc(val || '') + '</textarea>'
         + '<div class="secwrap" data-sec="' + esc(key) + '">' + secFilesHtml(key) + '</div>'
         + '</div>';
}

/** 某一段的附件縮圖列（上傳後只重畫這一段，不整頁重載） */
function secFilesHtml(key) {
    var list = (CUR.section_files || {})[key] || [];
    var h = '<div class="secfiles">';
    $.each(list, function (i, f) {
        var nm = String(f.orig_name || f.file_name || '');
        var isImg = /\.(jpe?g|png|gif|bmp|webp)$/i.test(nm);
        h += '<div class="secfile" data-file="' + num(f.file_id) + '">'
           + (isImg
               ? '<img src="' + fileUrl(f.file_id) + '&t=' + (new Date()).getTime() + '" title="' + esc(nm) + '">'
               : '<a class="nofile" href="' + fileUrl(f.file_id) + '" target="_blank">'
                 + '<i class="fa fa-file-o fa-2x"></i><br>開啟檔案</a>')
           + '<div class="nm">' + esc(nm) + '</div>';
        if (CUR.can_edit) {
            h += '<div class="ops">'
               + (isImg
                   ? '<button class="btn btn-xs btn-warm-o f-rot" data-file="' + num(f.file_id) + '" data-deg="-90" title="左轉">↺</button> '
                     + '<button class="btn btn-xs btn-warm-o f-rot" data-file="' + num(f.file_id) + '" data-deg="90" title="右轉">↻</button> '
                   : '')
               + '<button class="btn btn-xs sf-del" data-file="' + num(f.file_id) + '">刪除</button></div>';
        }
        h += '</div>';
    });
    h += '</div>';
    if (CUR.can_edit) {
        h += '<div style="margin-top:5px;">'
           + '<button class="btn btn-xs btn-warm-o sec-up" data-sec="' + esc(key) + '">加附件圖</button>'
           + '<span class="muted-help">　加上去的圖列印時會接在這一段文字下方。</span></div>';
    }
    return h;
}

/** 設備操作說明書的三大段（都可以加附件圖） */
function equipHtml() {
    var v = CUR.ver;
    return secBox('f_op_method', 'op_method', '操作方法', v.op_method, '一行一個步驟')
         + secBox('f_cautions', 'cautions', '使用注意事項', v.cautions, '一行一條')
         + secBox('f_maintain', 'maintain', '保養維修要點', v.maintain, '一行一條');
}

/** 製造製程說明書的操作步驟明細 */
function stepsHtml() {
    var ro = CUR.can_edit ? '' : ' readonly', dis = CUR.can_edit ? '' : ' disabled';
    var h = '<div class="sec"><h5>操作步驟'
          + '<span class="muted-help">最後一列按 ↓ 自動加一列；沒填東西的末列按 ↑ 自動移除</span></h5>'
          + '<table class="grid" id="tblSteps"><thead><tr>'
          + '<th style="width:44px;">項次</th><th style="width:130px;">名稱</th><th style="width:170px;">參考圖示</th>'
          + '<th>操作步驟</th><th style="width:200px;">說明</th>'
          + (CUR.can_edit ? '<th style="width:38px;"></th>' : '') + '</tr></thead><tbody data-eg-row-add="stepAdd" data-eg-row-del="stepDel">';
    var rows = CUR.steps.length ? CUR.steps : (CUR.can_edit ? [{}] : []);
    $.each(rows, function (i, s) { h += stepRow(i, s, ro, dis); });
    h += '</tbody></table></div>';
    return h;
}
function stepImgCell(fid) {
    var h = '';
    if (num(fid)) {
        h += '<img class="thumb" src="' + fileUrl(fid) + '&t=' + (new Date()).getTime() + '" data-file="' + num(fid) + '">';
        if (CUR.can_edit) {
            h += '<div style="margin-top:2px;">'
               + '<button class="btn btn-xs btn-warm-o f-rot" data-file="' + num(fid) + '" data-deg="-90" title="左轉">↺</button> '
               + '<button class="btn btn-xs btn-warm-o f-rot" data-file="' + num(fid) + '" data-deg="90" title="右轉">↻</button></div>';
        }
    } else h += '<span class="muted-help">無</span>';
    if (CUR.can_edit) {
        h += '<div style="margin-top:2px;"><button class="btn btn-xs btn-warm-o s-pick">挑圖</button>'
           + (num(fid) ? ' <button class="btn btn-xs s-clr">移除</button>' : '') + '</div>';
    }
    return h;
}
function stepRow(i, s, ro, dis) {
    s = s || {};
    return '<tr data-img="' + num(s.img_file_id) + '">'
        + '<td class="c">' + (i + 1) + '</td>'
        + '<td><input class="s-name" value="' + esc(s.step_name || '') + '"' + ro + '></td>'
        + '<td class="c">' + stepImgCell(s.img_file_id) + '</td>'
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

/* ───────────── 標準檢驗指導書的檢驗項目 ───────────── */

/** 擔當者：只能挑部門，顯示文字由管理員設定（現場講「包裝」，組織上沒有這個部門） */
function ownerSel(val, ro) {
    var h = '<select class="i-own"' + (ro ? ' disabled' : '') + '><option value="">（未指定）</option>';
    $.each(CUR.owner_depts || [], function (i, d) {
        h += '<option value="' + num(d.dept_id) + '"' + (num(d.dept_id) === num(val) ? ' selected' : '') + '>'
           + esc(d.label) + '</option>';
    });
    return h + '</select>';
}
/** 檢驗方法：由管理員挑的幾個量具類型 ＋ 自建項目混合出來的清單 */
function methodSel(val, tt, ro) {
    var h = '<select class="i-mth"' + (ro ? ' disabled' : '') + '><option value="">（未指定）</option>';
    var hit = false;
    $.each(CUR.methods || [], function (i, m) {
        var sel = (String(m.text) === String(val || ''));
        if (sel) hit = true;
        h += '<option value="' + esc(m.text) + '" data-tt="' + num(m.tool_type_id) + '"' + (sel ? ' selected' : '') + '>'
           + esc(m.text) + '</option>';
    });
    // 舊資料寫的方法不在清單裡時仍要看得到（不可以安靜變成空白）
    if (!hit && val) h += '<option value="' + esc(val) + '" data-tt="' + num(tt) + '" selected>' + esc(val) + '（既有）</option>';
    return h + '</select>';
}
function itemsHtml() {
    var ro = CUR.can_edit ? '' : ' readonly';
    var h = '<div class="sec"><h5>檢驗項目'
          + '<span class="muted-help">最後一列按 ↓ 自動加一列；尺寸類填上下限，其餘填品質特性</span>'
          + (CUR.can_edit
              ? '<button class="btn btn-xs btn-warm-o" id="btnApplyTpl" style="margin-left:8px;">代入預設項目</button>'
              : '')
          + '</h5>';
    if (CUR.can_edit) {
        h += '<div class="muted-help" style="margin-bottom:4px;">'
           + '「代入預設項目」會把這個製程設定好的專屬項目與標準項目接在現有內容後面，'
           + '代入之後仍然可以逐列刪掉不要的。</div>';
    }
    // 品質特性給明確寬度：不給的話它是唯一的彈性欄，欄位一多就會被壓成一條（表頭變直書）
    h += '<div class="gridwrap"><table class="grid" id="tblItems" style="min-width:1060px;"><thead><tr>'
          + '<th style="width:36px;">#</th><th style="width:140px;">管理重點</th><th style="width:200px;">品質特性</th>'
          + '<th style="width:74px;">上限</th><th style="width:74px;">下限</th>'
          + '<th style="width:86px;">擔當者</th><th style="width:122px;">檢驗方法</th>'
          + '<th style="width:130px;">檢具編號</th><th style="width:104px;">檢驗頻率</th><th>備註</th>'
          + (CUR.can_edit ? '<th style="width:38px;"></th>' : '') + '</tr></thead><tbody data-eg-row-add="itemAdd" data-eg-row-del="itemDel">';
    var rows = CUR.items.length ? CUR.items : (CUR.can_edit ? [{}] : []);
    $.each(rows, function (i, r) { h += itemRow(i, r, ro); });
    h += '</tbody></table></div></div>';
    return h;
}
function itemRow(i, r, ro) {
    r = r || {};
    var dis = ro ? ' disabled' : '';
    return '<tr data-tt="' + num(r.tool_type_id) + '"><td class="c">' + (i + 1) + '</td>'
        + '<td><input class="i-ctrl" value="' + esc(r.ctrl_point || '') + '"' + ro + '></td>'
        + '<td><input class="i-q" value="' + esc(r.q_char || '') + '"' + ro + '></td>'
        + '<td><input class="i-up" value="' + esc(r.up_limit || '') + '"' + ro + '></td>'
        + '<td><input class="i-lo" value="' + esc(r.lo_limit || '') + '"' + ro + '></td>'
        + '<td>' + ownerSel(r.owner_dept_id, ro) + '</td>'
        + '<td>' + methodSel(r.method, r.tool_type_id, ro) + '</td>'
        + '<td><input class="i-tool" value="' + esc(r.tool_no || '') + '"' + ro + '>'
            + (ro ? '' : '<button class="btn btn-xs btn-warm-o i-pick" style="margin-top:2px;">挑檢具</button>') + '</td>'
        + '<td><input class="i-freq" value="' + esc(r.freq || '') + '"' + ro + '></td>'
        + '<td><input class="i-note" value="' + esc(r.note || '') + '"' + ro + '></td>'
        + (CUR.can_edit ? '<td class="c"><button class="btn btn-xs i-del"' + dis + '>×</button></td>' : '')
        + '</tr>';
}
function itemAdd($tbody) { $tbody.append(itemRow($tbody.children('tr').length, {}, '')); renumber($tbody); }
function itemDel($tr) {
    var $tb = $tr.closest('tbody');
    if ($tb.children('tr').length <= 1) { $tb.children('tr').first().replaceWith(itemRow(0, {}, '')); return; }
    $tr.remove(); renumber($tb);
}
$(document).on('click', '.i-del', function () { itemDel($(this).closest('tr')); });
/* 檢驗方法選到的如果本身就是一種量具類型，檢具編號的挑選就直接限定在那個類型底下 */
$(document).on('change', '.i-mth', function () {
    $(this).closest('tr').attr('data-tt', num($(this).find('option:selected').data('tt')));
});

/** 代入預設項目（接在現有內容後面，不覆蓋使用者已經填好的） */
$(document).on('click', '#btnApplyTpl', function () {
    api('default_items', { process_no: num(CUR.doc.process_no) }, function (res) {
        var rows = res.rows || [];
        if (!rows.length) {
            alert('這個製程還沒有設定預設的檢驗項目。\n\n要設定請到右上角「設定」→「檢驗項目預設值」。');
            return;
        }
        var $tb = $('#tblItems tbody');
        // 整列都還空白的末列先拿掉，免得代入後中間夾一列空的
        $tb.children('tr').each(function () {
            var any = false;
            $(this).find('input').each(function () { if ($(this).val().trim() !== '') any = true; });
            if (!any) $(this).remove();
        });
        $.each(rows, function (i, r) { $tb.append(itemRow($tb.children('tr').length, r, '')); });
        renumber($tb);
    });
});

/** SIP 的圖面與注意事項 */
function sipExtraHtml() {
    var v = CUR.ver;
    var draw = num(v.draw_file_id);
    var h = '<div class="sec"><h5>圖面'
          + '<span class="muted-help">從這個料號的料號附件挑一個帶入（只建立關聯，不複製檔案）</span></h5>';
    h += draw ? '<img class="thumb" style="max-width:340px;max-height:250px;" src="' + fileUrl(draw)
                + '&t=' + (new Date()).getTime() + '" data-file="' + draw + '">'
              : '<span class="muted-help">尚未帶入圖面</span>';
    if (CUR.can_edit) {
        h += '<div style="margin-top:6px;">';
        if (draw) {
            h += '<button class="btn btn-xs btn-warm-o f-rot" data-file="' + draw + '" data-deg="-90">↺ 左轉</button> '
               + '<button class="btn btn-xs btn-warm-o f-rot" data-file="' + draw + '" data-deg="90">↻ 右轉</button> ';
        }
        h += '<button class="btn btn-xs btn-warm-o" id="btnPickDraw">挑圖面</button>'
           + (draw ? ' <button class="btn btn-xs" id="btnClrDraw">移除圖面</button>' : '')
           + ' <button class="btn btn-xs btn-warm-o" id="btnUpDraw">上傳新圖</button>'
           + '<input type="file" id="fileDraw" accept="image/*,.pdf" style="display:none;">'
           + '<span class="muted-help">　旋轉只會影響這份文件的畫面與列印，不會動到料號附件那張原圖。</span></div>';
    }
    h += '</div>';
    h += secBox('f_notice', 'notice', '注意事項', v.notice, '一行一條；留空就印設定裡那份固定的注意事項');
    return h;
}

/** 簽章格：**簽的順序是製表→審核→核准，排出來由左到右是核准→審核→製表**（職位高的在左，比照紙本） */
function signHtml() {
    var h = '<div class="sec"><h5>簽核'
          + '<span class="muted-help">簽的順序是 製表 → 審核 → 核准（欄位由左到右是核准、審核、製表，與列印版相同）；'
          + '可簽的人限表單日期當時在職、且簽章當天沒請整天假</span></h5>'
          + '<div class="sign-row">';
    $.each(SS_SLOTS_D, function (k, def) {
        var s = CUR.signs[k];
        h += '<div class="sign-box"><div class="t"><b>' + esc(def.label) + '</b></div>';
        if (s && num(s.user_id)) {
            h += '<div class="who">' + esc(s.dept_name || '') + '　' + esc(s.position_name || '') + '<br>'
               + '<b>' + esc(s.user_name || '') + '</b>　' + dispDate(s.sign_date) + '</div>';
            /* 刻意沒有「清除這一格」：只清掉其中一格會讓這一版停在「簽核中、卻一個章都沒有」，
               而重蓋時是以登入者本人的身分蓋，超級管理員這種特殊帳號蓋不上去就整個卡死。
               要重來一律用頁尾的「取消送簽（退回草稿）」整版退回再重送（使用者 2026-09-22 指定）。 */
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
          + '<th>說明</th><th style="width:90px;">狀態</th><th style="width:80px;"></th></tr></thead><tbody>';
    $.each(CUR.vers || [], function (i, v) {
        var cur = num(v.ver_id) === num(CUR.ver.ver_id);
        h += '<tr' + (cur ? ' style="background:#FFF6E6;"' : '') + '>'
           + '<td class="c">' + esc(v.ver_no) + (cur ? '　<span class="muted-help">目前</span>' : '') + '</td>'
           + '<td class="c">' + dispDate(v.form_date) + '</td>'
           + '<td>' + esc(v.rev_text || v.rev_note || '') + '</td>'
           + '<td class="c"><span class="st st-' + esc(v.status) + '">' + esc(SS_STATUSES[v.status] || v.status) + '</span></td>'
           + '<td class="c">' + (cur ? '' : '<button class="btn btn-xs btn-warm-o v-open" data-ver="' + num(v.ver_id) + '">開啟</button>') + '</td>'
           + '</tr>';
    });
    h += '</tbody></table></div>';
    return h;
}

/** 附件（掃描檔與其他檔案） */
function filesHtml() {
    var list = (CUR.files || []).filter(function (f) { return f.usage_kind === 'scan' || f.usage_kind === 'other'; });
    var canAtt = num(CUR.can_attach) === 1;        // 核准之後只有管理員補得了附件
    if (!list.length && !canAtt) return '';
    var h = '<div class="sec"><h5>附件<span class="muted-help">紙本掃描檔、已簽核的紙本</span></h5>';
    if (list.length) {
        h += '<ul style="margin:0 0 6px 18px;font-size:12.5px;">';
        $.each(list, function (i, f) {
            h += '<li><a href="' + fileUrl(f.file_id) + '" target="_blank">' + esc(f.orig_name || f.file_name) + '</a>'
               + ' <a class="muted-help" href="' + fileUrl(f.file_id) + '&dl=1">下載</a>'
               + (canAtt ? ' <button class="btn btn-xs f-del" data-file="' + num(f.file_id) + '">刪除</button>' : '')
               + '</li>';
        });
        h += '</ul>';
    } else h += '<div class="muted-help">沒有附件</div>';
    if (canAtt) {
        h += '<button class="btn btn-xs btn-warm-o" id="btnUpScan">上傳附件</button>'
           + '<input type="file" id="fileScan" style="display:none;" '
           + 'accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,image/*">'
           + '<span class="muted-help">　可以放 PDF、Word、Excel、圖片…（不可執行檔）。</span>';
        if (!CUR.can_edit) h += '<div class="muted-help">這一版已經送簽或核准了，這裡只能補附件，內容不可修改。</div>';
    }
    h += '</div>';
    return h;
}

function renderDoc() {
    var d = CUR.doc, v = CUR.ver;
    var tab = (SS_KINDS[CUR.kind] ? SS_KINDS[CUR.kind].tab : 'sop') === 'sip' ? 'SIP' : 'SOP';
    $('#docTitle').text(tab + '　' + (SS_KINDS[CUR.kind] ? SS_KINDS[CUR.kind].label : '') + '　' + (d.title || ''));
    $('#docAsNo').text(CUR.as_no || '');
    $('#docStatus').attr('class', 'st st-' + v.status).text(SS_STATUSES[v.status] || v.status);

    var body = headHtml();
    if (CUR.kind === 'equip') body += equipHtml();
    else if (CUR.kind === 'process') body += stepsHtml();
    else body += sipExtraHtml() + itemsHtml();
    body += signHtml() + versHtml() + filesHtml();
    $('#docBody').html(body);
    // 打字挑的那兩欄：先把「目前畫面上的字」記成已挑過的值，否則使用者一動就被判成改過而解除綁定
    $('#fProc').data('picked', $('#fProc').val() || '');
    $('#fCus').data('picked', $('#fCus').val() || '');
    refreshBindTags();

    var foot = '<span class="muted-help">' + (CUR.can_edit ? '草稿可以直接改，改完記得存檔。'
             : (v.status === 'approved' ? '已核准的版次不可修改，要改請建立新版次。' : '目前沒有修改權限。')) + '</span><span class="sp"></span>';
    foot += '<button class="btn btn-sm btn-warm-o" id="btnPrint">列印</button>';
    if (CUR.can_edit) {
        foot += ' <button class="btn btn-sm btn-warm-o" id="btnSave">存檔</button>'
              + ' <button class="btn btn-sm btn-warm" id="btnSubmit">送出簽核</button>';
    }
    if (ssCanEditKind()) foot += ' <button class="btn btn-sm btn-warm-o" id="btnNewVer">建立新版次</button>';
    if (num(CUR.can_unsubmit)) {
        foot += ' <button class="btn btn-sm btn-warm-o" id="btnUnsubmit">取消送簽（退回草稿）</button>';
    }
    if (num(CUR.can_delete)) foot += ' <button class="btn btn-sm" id="btnDelDoc">刪除文件</button>';
    foot += ' <button class="btn btn-sm" data-close="maskDoc">關閉</button>';
    $('#docFoot').html(foot);
}
function ssCanEditKind() {
    var tab = SS_KINDS[CUR.kind] ? SS_KINDS[CUR.kind].tab : 'sop';
    return tab === 'sip' ? !!SS_PERMS.canEditSip : !!SS_PERMS.canEditSop;
}

$(document).on('click', '.v-open', function (e) { e.stopPropagation(); openDoc(num($(this).data('ver'))); });
/** 整版退回草稿（管理員限定）＝唯一的重來方式；沒有「只取消其中一格」那種做法 */
$(document).on('click', '#btnUnsubmit', function () {
    var n = 0;
    $.each(SS_SLOTS, function (k) { if (CUR.signs[k] && num(CUR.signs[k].user_id)) n++; });
    if (!confirm('確定把這一版退回「尚未送審」？\n\n'
        + '目前已經蓋好的 ' + n + ' 個簽章（含人工蓋的）會全部清掉，狀態回到草稿。\n'
        + '退回後可以重新編輯，再按「送出簽核」一次重送（管理員可勾「自動簽核」一次蓋滿三格）。')) return;
    post('unsubmit', { ver_id: num(CUR.ver.ver_id) }, function () {
        openDoc(num(CUR.ver.ver_id)); load(true);
    });
});

$(document).on('click', '#btnDelDoc', function () {
    if (!confirm('確定刪除「' + (CUR.doc.title || '') + '」這份文件？\n\n連同它的所有版次都會從清單上移除。')) return;
    post('doc_delete', { doc_id: num(CUR.doc.doc_id) }, function () { closeMask('maskDoc'); load(true); });
});

/**
 * 改綁定對象／適用範圍（管理員限定）。
 * 自動建立的文件可能綁錯，沒有這個就只能刪掉重建（使用者 2026-09-22 要求）。
 * 存檔一樣走 doc_save，所以重複判定與「綁定對象要存在」在後端仍然會再擋一次。
 */
$(document).on('click', '#btnReScope', function () {
    var d = CUR.doc;
    var allow = (window.SS_KIND_SCOPES && SS_KIND_SCOPES[CUR.kind])
             || ((CUR.kind === 'equip') ? ['machine', 'tool'] : ['general', 'part']);
    var h = '<div class="note-box">改了之後這份文件會重新判定「有沒有跟別份撞到」（同一個對象＋同一個製程只能有一份）。'
          + '版次、內容與簽核紀錄都不會動到。</div><div class="frm">'
          + '<label>適用範圍</label><div class="wide"><select id="rsScope">';
    $.each(allow, function (i, k) {
        h += '<option value="' + k + '"' + (k === d.scope ? ' selected' : '') + '>' + esc(SS_SCOPES[k]) + '</option>';
    });
    h += '</select></div>'
       + '<label id="rsLab">綁定對象</label><div class="wide ac-wrap">'
       + '<input type="text" id="rsBind"><input type="hidden" id="rsBindId">'
       + '<div class="muted-help" id="rsHint"></div></div>'
       + '<label>機器編號</label><div class="wide"><div id="rsMachines" class="pickbox muted-help">先選機台型號。</div></div>'
       + '</div><div class="err" id="rsErr" style="margin-top:6px;"></div>'
       + '<div style="margin-top:8px;"><button class="btn btn-sm btn-warm" id="rsSave">套用</button></div>';
    $('#pickTitle').text('改綁定對象／適用範圍');
    $('#pickBody').html(h);
    openMask('maskPick');
    rsSync();
});
function rsSync() {
    var k = $('#rsScope').val();
    $('#rsBind').val(''); $('#rsBindId').val('');
    $('#rsMachines').html('先選機台型號。').addClass('muted-help');
    $('#rsMachines').closest('.wide').prev('label').toggle(k === 'machine');
    $('#rsMachines').closest('.wide').toggle(k === 'machine');
    var lab = { machine: '機台型號', tool: '量具', part: '料號', general: '綁定對象' }[k] || '綁定對象';
    $('#rsLab').text(lab);
    $('#rsBind').closest('.ac-wrap').toggle(k !== 'general');
    $('#rsHint').text(k === 'machine' ? '打型號、機台名稱或機器編號，從清單挑。'
        : (k === 'tool' ? '打量具編號或種類，從清單挑。'
        : (k === 'part' ? '打料號從清單挑；同一個料號文字可能分屬好幾家客戶。' : '')));
}
$(document).on('change', '#rsScope', rsSync);
/* 一個輸入框要查三種主檔，所以 action 給成函式，依目前選的適用範圍決定要打哪一支 */
acAttach('#rsBind', {
    hidden: '#rsBindId',
    action: function () {
        var k = $('#rsScope').val();
        return k === 'machine' ? 'machine_models' : (k === 'tool' ? 'search_tool' : 'search_part');
    },
    row: function (r) { return rsRow(r); },
    pick: function (r) { rsPick(r); }
});
function rsRow(r) {
    var k = $('#rsScope').val();
    if (k === 'machine') return '<span class="hit">' + esc(r.machine_model) + '</span>　' + esc(r.machine || '')
        + '　<span class="muted-help">' + num(r.cnt) + ' 台：' + esc(r.asset_nos || '') + '</span>';
    if (k === 'tool') return '<span class="hit">' + esc(r.tool_no) + '</span>　' + esc(r.tool_type || '');
    return '<span class="hit">' + esc(r.D_Setting_Id) + '</span>　' + esc(r.customer || '')
        + '　<span class="muted-help">#' + num(r.d_id) + '</span>';
}
function rsPick(r) {
    var k = $('#rsScope').val();
    if (k === 'machine') {
        $('#rsBind').val(r.machine_model); $('#rsBindId').val(r.machine_model);
        api('machines_by_model', { model: r.machine_model }, function (res) {
            var h = '';
            $.each(res.rows || [], function (i, m) {
                h += '<label><input type="checkbox" class="rschk" value="' + num(m.machine_id) + '" checked> '
                   + '<span class="mno">' + esc(m.asset_no || '(未編號)') + '</span> ' + esc(m.field_no || '') + '</label>';
            });
            $('#rsMachines').removeClass('muted-help').html(h || '<span class="muted-help">這個型號沒有在用的機台。</span>');
        });
    } else if (k === 'tool') { $('#rsBind').val(r.tool_no); $('#rsBindId').val(r.tool_id); }
    else { $('#rsBind').val(r.D_Setting_Id); $('#rsBindId').val(r.d_id); }
}
$(document).on('click', '#rsSave', function () {
    var k = $('#rsScope').val(), id = $('#rsBindId').val();
    if (k !== 'general' && !id) { $('#rsErr').text('要從清單挑一個綁定對象（打字不選不算）。'); return; }
    var ids = [];
    $('.rschk:checked').each(function () { ids.push(num($(this).val())); });
    post('doc_save', {
        doc_id: num(CUR.doc.doc_id), kind: CUR.kind, scope: k,
        machine_model: k === 'machine' ? id : '',
        machine_ids: JSON.stringify(ids),
        tool_id: k === 'tool' ? num(id) : 0,
        part_d_id: k === 'part' ? num(id) : 0,
        title: CUR.doc.title, process_no: num(CUR.doc.process_no)
    }, function () { closeMask('maskPick'); openDoc(num(CUR.ver.ver_id)); load(true); });
});

/* 文件內的製程與客戶也可以改（改製程等於換了一份文件的定位，後端會再檢查會不會撞到既有文件） */
acAttach('#fProc', {
    action: 'search_process', hidden: '#fProcNo',
    row: function (r) {
        return '<span class="hit">' + esc(r.process_name) + '</span>　<span class="muted-help">編號 '
             + num(r.process_no) + '　' + esc(r.process_type || '') + '</span>';
    },
    pick: function (r) {
        $('#fProc').val(r.process_name).data('picked', r.process_name);
        $('#fProcNo').val(r.process_no); refreshBindTags();
    }
});
acAttach('#fCus', {
    action: 'search_customer', hidden: '#fCusId',
    row: function (r) {
        return '<span class="hit">' + esc(r.customer_id) + '</span>　' + esc(r.customer || '');
    },
    pick: function (r) {
        $('#fCus').val(r.customer).data('picked', r.customer);
        $('#fCusId').val(r.customer_id); refreshBindTags();
    }
});

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
                   owner_dept_id: num($t.find('.i-own').val()), method: $t.find('.i-mth').val() || '',
                   tool_type_id: num($t.attr('data-tt')),
                   tool_no: $t.find('.i-tool').val() || '', freq: $t.find('.i-freq').val() || '',
                   note: $t.find('.i-note').val() || '' });
    });
    return out;
}

function saveDoc(cb) {
    if (!CUR || !CUR.can_edit) return;
    var d = CUR.doc;
    if ($('#fProc').length && $('#fProc').val().trim() && !num($('#fProcNo').val())) {
        alert('製程打了字卻沒有從清單挑，請重新挑一次。'); return;
    }
    // 客戶同理：後端只認客戶編號，只打字不挑會被安靜地丟掉（存完客戶欄變空的還不報錯）
    if ($('#fCus').length && $('#fCus').val().trim() && !($('#fCusId').val() || '').trim()) {
        alert('客戶打了字卻沒有從清單挑，請重新挑一次（只打字不挑，存檔不會存到客戶）。'); return;
    }
    var ids = [];
    $.each(CUR.machines || [], function (i, m) { ids.push(num(m.machine_id)); });
    // 主檔（名稱／製程／客戶／機台）與版次分兩支存：主檔是整份文件共用的，版次才是這一版的內容
    post('doc_save', {
        doc_id: num(d.doc_id), kind: CUR.kind, scope: d.scope,
        machine_model: d.machine_model || '', machine_ids: JSON.stringify(ids),
        part_d_id: num(d.part_d_id),
        process_no: $('#fProcNo').length ? num($('#fProcNo').val()) : num(d.process_no),
        customer_id: $('#fCusId').length ? ($('#fCusId').val() || '') : (d.customer_id || ''),
        title: $('#fTitle').val()
    }, function () {
        var p = { ver_id: num(CUR.ver.ver_id), ver_no: $('#fVer').val(), form_date: $('#fDate').val(),
                  rev_note: $('#fRev').val(),
                  paper: $('#fPaper').val() || '', orient: $('#fOrient').val() || '' };
        if (CUR.kind === 'equip') {
            $.each(['m_maker', 'm_name', 'm_spec', 'm_range', 'op_method', 'cautions', 'maintain'], function (i, k) {
                p[k] = $('#f_' + k).val() || '';
            });
        } else if (CUR.kind === 'process') {
            p.use_equip = $('#f_use_equip').val() || '';
            p.est_hours = $('#f_est_hours').val() || '';
            p.steps = JSON.stringify(collectSteps());
        } else {
            p.notice = $('#f_notice').val() || '';
            p.items = JSON.stringify(collectItems());
        }
        post('ver_save', p, function () {
            if (cb) cb(); else { openDoc(num(CUR.ver.ver_id)); load(true); }
        });
    });
}
$(document).on('click', '#btnSave', function () { saveDoc(); });

/* ══════════════════════ 機台：調整機器編號／重新帶入／使用設備 ══════════════════════ */

/** 同型號的機台一次列出來勾（使用者拍板：自動帶全部、可勾掉） */
$(document).on('click', '#btnEditMachines', function () {
    api('machines_by_model', { model: CUR.doc.machine_model || '' }, function (res) {
        var have = {};
        $.each(CUR.machines || [], function (i, m) { have[num(m.machine_id)] = 1; });
        var h = '<div class="note-box">這份 SOP 綁的是<b>型號 ' + esc(CUR.doc.machine_model || '') + '</b>，'
              + '下面勾的是它涵蓋哪幾台機器。同型號新買的機台不會自動加進來，要自己勾。</div><div class="pickbox">';
        $.each(res.rows || [], function (i, m) {
            h += '<label><input type="checkbox" class="emchk" value="' + num(m.machine_id) + '"'
               + (have[num(m.machine_id)] ? ' checked' : '') + '> <span class="mno">'
               + esc(m.asset_no || '(未編號)') + '</span> ' + esc(m.field_no || '') + '</label>';
        });
        h += '</div><div style="margin-top:8px;"><button class="btn btn-sm btn-warm" id="emSave">套用</button></div>';
        $('#pickTitle').text('調整機器編號');
        $('#pickBody').html(h);
        openMask('maskPick');
    });
});
$(document).on('click', '#emSave', function () {
    var ids = [];
    $('.emchk:checked').each(function () { ids.push(num($(this).val())); });
    if (!ids.length) { alert('至少要勾一台。'); return; }
    post('doc_save', {
        doc_id: num(CUR.doc.doc_id), kind: CUR.kind, scope: CUR.doc.scope,
        machine_model: CUR.doc.machine_model || '', machine_ids: JSON.stringify(ids),
        title: CUR.doc.title, process_no: num(CUR.doc.process_no)
    }, function () { closeMask('maskPick'); openDoc(num(CUR.ver.ver_id)); load(true); });
});

/** 機台四欄重新照主檔帶一次 */
$(document).on('click', '#btnRefillMachine', function () {
    var mm = CUR.machine_meta || {};
    $('#f_m_maker').val(mm.m_maker || '');
    $('#f_m_name').val(mm.m_name || '');
    $('#f_m_spec').val(mm.m_spec || '');
    $('#f_m_range').val(mm.m_range || '');
    alert('已照機台主檔重新帶入，確認沒問題請按「存檔」。');
});

/** 製造製程說明書的「使用設備」：可複選機器編號（使用者要求） */
$(document).on('click', '#btnPickEquip', function () {
    var h = '<div class="note-box">勾選這個製程會用到的<b>機台或量具</b>，按「帶入」會把編號填進「使用設備」欄，'
          + '之後仍然可以自己改文字。機台依<b>綁定的製程</b>分組、量具依<b>種類</b>分組。</div>'
          + '<div class="frm" style="margin-bottom:6px;"><label>搜尋</label><div class="wide">'
          + '<input type="text" id="eqKw" data-eg-hint="打製程、型號、機台名稱、機器編號或量具編號"></div></div>'
          + '<div class="pickbox eqbox" id="eqList" style="max-height:330px;">載入中…</div>'
          + '<div style="margin-top:8px;"><span class="muted-help" id="eqSel">已勾 0 項</span>'
          + '　<button class="btn btn-sm btn-warm" id="eqApply">帶入</button></div>';
    $('#pickTitle').text('挑使用設備');
    $('#pickBody').html(h);
    openMask('maskPick');
    loadEqList('');
});
/** 依「製程／量具種類」分組列出，一組一個標題一列一台——平鋪 34 台看不出哪台是哪一關的 */
function loadEqList(kw) {
    api('equip_pick', { kw: kw }, function (res) {
        var gs = res.groups || [], h = '', n = 0;
        $.each(gs, function (i, g) {
            if (!g.rows || !g.rows.length) return;
            n += g.rows.length;
            h += '<div class="eqgrp"><div class="eqgh">'
               + (g.kind === 'tool' ? '量具／檢驗設備　' : '製程　') + esc(g.group)
               + '<span class="muted-help">　' + g.rows.length + ' 項</span></div>';
            $.each(g.rows, function (j, r) {
                h += '<label class="eqit"><input type="checkbox" class="eqchk" value="' + esc(r.value) + '"> '
                   + '<span class="mno">' + esc(r.no) + '</span> ' + esc(r.name || '')
                   + (r.sub ? ' <span class="muted-help">' + esc(r.sub) + '</span>' : '')
                   + '</label>';
            });
            h += '</div>';
        });
        $('#eqList').html(h || '<span class="muted-help">查無符合的機台或量具。</span>');
        eqCount();
    });
}
function eqCount() { $('#eqSel').text('已勾 ' + $('.eqchk:checked').length + ' 項'); }
$(document).on('change', '.eqchk', eqCount);
$(document).on('input', '#eqKw', function () {
    var kw = $(this).val();
    clearTimeout(window._eqT);
    window._eqT = setTimeout(function () { loadEqList(kw); }, 200);
});
$(document).on('click', '#eqApply', function () {
    var v = [];
    $('.eqchk:checked').each(function () { v.push($(this).val()); });
    if (!v.length) { alert('至少要勾一台。'); return; }
    $('#f_use_equip').val(v.join('、'));
    closeMask('maskPick');
});

/* ══════════════════════ 檢具編號：先選類型再選編號 ══════════════════════ */

var TOOL_FOR = null;
$(document).on('click', '.i-pick', function () {
    TOOL_FOR = $(this).closest('tr');
    var tt = num(TOOL_FOR.attr('data-tt'));
    $('#pickTitle').text('挑檢具編號');
    openMask('maskPick');
    if (tt) toolNums(tt); else toolTypes();
});
function toolTypes() {
    var h = '<div class="note-box">先選<b>量具類型</b>，再選編號。'
          + '（檢驗方法那一欄如果選的本來就是一種量具，這裡會直接跳到該類型的編號）</div><div class="tpick">';
    $.each(CUR.tool_types || [], function (i, t) {
        h += '<button class="btn btn-sm btn-warm-o tt-go" data-id="' + num(t.id) + '">' + esc(t.name) + '</button>';
    });
    h += '</div>';
    $('#pickBody').html(h);
}
$(document).on('click', '.tt-go', function () { toolNums(num($(this).data('id'))); });
function toolNums(typeId) {
    api('tools_by_type', { type_id: typeId }, function (res) {
        var name = '';
        $.each(CUR.tool_types || [], function (i, t) { if (num(t.id) === typeId) name = t.name; });
        var h = '<div class="note-box"><b>' + esc(name) + '</b>　'
              + '<button class="btn btn-xs btn-warm-o" id="ttBack">← 換一個類型</button></div><div class="tpick">';
        $.each(res.rows || [], function (i, t) {
            h += '<button class="btn btn-sm btn-warm-o tn-go" data-no="' + esc(t.tool_no) + '" data-tt="' + typeId + '">'
               + esc(t.tool_no) + (t.spec_desc ? ' <span class="muted-help">' + esc(t.spec_desc) + '</span>' : '')
               + '</button>';
        });
        h += '</div>';
        if (!(res.rows || []).length) {
            h += '<div class="muted-help" style="margin-top:6px;">這個類型底下沒有在用的編號。'
               + '可以直接在欄位裡打字（例如 N/A）。</div>';
        }
        $('#pickBody').html(h);
    });
}
$(document).on('click', '#ttBack', toolTypes);
$(document).on('click', '.tn-go', function () {
    if (TOOL_FOR) {
        TOOL_FOR.find('.i-tool').val($(this).data('no'));
        TOOL_FOR.attr('data-tt', num($(this).data('tt')));
    }
    closeMask('maskPick');
});

/* ══════════════════════ 圖面／附件 ══════════════════════ */

var PICK_FOR = null;        // 'draw' 或 步驟的 <tr>

function openDrawPick(forWhat) {
    PICK_FOR = forWhat;
    var list = CUR.draw_candidates || [];
    var h = '<div class="note-box">清單就是<b>這個料號的料號附件</b>。挑一個帶入，只建立關聯不會複製檔案；'
          + '料號附件那邊換了圖，這裡也會跟著是新的。帶入之後可以在這一頁轉方向，<b>不會動到原圖</b>。</div>';
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
    $('#pickTitle').text('挑選圖面');
    $('#pickBody').html(h);
    openMask('maskPick');
}
$(document).on('click', '.dp-item', function () {
    var id = num($(this).data('id'));
    post('file_link_part', { doc_id: num(CUR.doc.doc_id), ver_id: num(CUR.ver.ver_id),
                             part_attach_id: id, usage: PICK_FOR === 'draw' ? 'draw' : 'step' },
    function (res) {
        closeMask('maskPick');
        if (PICK_FOR === 'draw') {
            post('ver_save', { ver_id: num(CUR.ver.ver_id), draw_file_id: num(res.file_id) },
                 function () { openDoc(num(CUR.ver.ver_id)); });
        } else if (PICK_FOR && PICK_FOR.length) {
            PICK_FOR.data('img', num(res.file_id));
            PICK_FOR.find('td').eq(2).html(stepImgCell(res.file_id));
        }
    });
});
$(document).on('click', '#btnPickDraw', function () { openDrawPick('draw'); });
$(document).on('click', '.s-pick', function () { openDrawPick($(this).closest('tr')); });
$(document).on('click', '.s-clr', function () {
    var $tr = $(this).closest('tr');
    $tr.data('img', 0);
    $tr.find('td').eq(2).html(stepImgCell(0));
});
$(document).on('click', '#btnClrDraw', function () {
    post('ver_save', { ver_id: num(CUR.ver.ver_id), draw_file_id: 0 }, function () { openDoc(num(CUR.ver.ver_id)); });
});

/**
 * 旋轉：只轉這份文件，不動原檔（使用者拍板）。
 * 轉完只換掉那一張 img 的網址（加時間戳避開瀏覽器快取），不整頁重載。
 */
$(document).on('click', '.f-rot', function (e) {
    e.stopPropagation();
    var fid = num($(this).data('file')), deg = num($(this).data('deg'));
    post('file_rotate', { file_id: fid, deg: deg }, function () {
        $('img[src*="id=' + fid + '"]').each(function () {
            $(this).attr('src', fileUrl(fid) + '&t=' + (new Date()).getTime());
        });
    });
});

/* 上傳：依記憶 file_upload_change_event 三鐵則——送出時直讀 input.files，不倚賴 change 事件被觸發 */
function doUpload(inputEl, usage, secKey, after) {
    var el = inputEl;
    if (!el || !el.files || !el.files.length) return;
    var fd = new FormData();
    fd.append('action', 'file_upload'); fd.append('csrf', SS_CSRF);
    fd.append('doc_id', num(CUR.doc.doc_id)); fd.append('ver_id', num(CUR.ver.ver_id));
    fd.append('usage', usage);
    if (secKey) fd.append('sec_key', secKey);
    fd.append('file', el.files[0]);
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
    doUpload(this, 'draw', '', function (res) {
        post('ver_save', { ver_id: num(CUR.ver.ver_id), draw_file_id: num(res.file_id) },
             function () { openDoc(num(CUR.ver.ver_id)); });
    });
});
$(document).on('click', '#btnUpScan', function () { $('#fileScan').trigger('click'); });
$(document).on('change', '#fileScan', function () { doUpload(this, 'scan', ''); });
$(document).on('click', '.f-del', function () {
    if (!confirm('確定刪除這個附件？')) return;
    post('file_delete', { file_id: num($(this).data('file')) }, function () { openDoc(num(CUR.ver.ver_id)); });
});

/* 段落附件：上傳後**只重畫那一段**的縮圖列（使用者要求 AJAX 更新，不要整頁跳掉） */
$(document).on('click', '.sec-up', function () {
    var key = $(this).data('sec');
    var $f = $('<input type="file" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv" '
             + 'style="display:none;">').appendTo('body');
    $f.on('change', function () {
        var el = this;
        doUpload(el, 'sec', key, function () {
            api('detail', { ver_id: num(CUR.ver.ver_id) }, function (res) {
                CUR = res;
                $('.secwrap[data-sec="' + key + '"]').html(secFilesHtml(key));
                $f.remove();
            });
        });
    });
    $f.trigger('click');
});
$(document).on('click', '.sf-del', function () {
    if (!confirm('確定刪除這張圖？')) return;
    var key = $(this).closest('.secwrap').data('sec');
    post('file_delete', { file_id: num($(this).data('file')) }, function () {
        api('detail', { ver_id: num(CUR.ver.ver_id) }, function (res) {
            CUR = res;
            $('.secwrap[data-sec="' + key + '"]').html(secFilesHtml(key));
        });
    });
});

/* ══════════════════════ 送簽與簽核 ══════════════════════ */

/* 送簽視窗的人員名單與自動簽核的解析結果（一律跟著「簽章日期」重抓，見 ai-rules/22） */
var SB = { people: [], resolve: {}, auto: 0 };

/** 重畫三個人員下拉：勾了自動簽核就把解析到的人先填好，管理員仍可改成別人 */
function sbRenderPeople(keepPick) {
    if (!SS_PERMS.canAdmin) return;
    var auto = $('#sbAuto').is(':checked');
    $.each([['sbMaker', 'maker'], ['sbReview', 'review'], ['sbApprove', 'approve']], function (i, x) {
        var id = x[0], slot = x[1], $s = $('#' + id);
        var cur = keepPick ? num($s.val()) : 0;
        // 這個人在新的日期還在不在名單裡？不在就不要留著（那一天他還沒到職或已離職）
        var ok = false;
        $.each(SB.people, function (j, p) { if (num(p.id) === cur && !num(p.blocked)) ok = true; });
        var want = ok ? cur : 0;
        if (!want) {
            if (slot === 'maker') want = num(SS_PERMS.uid);      // 送出的人就是製表
            else if (auto) want = num((SB.resolve[slot] || {}).id);
        }
        // 解析到的人剛好那天請整天假就不要硬填（畫面上他是不可選的）
        var fine = false;
        $.each(SB.people, function (j, p) { if (num(p.id) === want && !num(p.blocked)) fine = true; });
        // 只換選項、不換 select 本身——換掉的話打字篩選框會留在原地指著一個已經不存在的下拉
        $s.html(peopleOpts(SB.people, fine ? want : 0)).val(fine && want ? String(want) : '');
        if (slot !== 'maker') {
            // 說明一律講「設定解析到誰、能不能用」，跟現在下拉選了誰分開講，不然管理員改過人之後會看不懂
            var r = SB.resolve[slot] || {}, inList = false, blocked = false;
            $.each(SB.people, function (j, p) {
                if (num(p.id) !== num(r.id)) return;
                inList = true; if (num(p.blocked)) blocked = true;
            });
            var msg;
            if (!auto) {
                msg = '沒有勾自動簽核就留白，之後由本人一格一格蓋。';
            } else if (!num(r.id)) {
                msg = '<span style="color:#9C3312;">' + esc(r.why || '設定裡沒有解析到人') + '，請自己挑一位。</span>';
            } else if (!inList) {
                // 設定解析到的人在這個日期還沒到職（或已離職）＝不可以把他的章蓋在這一版上（ai-rules/22）
                msg = '<span style="color:#9C3312;">設定解析到 <b>' + esc(r.name || ('#' + num(r.id)))
                    + '</b>，但他在這個簽章日期<b>還不在職（或已離職）</b>，不能蓋在這一版上，請自己挑一位。</span>';
            } else if (blocked) {
                msg = '<span style="color:#9C3312;">設定解析到 <b>' + esc(r.name || '')
                    + '</b>，但他那天請整天假不可簽，請自己挑一位。</span>';
            } else {
                msg = '依設定解析到：<b>' + esc(r.name || '') + '</b>（' + esc(r.why || '') + '）　可以直接改成別人。';
            }
            $('#sbWhy_' + slot).html(msg);
        }
    });
}

/** 向後端要「這個日期當時」的人員名單與解析結果，回來再重畫 */
function sbLoad(signDate, keepPick, cb) {
    api('signer_candidates', { form_date: $('#fDate').val() || CUR.ver.form_date,
                               sign_date: signDate || '', kind: CUR.kind }, function (res) {
        SB.people  = res.rows || [];
        SB.resolve = res.resolve || {};
        SB.auto    = num(res.auto_on);
        sbRenderPeople(keepPick);
        if (cb) cb();
    });
}

$(document).on('click', '#btnSubmit', function () {
    saveDoc(function () {
        var d0 = $('#fDate').val() || CUR.ver.form_date || SS_TODAY;
        api('signer_candidates', { form_date: d0, sign_date: d0, kind: CUR.kind }, function (res) {
            SB.people = res.rows || []; SB.resolve = res.resolve || {}; SB.auto = num(res.auto_on);
            var h = '<div class="note-box">送出後「製表」那一格立刻成立，業務日期＝表單日期。'
                  + '可以簽的人限<b>表單日期當時在職</b>（當時在職、現在已離職的人也挑得到），'
                  + '且<b>簽章當天沒有請整天假</b>——請假的人仍然列出來，但會標明假別並不可選。'
                  + '<b>改簽章日期會整份重抓</b>，因為那一天的部門職稱與請假都不一樣。</div><div class="frm">';
            h += '<label>簽章日期</label><div class="wide"><input type="date" id="sbDate" value="'
               + esc(d0) + '"' + (SS_PERMS.canAdmin ? '' : ' readonly') + '></div>';
            if (SS_PERMS.canAdmin) {
                h += '<label>自動簽核</label><div class="wide"><label style="font-weight:normal;text-align:left;">'
                   + '<input type="checkbox" id="sbAuto"' + (SB.auto ? ' checked' : '')
                   + '> 送出當下把審核與核准一起蓋好（時間會錯開且不跨日）</label>'
                   + '<div class="muted-help">預設跟著「設定」裡這個版面的自動簽核開關；'
                   + '勾起來會自動帶出依設定解析到的人，<b>要換人直接改下面的下拉就好</b>。</div></div>'
                   + '<label>製表（填表人）</label><div class="wide">' + peopleSel('sbMaker', SB.people, 0) + '</div>'
                   + '<label>審核</label><div class="wide">' + peopleSel('sbReview', SB.people, 0)
                   + '<div class="muted-help" id="sbWhy_review"></div></div>'
                   + '<label>核准</label><div class="wide">' + peopleSel('sbApprove', SB.people, 0)
                   + '<div class="muted-help" id="sbWhy_approve"></div></div>';
            } else {
                h += '<label>製表</label><div class="wide"><input value="' + esc(SS_PERMS.name) + '" readonly></div>';
            }
            h += '</div>';
            $('#submitBody').html(h);
            $('#maskSubmit .m-head').contents().first().replaceWith('送出簽核');
            $('#sbGo').show();
            openMask('maskSubmit');
            sbRenderPeople(false);
        });
    });
});
/* 改日期＝那一天的在職者、職稱與請假全都不一樣，一定要整份重抓（ai-rules/22） */
$(document).on('change', '#sbDate', function () { sbLoad($(this).val(), true); });
/* 勾／取消自動簽核：勾起來就把解析到的人填進去，取消不動已經挑好的人 */
$(document).on('change', '#sbAuto', function () { sbRenderPeople(true); });
/** 人員下拉的選項（請假者仍然列出來，只是標明假別並不可選——不是安靜地消失） */
function peopleOpts(people, sel) {
    var h = '<option value="">（不指定）</option>';
    $.each(people || [], function (i, p) {
        h += '<option value="' + num(p.id) + '"' + (num(p.id) === num(sel) ? ' selected' : '')
           + (num(p.blocked) ? ' disabled' : '') + '>'
           + esc(p.dept) + '　' + esc(p.position) + '　' + esc(p.name)
           + (num(p.blocked) ? '（' + esc(p.block_why) + '，不可簽）' : '')
           + (num(p.is_former) ? '（當時在職・現已離職）' : '') + '</option>';
    });
    return h;
}
function peopleSel(id, people, sel) {
    return '<select id="' + id + '" data-eg-filter="輸入姓名或部門篩選…">' + peopleOpts(people, sel) + '</select>';
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
        load(true);
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
$(document).on('click', '#btnNewVer', function () {
    var v = prompt('新版次的版次號（留空＝自動遞增）', '');
    if (v === null) return;
    post('ver_new', { from_ver_id: num(CUR.ver.ver_id), ver_no: v || '', form_date: SS_TODAY, rev_note: '' },
         function (res) { openDoc(num(res.ver_id)); load(true); });
});

/* ══════════════════════ 列印 ══════════════════════ */

function doPrint(verId) {
    // 一定要由使用者點擊直接開視窗，不然會被彈出視窗封鎖擋掉而「按了完全沒反應」
    window.open('sop_sip_print.php?ver_id=' + num(verId), '_blank');
}
$(document).on('click', '#btnPrint', function () { doPrint(num(CUR.ver.ver_id)); });

/* ══════════════════════ 設定 ══════════════════════ */

var SET = null;

$('#btnSetting').on('click', function () {
    api('settings_get', {}, function (res) {
        SET = res;
        var h = '<div class="ss-tabs" style="margin-bottom:8px;">'
              + '<div class="ss-tab on" data-set="base">簽核與圖章</div>'
              + '<div class="ss-tab" data-set="owner">擔當者與檢驗方法</div>'
              + '<div class="ss-tab" data-set="tpl">檢驗項目預設值</div></div>'
              + '<div id="setPane"></div>';
        $('#setBody').html(h);
        setPane('base');
        openMask('maskSet');
    });
});
$(document).on('click', '.ss-tab[data-set]', function () {
    $('.ss-tab[data-set]').removeClass('on'); $(this).addClass('on');
    setPane($(this).data('set'));
});

function setPane(which) {
    if (which === 'base') return setPaneBase();
    if (which === 'owner') return setPaneOwner();
    return setPaneTpl(0);
}

/** 下拉的共用產生器（key=>label 的物件） */
function selOpts(id, map, sel, blank) {
    var h = '<select id="' + id + '" data-eg-skip>';
    if (blank) h += '<option value="0">' + esc(blank) + '</option>';
    $.each(map, function (k, lab) {
        h += '<option value="' + esc(k) + '"' + (String(k) === String(sel) ? ' selected' : '') + '>' + esc(lab) + '</option>';
    });
    return h + '</select>';
}

/**
 * 依序輸出選項的下拉（**不要用 selOpts 的物件**）。
 * 物件的鍵是數字時，JS 會依數字大小跑 $.each，後端排好的順序會被整個打亂——
 * 部門下拉原本就是這樣變成「依 department.id 排序」的（技術課 1、品管課 2…），
 * 而且完全不報錯，光看程式碼也看不出來（使用者 2026-09-22 回報）。
 * @param rows [{v, label, indent}]
 */
function selList(id, rows, sel, blank, cls) {
    var h = '<select id="' + id + '"' + (cls ? ' class="' + cls + '"' : '') + '>';
    if (blank !== undefined) h += '<option value="0">' + esc(blank) + '</option>';
    $.each(rows || [], function (i, r) {
        h += '<option value="' + esc(r.v) + '"' + (String(r.v) === String(sel) ? ' selected' : '') + '>'
           + (r.indent ? new Array(r.indent + 1).join('　') : '') + esc(r.label) + '</option>';
    });
    return h + '</select>';
}
/** 這個部門底下實際登記的職稱；沒登記過的部門就退回全部職稱（不然會一個都挑不到） */
function posRowsOfDept(deptId) {
    var res = SET, all = [];
    $.each(res.positions || [], function (i, p) { all.push({ v: num(p.id), label: p.name }); });
    var ids = (res.dept_positions || {})[String(num(deptId))] || (res.dept_positions || {})[num(deptId)];
    if (!deptId || !ids || !ids.length) return all;
    var set = {};
    $.each(ids, function (i, x) { set[num(x)] = 1; });
    var hit = [];
    $.each(all, function (i, p) { if (set[num(p.v)]) hit.push(p); });
    return hit.length ? hit : all;
}

function setPaneBase() {
    var res = SET;
    // 部門依組織樹順序（含董事長室／總經理室），職稱依 sort_order；兩個都要保序故用陣列
    var deptRows = [];
    $.each(res.departments || [], function (i, d) {
        deptRows.push({ v: num(d.id), label: d.name, indent: num(d.depth) });
    });
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
        // 列印紙張（逐版面的預設；綁機台／量具的一律 A4 直式，不吃這裡的設定）
        var pp = cfg.paper || {};
        h += '<label>列印紙張</label><div class="wide">'
           + selOpts('stPaper_' + k, res.papers || {}, pp.size || 'A3')
           + ' ' + selOpts('stOrient_' + k, res.orients || {}, pp.orient || 'landscape')
           + '<span class="muted-help">　綁機台或量具的一律 A4 直式，綁料號的預設 A3 橫式。</span></div>';
        // 簽核人改設「部門＋職稱」，不設人（補歷史單據時那個人可能還沒到職、之後也可能離職）
        $.each(SS_SLOTS, function (slot, sd) {
            if (slot === 'maker') return;
            var sc = (cfg.signer_cfg || {})[slot] || {};
            h += '<label>' + esc(sd.label) + '</label><div class="wide sgcfg" data-kind="' + k + '" data-slot="' + slot + '">'
               + selList('sgD_' + k + '_' + slot, deptRows, num(sc.dept_id), '（不限部門）', 'sg-dept')
               + ' ' + selList('sgP_' + k + '_' + slot, posRowsOfDept(num(sc.dept_id)), num(sc.position_id), '（不限職稱）')
               + '<br><span class="muted-help">代理：</span> '
               + selList('sgDD_' + k + '_' + slot, deptRows, num(sc.dep_dept_id), '（不設代理）', 'sg-dept')
               + ' ' + selList('sgPP_' + k + '_' + slot, posRowsOfDept(num(sc.dep_dept_id)), num(sc.dep_position_id), '（不限職稱）')
               + '<div class="muted-help">今天會解析到：<b>' + esc(sc.preview_name || '（找不到人）') + '</b>'
               + '（' + esc(sc.preview_why || '') + '）'
               + (num(sc.legacy_user_id) ? '　※ 這一關還是舊的「指定人員」設定，改成部門＋職稱後才會依日期解析' : '')
               + '</div></div>';
        });
        h += '</div></div>';
    });
    $('#setPane').html(h);
}

/* 換部門就把旁邊的「職稱」收斂成那個部門真的有的職稱（使用者 2026-09-22 要求）。
   原本選的職稱在新部門也有就留著，沒有才退回「不限職稱」——留一個那個部門沒有的職稱
   等於設了一條永遠解析不到人的規則，而且畫面上看起來完全正常。 */
$(document).on('change', '#setPane .sg-dept', function () {
    var $d = $(this), id = $d.attr('id');
    var pid = id.indexOf('sgDD_') === 0 ? id.replace('sgDD_', 'sgPP_') : id.replace('sgD_', 'sgP_');
    var $p = $('#' + pid);
    if (!$p.length) return;
    var keep = num($p.val());
    var rows = posRowsOfDept(num($d.val())), hit = false;
    var h = '<option value="0">（不限職稱）</option>';
    $.each(rows, function (i, r) {
        if (num(r.v) === keep) hit = true;
        h += '<option value="' + num(r.v) + '"' + (num(r.v) === keep ? ' selected' : '') + '>' + esc(r.label) + '</option>';
    });
    $p.html(h).val(hit ? String(keep) : '0');
});

/** 擔當者部門的顯示文字＋檢驗方法的可選項目 */
function setPaneOwner() {
    var res = SET;
    var cur = {};
    $.each(res.owner_depts || [], function (i, d) { cur[num(d.dept_id)] = d; });
    var h = '<div class="note-box">'
          + '<b>擔當者</b>只能挑部門，但現場講的是「品管／生產／<b>包裝</b>」——組織上沒有包裝這個部門，'
          + '所以這裡可以逐個部門指定「前端要顯示成什麼字」，勾起來的才會出現在檢驗項目的擔當者下拉裡。'
          + '部門名稱一律即時查，部門改名這裡會跟著改。</div>';
    h += '<table class="grid" style="margin-bottom:12px;"><thead><tr>'
       + '<th style="width:60px;">啟用</th><th style="width:180px;">部門</th><th>顯示文字（留空＝用部門名稱）</th>'
       + '</tr></thead><tbody>';
    $.each(res.departments || [], function (i, d) {
        if (num(d.level) < 3) return;
        var c = cur[num(d.id)] || {};
        var on = (res.owner_depts && res.owner_depts.length) ? num(c.on) : 1;
        h += '<tr><td class="c"><input type="checkbox" class="od-on" data-id="' + num(d.id) + '"' + (on ? ' checked' : '') + '></td>'
           + '<td>' + esc(d.name) + '</td>'
           + '<td><input class="od-lab" data-id="' + num(d.id) + '" value="'
           + esc((c.label && c.label !== d.name) ? c.label : '') + '" placeholder=""></td></tr>';
    });
    h += '</tbody></table>';

    var sel = {};
    $.each((res.methods || {}).tool_type_ids || [], function (i, id) { sel[num(id)] = 1; });
    h += '<div class="note-box"><b>檢驗方法</b>的下拉要出現哪些選項：勾量具類型（清單來自量具主檔，'
       + '類型改名這裡會跟著改），再加上自己打的項目（例如「依包裝指導書要求」）。'
       + '選到的方法本身是一種量具時，檢具編號可以直接從那個類型底下挑。</div>';
    h += '<div class="pickbox" style="max-height:180px;">';
    $.each(res.tool_types || [], function (i, t) {
        h += '<label><input type="checkbox" class="mt-chk" value="' + num(t.id) + '"' + (sel[num(t.id)] ? ' checked' : '') + '> '
           + esc(t.name) + '</label>';
    });
    h += '</div>';
    h += '<div class="frm" style="margin-top:8px;"><label>自建項目</label><div class="wide">'
       + '<textarea id="mtExtra" style="min-height:70px;">' + esc(((res.methods || {}).extra || []).join('\n')) + '</textarea>'
       + '<div class="muted-help">一行一個。</div></div></div>';
    // 標準檢驗指導書左下角那塊固定的「注意事項」——每一份都一樣，所以設定一次就好
    h += '<div class="note-box" style="margin-top:10px;">'
       + '<b>標準檢驗指導書的「注意事項」</b>印在左下角圖面下方，每一份都一樣，所以在這裡設一次就好。'
       + '個別文件如果自己填了注意事項，就以那一份自己填的為準。</div>'
       + '<div class="frm"><label>注意事項</label><div class="wide">'
       + '<textarea id="sipNotice" style="min-height:96px;">' + esc(res.sip_notice_default || '') + '</textarea>'
       + '<div class="muted-help">一行一條，列印時自動編號。留空會用系統內建的那五條。</div></div></div>';
    $('#setPane').html(h);
}

/** 檢驗項目預設值：標準項目（全站）＋ 逐製程的專屬項目 */
function setPaneTpl(pno) {
    api('tpl_get', { tpl_kind: pno ? 'proc' : 'std', process_no: pno }, function (res) {
        var h = '<div class="note-box">'
              + '<b>標準項目</b>是每一份檢驗指導書都會有的那幾列（精度等級、外觀、包裝…）；'
              + '<b>製程專屬項目</b>是某個製程才有的（例如齒研的跨齒厚）。'
              + '建立文件時會先帶專屬項目、再帶標準項目，<b>代入之後仍然可以逐列刪掉不要的</b>。</div>';
        h += '<div class="frm" style="margin-bottom:8px;">'
           + '<label>要編哪一組</label><div class="wide">'
           + '<button class="btn btn-xs ' + (pno ? 'btn-warm-o' : 'btn-warm') + ' tpl-std">標準項目（全站共用）</button>　'
           + '<span class="muted-help">製程專屬：</span>'
           + '<input type="text" id="tplProc" style="width:180px;display:inline-block;" data-eg-hint="打製程名稱或編號">'
           + '<input type="hidden" id="tplProcNo" value="' + num(pno) + '">'
           + '</div></div>';
        if ((res.processes || []).length) {
            h += '<div class="muted-help" style="margin-bottom:6px;">已經設過專屬項目的製程：';
            $.each(res.processes, function (i, p) {
                h += '<button class="btn btn-xs ' + (num(p.process_no) === num(pno) ? 'btn-warm' : 'btn-warm-o')
                   + ' tpl-go" data-no="' + num(p.process_no) + '">' + esc(p.process_name || p.process_no)
                   + '（' + num(p.cnt) + '）</button> ';
            });
            h += '</div>';
        }
        if (pno) {
            var cfg = res.cfg || {};
            h += '<div class="frm" style="margin-bottom:8px;">'
               + '<label>代入設定</label><div class="wide">'
               + '<label style="font-weight:normal;text-align:left;margin-right:14px;">'
               + '<input type="checkbox" id="tplAuto"' + (num(cfg.auto_apply) ? ' checked' : '') + '> 新文件綁到這個製程時自動代入</label>'
               + '<label style="font-weight:normal;text-align:left;">'
               + '<input type="checkbox" id="tplStd"' + (num(cfg.with_std) ? ' checked' : '') + '> 代入時一併帶標準項目</label>'
               + '</div></div>';
        }
        // 欄位與文件裡的檢驗項目一樣多，品質特性一定要給寬度，否則會被擠成一條（表頭變直書）
        h += '<div class="gridwrap"><table class="grid" id="tblTpl" style="min-width:1060px;"><thead><tr>'
           + '<th style="width:36px;">#</th><th style="width:140px;">管理重點</th><th style="width:200px;">品質特性</th>'
           + '<th style="width:74px;">上限</th><th style="width:74px;">下限</th>'
           + '<th style="width:86px;">擔當者</th><th style="width:122px;">檢驗方法</th>'
           + '<th style="width:130px;">檢具編號</th><th style="width:104px;">檢驗頻率</th><th>備註</th>'
           + '<th style="width:38px;"></th></tr></thead><tbody data-eg-row-add="tplAdd" data-eg-row-del="tplDel">';
        var rows = (res.rows || []).length ? res.rows : [{}];
        // 樣板列用同一份 ownerSel/methodSel，所以先把選項塞進 CUR 的替身
        TPLCTX = { owner_depts: res.owner_depts || [], methods: res.methods || [], tool_types: res.tool_types || [] };
        $.each(rows, function (i, r) { h += tplRow(i, r); });
        h += '</tbody></table></div>';
        h += '<div style="margin-top:8px;"><button class="btn btn-sm btn-warm" id="tplSave">儲存這一組</button> '
           + '<button class="btn btn-sm btn-warm-o" id="tplSuggest">從既有文件找出重複的項目</button>'
           + '<span class="muted-help">　儲存的是目前編輯的這一組（標準項目或某一個製程）。'
           + '「找出重複的項目」會統計既有的檢驗指導書裡每一份都有的那幾列（有上下限的尺寸列不算，'
           + '那是各料號自己的），列出來讓你挑，<b>按了儲存才會真的存下去</b>。</span></div>';
        $('#setPane').html(h);
    });
}
var TPLCTX = null;
function tplRow(i, r) {
    r = r || {};
    var save = CUR;
    CUR = TPLCTX;                                   // ownerSel/methodSel 讀的是 CUR，暫時換成樣板的選項
    var own = ownerSel(r.owner_dept_id, ''), mth = methodSel(r.method, r.tool_type_id, '');
    CUR = save;
    return '<tr data-tt="' + num(r.tool_type_id) + '"><td class="c">' + (i + 1) + '</td>'
        + '<td><input class="i-ctrl" value="' + esc(r.ctrl_point || '') + '"></td>'
        + '<td><input class="i-q" value="' + esc(r.q_char || '') + '"></td>'
        + '<td><input class="i-up" value="' + esc(r.up_limit || '') + '"></td>'
        + '<td><input class="i-lo" value="' + esc(r.lo_limit || '') + '"></td>'
        + '<td>' + own + '</td><td>' + mth + '</td>'
        + '<td><input class="i-tool" value="' + esc(r.tool_no || '') + '"></td>'
        + '<td><input class="i-freq" value="' + esc(r.freq || '') + '"></td>'
        + '<td><input class="i-note" value="' + esc(r.note || '') + '"></td>'
        + '<td class="c"><button class="btn btn-xs tpl-rm">×</button></td></tr>';
}
function tplAdd($tbody) { $tbody.append(tplRow($tbody.children('tr').length, {})); renumber($tbody); }
function tplDel($tr) {
    var $tb = $tr.closest('tbody');
    if ($tb.children('tr').length <= 1) { $tb.children('tr').first().replaceWith(tplRow(0, {})); return; }
    $tr.remove(); renumber($tb);
}
$(document).on('click', '.tpl-rm', function () { tplDel($(this).closest('tr')); });
$(document).on('click', '#tplSuggest', function () {
    var pno = num($('#tplProcNo').val());
    api('tpl_suggest', { tpl_kind: pno ? 'proc' : 'std', process_no: pno }, function (res) {
        var rows = res.rows || [];
        if (!rows.length) { alert('既有文件裡找不到重複出現兩次以上的項目。'); return; }
        var $tb = $('#tblTpl tbody');
        $tb.children('tr').each(function () {
            var any = false;
            $(this).find('input').each(function () { if ($(this).val().trim() !== '') any = true; });
            if (!any) $(this).remove();
        });
        $.each(rows, function (i, r) { $tb.append(tplRow($tb.children('tr').length, r)); });
        renumber($tb);
        alert('找到 ' + rows.length + ' 個重複出現的項目，已經列在表格裡。\n\n'
            + '刪掉不要的之後，按「儲存這一組」才會真的存下去。');
    });
});
$(document).on('click', '.tpl-std', function () { setPaneTpl(0); });
$(document).on('click', '.tpl-go', function () { setPaneTpl(num($(this).data('no'))); });
acAttach('#tplProc', {
    action: 'search_process', hidden: '#tplProcNo',
    row: function (r) {
        return '<span class="hit">' + esc(r.process_name) + '</span>　<span class="muted-help">編號 '
             + num(r.process_no) + '</span>';
    },
    pick: function (r) { setPaneTpl(num(r.process_no)); }
});
$(document).on('click', '#tplSave', function () {
    var pno = num($('#tplProcNo').val());
    var rows = [];
    $('#tblTpl tbody tr').each(function () {
        var $t = $(this);
        rows.push({ ctrl_point: $t.find('.i-ctrl').val() || '', q_char: $t.find('.i-q').val() || '',
                    up_limit: $t.find('.i-up').val() || '', lo_limit: $t.find('.i-lo').val() || '',
                    owner_dept_id: num($t.find('.i-own').val()), method: $t.find('.i-mth').val() || '',
                    tool_type_id: num($t.find('.i-mth option:selected').data('tt')) || num($t.attr('data-tt')),
                    tool_no: $t.find('.i-tool').val() || '', freq: $t.find('.i-freq').val() || '',
                    note: $t.find('.i-note').val() || '' });
    });
    post('tpl_save', {
        tpl_kind: pno ? 'proc' : 'std', process_no: pno, rows: JSON.stringify(rows),
        auto_apply: $('#tplAuto').is(':checked') ? 1 : 0,
        with_std: $('#tplStd').length ? ($('#tplStd').is(':checked') ? 1 : 0) : 1
    }, function () { alert('已儲存'); setPaneTpl(pno); });
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
    var p = {};
    // 只送目前這個分頁上真的有的欄位（後端一律用 array_key_exists 判「有沒有送」，沒送就不動）
    if ($('#stWs').length) {
        p.work_start = $('#stWs').val(); p.work_end = $('#stWe').val();
        $.each(SS_SLOTS, function (k) { p['stamp_' + k] = num($('#stTpl_' + k).val()); });
        $.each(SS_KINDS, function (k) {
            p['auto_' + k] = $('.st-auto[data-kind="' + k + '"]').is(':checked') ? 1 : 0;
            p['paper_' + k] = JSON.stringify({ size: $('#stPaper_' + k).val(), orient: $('#stOrient_' + k).val() });
            $.each(SS_SLOTS, function (slot) {
                if (slot === 'maker') return;
                p['signercfg_' + k + '_' + slot] = JSON.stringify({
                    dept_id: num($('#sgD_' + k + '_' + slot).val()),
                    position_id: num($('#sgP_' + k + '_' + slot).val()),
                    dep_dept_id: num($('#sgDD_' + k + '_' + slot).val()),
                    dep_position_id: num($('#sgPP_' + k + '_' + slot).val())
                });
            });
        });
    }
    if ($('.od-on').length) {
        var od = [];
        $('.od-on').each(function () {
            var id = num($(this).data('id'));
            od.push({ dept_id: id, on: $(this).is(':checked') ? 1 : 0,
                      label: $('.od-lab[data-id="' + id + '"]').val() || '' });
        });
        p.owner_depts = JSON.stringify(od);
        var tt = [];
        $('.mt-chk:checked').each(function () { tt.push(num($(this).val())); });
        p.method_tool_types = JSON.stringify(tt);
        p.method_extra = JSON.stringify(($('#mtExtra').val() || '').split('\n'));
        p.sip_notice_default = $('#sipNotice').val() || '';
    }
    if (!Object.keys(p).length) { alert('「檢驗項目預設值」請用該分頁裡的「儲存這一組」。'); return; }
    post('settings_save', p, function () { alert('已儲存'); });
});

/* ══════════════════════ 起動 ══════════════════════ */

/**
 * 由別的頁面帶參數連過來（專案管理「文件檢核」的 SOP／SIP 欄就是這樣連進來的）：
 *   ?tab=sop|sip  直接開在那一個分頁上
 *   ?kw=料號      帶進關鍵字並查詢
 * **分頁一定要在 kindOptions() 之前切好**——「版面」下拉是依目前分頁長出來的，
 * 先長再切會先閃出另一個分頁的版面選項，而且 load() 會送出錯的 tab。
 */
function applyUrlParams() {
    try {
        var q = new URLSearchParams(location.search);
        var t = String(q.get('tab') || '').toLowerCase();
        if (t === 'sop' || t === 'sip') {
            TAB = t;
            $('.ss-tab').removeClass('on').filter('[data-tab="' + t + '"]').addClass('on');
        }
        var kw = q.get('kw');
        if (kw) $('#fKw').val(kw);
    } catch (e) { /* 網址參數有問題時照常開頁面，不可以讓整頁掛掉 */ }
}

$(function () {
    if (!SS_PERMS.canView) return;
    applyUrlParams();
    kindOptions('#fKind', true);
    load();
});
$('#btnPageHelp').on('click', function () { openMask('helpUseMask'); });
