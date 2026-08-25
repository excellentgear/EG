/* 專案管理（2-GM-02）前端邏輯
 * 抽成獨立檔案：本頁 inline script 已經很長，抽出來才能用 node --check 掃語法
 * （CLAUDE.md 記過的坑：inline script 有語法錯誤時 php -l 抓不到，整頁 JS 會全失效）。
 * 依賴 project_mgmt.php 先宣告的 API / PERM / META / esc / dispDate / openMask / api 等。
 */
/* global $, API, PERM, esc, dispDate, openMask, closeMask, api, num, EGAsDoc, EGStamp */
/* exported pjInit */

var STATUS_LABEL = {
    draft: '草稿', submitted: '已送簽', approved: '已核准',
    rejected: '已退回', closed: '已結案', terminated: '已終止'
};
/* 暖色系固定調色盤（ai-rules/10：禁冷暖混雜、禁隨機上色） */
var WARM_COLORS = [
    ['#F7E0BD', '砂'], ['#F0A24B', '琥珀'], ['#DD5138', '珊瑚紅'], ['#C97B2E', '赭'],
    ['#8A5A2B', '暖棕'], ['#E8C39E', '淺砂'], ['#B5762A', '深琥珀'], ['#D99A6C', '陶土']
];

/* ══════════════════════════ 初始化 ══════════════════════════ */
function pjInit() {
    api('meta').done(function (res) {
        META = res;
        fillMeta();
        loadList();
        pjHandleUrlParams();
    });

    $('#btnSearch').on('click', function () { PAGE = 1; loadList(); });
    $('#btnReset').on('click', function () {
        $('#fKw,#fType,#fPhase,#fStatus,#fOwner').val('');
        FTAGS = []; renderTagFilter(); PAGE = 1; loadList();
    });
    $('#fKw').on('keydown', function (e) { if (e.which === 13) { PAGE = 1; loadList(); } });
    $('#pgSize').on('change', function () { PSIZE = num($(this).val()) || 10; PAGE = 1; renderList(); });
    $('#btnCsv').on('click', function () { window.location = API + '?' + $.param(listQuery()) + '&action=export_csv'; });
    $('#btnNew').on('click', function () { openProject(0); });
    $('#btnOrderToPrj').on('click', openO2P);
    $('#btnPhrase').on('click', function () { openPhrase('purpose', ''); });
    $('#btnTags').on('click', openTagSetting);
    $('#btnSetting').on('click', openSetting);
    $('#btnOverview').on('click', openOverview);

    /* 詳情分頁切換 */
    $(document).on('click', '.pj-tab', function () {
        var p = $(this).data('pane');
        $(this).addClass('active').siblings().removeClass('active');
        $('#' + p).addClass('active').siblings('.pj-pane').removeClass('active');
    });
}

/* 網址參數：從通知點進來時直接開到該筆
   ?cosign=n     待會簽通知（PROJECT_COSIGN）→ 開該專案並跳到會簽分頁
   ?project_id=n 結果通知（PROJECT_RESULT）／文件檢核跳回來
   ?kw=xxx       其他頁面帶關鍵字過來（比照 td_dev_eval/pfmea 的既有慣例） */
function pjHandleUrlParams() {
    var q = {};
    window.location.search.replace(/^\?/, '').split('&').forEach(function (kv) {
        if (!kv) return;
        var i = kv.indexOf('=');
        q[decodeURIComponent(i < 0 ? kv : kv.slice(0, i))] = i < 0 ? '' : decodeURIComponent(kv.slice(i + 1));
    });
    if (q.kw) { $('#fKw').val(q.kw); }
    var cosId = num(q.cosign);
    if (cosId) {
        /* 會簽通知只帶 cosign_id，要先問後端這一列屬於哪個專案 */
        api('list', {}).done(function () {
            api('cosign_owner', { cosign_id: cosId }).done(function (r) {
                if (!num(r.project_id)) { alert('找不到這筆會簽項目（可能已被移除）'); return; }
                openProject(num(r.project_id));
                setTimeout(function () {
                    $('.pj-tab[data-pane="paneSign"]').click();
                    $('[data-cosign="' + cosId + '"]').trigger('click');
                }, 500);
            });
        });
        return;
    }
    if (num(q.project_id)) openProject(num(q.project_id));
}

function fillMeta() {
    var t = $('#fType').empty().append('<option value="">全部類型</option>');
    $.each(META.types || {}, function (k, v) { t.append('<option value="' + k + '">' + esc(v + '型（' + k + '）') + '</option>'); });
    var ph = $('#fPhase').empty().append('<option value="">全部階段</option>');
    $.each(META.phases || {}, function (k, v) { ph.append('<option value="' + k + '">' + esc(v) + '</option>'); });
    var ow = $('#fOwner').empty().append('<option value="">全部負責人</option>');
    $.each(META.people || [], function (i, p) { ow.append('<option value="' + p.id + '">' + esc(peopleLabel(p)) + '</option>'); });
    renderTagFilter();
}

/* 人員顯示：部門/職稱/姓名，長期請假要標假別期間（ai-rules/08 第五節） */
function peopleLabel(p) {
    var s = (p.dept_name ? p.dept_name + ' ' : '') + (p.position_name ? p.position_name + ' ' : '') + (p.user_cname || '');
    if (p.leave_note) s += '（' + p.leave_note + '）';
    return s;
}

/* 可被指派為專案負責人的人（管理員可用「部門×職稱」限定；未設定時＝全體） */
function ownerPeople() {
    return (META.owner_people && META.owner_people.length) ? META.owner_people : (META.owner_scope && META.owner_scope.length ? [] : (META.people || []));
}

function tagsOf(kind) {
    return $.grep(META.tags || [], function (t) { return t.tag_kind === kind && num(t.is_active) === 1; });
}

function renderTagFilter() {
    var list = tagsOf('project');
    if (!list.length) { $('#tagFilterBar').empty(); return; }
    var h = '<span style="font-size:12px;color:#8a6d45;">標籤篩選：</span>';
    $.each(list, function (i, t) {
        var on = $.inArray(num(t.tag_id), FTAGS) >= 0;
        h += '<span class="pj-tag' + (on ? ' on' : '') + '" data-tag="' + t.tag_id + '"' +
             (t.color && !on ? ' style="background:' + esc(t.color) + '33;border-color:' + esc(t.color) + ';"' : '') +
             '>' + esc(t.tag_name) + '</span>';
    });
    if (FTAGS.length) h += '<span class="pj-tag" id="tagClear" style="background:#EFE7D8;">✕ 清除</span>';
    $('#tagFilterBar').html(h);
}
$(document).on('click', '#tagFilterBar .pj-tag[data-tag]', function () {
    var id = num($(this).data('tag'));
    var i = $.inArray(id, FTAGS);
    if (i >= 0) FTAGS.splice(i, 1); else FTAGS.push(id);
    renderTagFilter(); PAGE = 1; loadList();
});
$(document).on('click', '#tagClear', function () { FTAGS = []; renderTagFilter(); PAGE = 1; loadList(); });

/* ══════════════════════════ 清單 ══════════════════════════ */
function listQuery() {
    return {
        kw: $('#fKw').val(), type: $('#fType').val(), phase: $('#fPhase').val(),
        status: $('#fStatus').val(), owner: $('#fOwner').val(), tags: FTAGS.join(',')
    };
}

function loadList() {
    $('#listBody').html('<tr><td colspan="14" style="padding:18px;color:#8a6d45;">載入中…</td></tr>');
    api('list', listQuery()).done(function (res) { LIST = res.rows || []; renderList(); });
}

function renderList() {
    var total = LIST.length;
    var pages = Math.max(1, Math.ceil(total / PSIZE));
    if (PAGE > pages) PAGE = pages;
    var rows = LIST.slice((PAGE - 1) * PSIZE, PAGE * PSIZE);

    $('#pgInfo').text('共 ' + total + ' 筆／第 ' + PAGE + '/' + pages + ' 頁');
    var pb = '';
    if (pages > 1) {
        pb += '<button data-pg="1">«</button><button data-pg="' + Math.max(1, PAGE - 1) + '">‹</button>';
        var s = Math.max(1, PAGE - 2), e = Math.min(pages, s + 4);
        for (var i = s; i <= e; i++) pb += '<button data-pg="' + i + '"' + (i === PAGE ? ' class="on"' : '') + '>' + i + '</button>';
        pb += '<button data-pg="' + Math.min(pages, PAGE + 1) + '">›</button><button data-pg="' + pages + '">»</button>';
    }
    $('#pgBtns').html(pb);

    if (!rows.length) {
        $('#listBody').html('<tr><td colspan="14" style="padding:18px;color:#8a6d45;">沒有符合條件的專案</td></tr>');
        return;
    }
    var h = '';
    $.each(rows, function (i, r) {
        var miss = missCountOf(r);
        h += '<tr>'
          + '<td><b>' + esc(r.project_no) + '</b></td>'
          + '<td>' + esc(r.type_label) + '</td>'
          + '<td class="l"><span class="pj-op" data-open="' + r.project_id + '">' + esc(r.project_name) + '</span></td>'
          + '<td class="l">' + esc(r.customer_name || '－') + '</td>'
          + '<td>' + esc(r.owner_name || '－') + '</td>'
          + '<td><span class="ph">' + esc(r.phase_label) + '</span></td>'
          + '<td><span class="st st-' + esc(r.status) + '">' + esc(STATUS_LABEL[r.status] || r.status) + '</span></td>'
          + '<td>' + dispDate(r.start_date) + (r.end_date ? ' ~ ' + dispDate(r.end_date) : '') + '</td>'
          + '<td>' + barHtml(num(r.progress)) + '</td>'
          + '<td>' + num(r.order_cnt) + '</td>'
          + '<td>' + num(r.part_cnt) + '</td>'
          + '<td>' + (miss === null ? '<span class="pj-ok-badge">－</span>'
                    : (miss > 0 ? '<span class="pj-miss-badge" title="還有文件未建立">缺' + miss + '</span>'
                                : '<span class="pj-ok-badge">齊全</span>')) + '</td>'
          + '<td>' + (num(r.bom_alert_cnt) > 0
                    ? '<span class="pj-alert-badge" title="BOM 製程有變更未知悉">' + num(r.bom_alert_cnt) + '</span>'
                    : '<span class="pj-ok-badge">－</span>') + '</td>'
          + '<td>'
          + '<span class="pj-op" data-open="' + r.project_id + '">檢視</span>'
          + '<span class="pj-op" data-print="' + r.project_id + '">列印</span>'
          + (PERM.canAdmin ? '<span class="pj-op" data-del="' + r.project_id + '" style="color:#DD5138;">刪除</span>' : '')
          + '</td></tr>';
    });
    $('#listBody').html(h);
}

/* 清單的「文件」欄只知道有幾個料號，缺幾件要開專案才算得出來；這裡不猜，開過的才顯示 */
function missCountOf(r) {
    return (r._miss === undefined) ? null : r._miss;
}

function barHtml(p) {
    p = Math.max(0, Math.min(100, p));
    return '<div class="pj-bar"><i style="width:' + p + '%"></i><span>' + p + '%</span></div>';
}

$(document).on('click', '#pgBtns button', function () { PAGE = num($(this).data('pg')) || 1; renderList(); });
$(document).on('click', '[data-open]', function () { openProject(num($(this).data('open'))); });
$(document).on('click', '[data-del]', function () {
    var id = num($(this).data('del'));
    if (!confirm('確定刪除這個專案？（訂單綁定會一併釋出，可再轉給別的專案）')) return;
    api('delete', { project_id: id }, 'POST').done(function (res) { alert(res.message); loadList(); });
});
$(document).on('click', '[data-print]', function () {
    var id = num($(this).data('print'));
    api('get', { project_id: id }).done(function (res) { printPlan(res); });
});

/* ══════════════════════════ 專案詳情 ══════════════════════════ */
function openProject(id) {
    if (!id) { CUR = null; renderDetail(newProjectShell()); openMask('prjMask'); return; }
    api('get', { project_id: id }).done(function (res) {
        CUR = res;
        /* 順路把缺件數帶回清單那一列（避免清單為了算檢核去掃全部專案） */
        var miss = 0;
        $.each(res.doc_check || [], function (i, r) { miss += num(r.missing); });
        $.each(LIST, function (i, r) { if (num(r.project_id) === id) r._miss = miss; });
        renderList();
        renderDetail(res);
        openMask('prjMask');
    });
}

function newProjectShell() {
    return {
        project: { project_id: 0, project_type: 'C', phase: 'initiating', status: 'draft', progress: 0 },
        goals: [], tasks: [], orders: [], parts: [], processes: [], cards: [], cosigns: [],
        alerts: [], doc_check: [], can_edit: true, can_approve: false
    };
}

function renderDetail(res) {
    var p = res.project;
    $('#prjTitle').html('<i class="fa fa-folder-open-o"></i> ' +
        (p.project_id ? esc(p.project_no + '　' + p.project_name) : '新增專案'));
    renderAlertBar(res);
    renderBase(res);
    renderPlan(res);
    renderCards(res);
    renderRel(res);
    renderCheck(res);
    renderSign(res);
    renderFoot(res);
    $('.pj-tab').first().click();
}

function renderAlertBar(res) {
    var a = res.alerts || [];
    if (!a.length) { $('#prjAlertBar').empty(); return; }
    var h = '<div class="pj-alertbar"><b><i class="fa fa-bell"></i> BOM 製程有 ' + a.length + ' 筆變更</b>'
          + ' <span class="pj-op" id="btnAckAll">全部標記知悉</span>';
    $.each(a.slice(0, 8), function (i, x) {
        h += '<span class="it">' + dispDate(x.detected_at) + '　' + esc(x.detail) + '</span>';
    });
    if (a.length > 8) h += '<span class="it">…另有 ' + (a.length - 8) + ' 筆</span>';
    h += '</div>';
    $('#prjAlertBar').html(h);
}
$(document).on('click', '#btnAckAll', function () {
    api('bom_alert_ack', { project_id: CUR.project.project_id }, 'POST').done(function () {
        openProject(num(CUR.project.project_id)); loadList();
    });
});

/* ── 基本資料 ── */
function renderBase(res) {
    var p = res.project, ro = res.can_edit ? '' : ' disabled';
    var typeOpt = '', phaseOpt = '', ownerOpt = '<option value="">（請選擇）</option>', custOpt = '<option value="">（無）</option>', deptOpt = '<option value="">（無）</option>';
    $.each(META.types || {}, function (k, v) { typeOpt += '<option value="' + k + '"' + (p.project_type === k ? ' selected' : '') + '>' + esc(v + '型（' + k + '）') + '</option>'; });
    $.each(META.phases || {}, function (k, v) { phaseOpt += '<option value="' + k + '"' + (p.phase === k ? ' selected' : '') + '>' + esc(v) + '</option>'; });
    /* 只列合格的人；本專案目前的負責人即使事後不合資格也一定保留，否則一打開就變空白、一存檔就被洗掉。
       新專案（還沒有 owner_id）預設帶「目前使用者」——非管理員本來就只能挑自己部門的人。 */
    if (!num(p.owner_id) && !num(p.project_id)) p.owner_id = META.owner_default || PERM.uid;
    var ownerCands = ownerPeople().slice(), ownerHas = false;
    $.each(ownerCands, function (i, x) { if (num(p.owner_id) === num(x.id)) ownerHas = true; });
    if (!ownerHas && num(p.owner_id) > 0) {
        var cur = $.grep(META.people || [], function (x) { return num(x.id) === num(p.owner_id); });
        if (cur.length) ownerCands.unshift(cur[0]);
        else ownerCands.unshift({ id: p.owner_id, user_cname: p.owner_name || ('#' + p.owner_id) });
    }
    $.each(ownerCands, function (i, x) { ownerOpt += '<option value="' + x.id + '"' + (num(p.owner_id) === num(x.id) ? ' selected' : '') + '>' + esc(peopleLabel(x)) + '</option>'; });
    $.each(META.customers || [], function (i, x) { custOpt += '<option value="' + esc(x.customer_id) + '"' + (p.customer_id === x.customer_id ? ' selected' : '') + '>' + esc(x.customer) + '</option>'; });
    $.each(META.depts || [], function (i, x) { deptOpt += '<option value="' + x.id + '"' + (num(p.dept_id) === num(x.id) ? ' selected' : '') + '>' + esc(x.name) + '</option>'; });

    var h = '<div class="sec"><h5>專案基本資料</h5><div class="grid3">'
      + '<div><label>專案代號</label><input type="text" class="ro-auto" readonly value="' + esc(p.project_no || '（存檔後自動產生）') + '"></div>'
      + '<div id="fldType"><label>專案類型 <span style="color:#DD5138;">*</span></label><select id="eType"' + ro + '>' + typeOpt + '</select>'
      + '<div class="pj-err"></div></div>'
      + '<div><label>目前階段</label><select id="ePhase"' + ro + '>' + phaseOpt + '</select></div>'
      + '<div style="grid-column:1 / -1;" id="fldName"><label>專案名稱 <span style="color:#DD5138;">*</span></label>'
      + '<input type="text" id="eName" value="' + esc(p.project_name || '') + '"' + ro + '><div class="pj-err"></div></div>'
      + '<div><label>客戶</label><select id="eCust" data-eg-filter="輸入客戶名稱篩選…"' + ro + '>' + custOpt + '</select></div>'
      + '<div id="fldOwner"><label>專案負責人 <span style="color:#DD5138;">*</span>'
      + (META.owner_restricted ? '<span class="pj-hint" style="margin-left:6px;">（只能挑自己部門，含兼任）</span>' : '')
      + '</label>'
      + '<select id="eOwner" data-eg-filter="輸入姓名篩選…"' + ro + '>' + ownerOpt + '</select><div class="pj-err"></div></div>'
      + '<div><label>主辦部門</label><select id="eDept"' + ro + '>' + deptOpt + '</select></div>'
      + '<div><label>專案起日</label><input type="date" id="eStart" value="' + esc(p.start_date || '') + '"' + ro + '></div>'
      + '<div id="fldEnd"><label>專案迄日</label><input type="date" id="eEnd" value="' + esc(p.end_date || '') + '"' + ro + '><div class="pj-err"></div></div>'
      + '<div><label>核定預算</label><input type="number" step="0.01" id="eBudget" value="' + esc(p.budget || '') + '"' + ro + '></div>'
      + '<div style="grid-column:1 / -1;"><label>專案分類標籤</label><div class="pj-tagbar" id="eTagBar"></div></div>'
      + '</div></div>'
      /* 專案內容只留「專案目的／專案目標」兩項（使用者要求，2026-08-25）；
         每欄右上角有「常用語句」可挑事先編好的句子帶入（語句本身在同一個跳窗裡新增/修改/刪除）。 */
      + '<div class="sec"><h5>專案內容（程序書 §6.8 籌備階段的提案內容）</h5>'
      + phraseField('ePurpose', 'purpose', '專案目的', p.purpose, ro, res.can_edit)
      + phraseField('eGoalDesc', 'goal_desc', '專案目標（列印在執行規劃表表頭）', p.goal_desc, ro, res.can_edit)
      + '</div>';

    if (num(p.project_id) && p.status === 'closed') {
        h += '<div class="sec"><h5>結案</h5><div class="pj-hint">結案日期：' + dispDate(p.close_date) + '</div>'
           + '<label style="margin-top:6px;">專案總結報告</label>'
           + '<textarea rows="4" readonly class="ro-auto">' + esc(p.close_summary || '') + '</textarea></div>';
    }
    $('#paneBase').html(h);
    renderTagPick('eTagBar', 'project', (p.tag_ids || '').split(',').map(num), res.can_edit);
}

/* 專案內容欄位＋「常用語句」入口（只有可編輯時才出現按鈕；唯讀檢視不給帶入） */
function phraseField(id, fieldKey, label, val, ro, editable) {
    return '<div style="margin-bottom:10px;">'
      + '<div style="display:flex;align-items:center;gap:10px;margin-bottom:3px;">'
      + '<label style="margin:0;">' + esc(label) + '</label>'
      + (editable && PERM.canEdit
          ? '<span class="pj-op" data-phrase="' + fieldKey + '" data-phtarget="' + id + '">'
            + '<i class="fa fa-commenting-o"></i> 常用語句</span>'
          : '')
      + '</div>'
      + '<textarea id="' + id + '" rows="3"' + ro + '>' + esc(val || '') + '</textarea></div>';
}

/* 標籤挑選（可自訂標籤，按標籤選擇＝使用者要求） */
function renderTagPick(boxId, kind, selected, editable) {
    var list = tagsOf(kind);
    if (!list.length) {
        $('#' + boxId).html('<span class="pj-hint">尚未建立' + esc((META.tag_kinds || {})[kind] || '') +
            '標籤' + (PERM.canAdmin ? '（可在工具列「標籤設定」新增）' : '') + '</span>');
        return;
    }
    var h = '';
    $.each(list, function (i, t) {
        var on = $.inArray(num(t.tag_id), selected) >= 0;
        h += '<span class="pj-tag' + (on ? ' on' : '') + (editable ? '' : ' ro') + '" data-pick="' + t.tag_id + '"' +
             (t.color && !on ? ' style="background:' + esc(t.color) + '33;border-color:' + esc(t.color) + ';"' : '') +
             '>' + esc(t.tag_name) + '</span>';
    });
    $('#' + boxId).html(h).data('editable', !!editable);
}
$(document).on('click', '.pj-tagbar[id!="tagFilterBar"] .pj-tag[data-pick]', function () {
    var $bar = $(this).closest('.pj-tagbar');
    if (!$bar.data('editable')) return;
    $(this).toggleClass('on').removeAttr('style');
});
function pickedTags(boxId) {
    var out = [];
    $('#' + boxId + ' .pj-tag.on').each(function () { out.push(num($(this).data('pick'))); });
    return out.join(',');
}

/* 前端即時驗證：紅框＋該欄旁紅字寫原因（表單三總則③；後端 prj_validate 會再擋一次） */
function showFieldErrors(fields) {
    $('.fld-bad').removeClass('fld-bad');
    $('.pj-err').hide().text('');
    var map = { project_name: '#fldName', project_type: '#fldType', owner_id: '#fldOwner', end_date: '#fldEnd' };
    var first = null;
    $.each(fields || {}, function (k, msg) {
        var sel = map[k];
        if (!sel || !$(sel).length) { if (!first) first = msg; return; }
        $(sel).addClass('fld-bad').find('.pj-err').text(msg).show();
        if (!first) first = null;
    });
    if (first) alert(first);
}

function collectBase() {
    return {
        project_id: num(CUR ? CUR.project.project_id : 0),
        project_type: $('#eType').val(), project_name: $.trim($('#eName').val()),
        customer_id: $('#eCust').val(), owner_id: $('#eOwner').val(), dept_id: $('#eDept').val(),
        phase: $('#ePhase').val(), start_date: $('#eStart').val(), end_date: $('#eEnd').val(),
        budget: $('#eBudget').val(), tag_ids: pickedTags('eTagBar'),
        goal_desc: $('#eGoalDesc').val(), purpose: $('#ePurpose').val()
    };
}

function saveBase(after) {
    var d = collectBase();
    /* 前端先驗一次（同一套規則），錯的欄位當場標紅 */
    var err = {};
    if (!d.project_name) err.project_name = '請填專案名稱';
    if (!d.project_type) err.project_type = '請選擇專案類型';
    if (!num(d.owner_id)) err.owner_id = '請選擇專案負責人';
    if (d.start_date && d.end_date && d.start_date > d.end_date) err.end_date = '專案迄日不可早於起日';
    if (Object.keys(err).length) { showFieldErrors(err); return; }
    $('.fld-bad').removeClass('fld-bad'); $('.pj-err').hide();

    api('save', d, 'POST').done(function (res) {
        alert(res.message);
        loadList();
        openProject(num(res.project_id));
        if (after) after(num(res.project_id));
    }).fail(function (xhr) {
        try { showFieldErrors((JSON.parse(xhr.responseText) || {}).fields); } catch (e) { /* ajaxError 已提示 */ }
    });
}

/* ── 底部按鈕 ── */
function renderFoot(res) {
    var p = res.project, h = '<button onclick="closeMask(\'prjMask\')">關閉</button>';
    if (num(p.project_id)) {
        h += '<button id="btnPrintPlan"><i class="fa fa-print"></i> 列印執行規劃表</button>';
        if (res.can_edit && (p.status === 'draft' || p.status === 'rejected')) {
            h += '<button id="btnSubmit"><i class="fa fa-paper-plane"></i> 送簽</button>';
        }
        if (res.can_approve) {
            h += '<button id="btnApprove" class="b-ok"><i class="fa fa-check"></i> 核准</button>'
               + '<button id="btnReject" class="b-danger"><i class="fa fa-times"></i> 退回</button>';
        }
        if (res.can_edit && p.status !== 'closed') {
            h += '<button id="btnClose"><i class="fa fa-flag-checkered"></i> 結案</button>';
        }
    }
    if (res.can_edit) h += '<button class="b-ok" id="btnSaveBase"><i class="fa fa-save"></i> 儲存</button>';
    $('#prjFoot').html(h);
}
$(document).on('click', '#btnSaveBase', function () { saveBase(); });
$(document).on('click', '#btnPrintPlan', function () { printPlan(CUR); });

/* ══════════════════════════ 執行規劃表（2-GM-02-02） ══════════════════════════ */
function renderPlan(res) {
    var p = res.project;
    if (!num(p.project_id)) {
        $('#panePlan').html('<div class="pj-hint" style="padding:14px;">請先儲存專案基本資料，才能編排目標與任務。</div>');
        return;
    }
    var h = '<div class="pj-toolbar" style="margin-bottom:8px;">'
      + '<label>檢視</label>'
      + '<select id="gView"><option value="gantt"' + (GVIEW === 'gantt' ? ' selected' : '') + '>時間軸（甘特）</option>'
      + '<option value="list"' + (GVIEW === 'list' ? ' selected' : '') + '>清單</option></select>'
      + '<label>刻度</label><select id="gScale">'
      + '<option value="day"' + (GSCALE === 'day' ? ' selected' : '') + '>日</option>'
      + '<option value="week"' + (GSCALE === 'week' ? ' selected' : '') + '>週</option>'
      + '<option value="month"' + (GSCALE === 'month' ? ' selected' : '') + '>月</option></select>'
      + (res.can_edit ? '<button id="btnGoalAdd"><i class="fa fa-plus"></i> 新增目標</button>'
                      + '<button class="btn-warm" id="btnPlanSave"><i class="fa fa-save"></i> 儲存規劃表</button>' : '')
      + '</div>'
      + '<div id="ganttBox"></div>'
      + '<div id="planEditBox"' + (res.can_edit ? '' : ' style="display:none;"') + '></div>';
    $('#panePlan').html(h);
    drawGantt(res);
    if (res.can_edit) drawPlanEditor(res);
}

/* ── 甘特時間軸 ── */
function drawGantt(res) {
    var tasks = res.tasks || [], goals = res.goals || [];
    if (GVIEW === 'list') { drawGanttList(res); return; }
    var range = ganttRange(res.project, tasks);
    if (!range) {
        $('#ganttBox').html('<div class="pj-hint" style="padding:14px;">還沒有任何日期，填好任務的預計起迄日後就會畫出時間軸。</div>');
        return;
    }
    var d0 = new Date(range.start + 'T00:00:00'), d1 = new Date(range.end + 'T00:00:00');
    /* 左右各留 3 天，條子才不會貼著邊 */
    d0.setDate(d0.getDate() - 3); d1.setDate(d1.getDate() + 3);
    var span = Math.max(1, dayDiff(d0, d1));
    var today = META.today ? new Date(META.today + 'T00:00:00') : new Date();

    function pct(d) { return (dayDiff(d0, d) / span) * 100; }

    /* 刻度線 */
    var grid = '', ticks = '';
    var step = GSCALE === 'day' ? 1 : (GSCALE === 'week' ? 7 : 30);
    var cur = new Date(d0);
    if (GSCALE === 'week') { while (cur.getDay() !== 1) cur.setDate(cur.getDate() + 1); }
    if (GSCALE === 'month') { cur.setDate(1); if (cur < d0) cur.setMonth(cur.getMonth() + 1); }
    var guard = 0;
    while (cur <= d1 && guard++ < 400) {
        var x = pct(cur);
        grid += '<i class="' + (GSCALE === 'day' && cur.getDay() === 1 ? 'mon' : '') + '" style="left:' + x + '%"></i>';
        ticks += '<span class="gantt-tick" style="left:' + x + '%">' + tickLabel(cur) + '</span>';
        cur = new Date(cur.getTime());
        if (GSCALE === 'month') cur.setMonth(cur.getMonth() + 1); else cur.setDate(cur.getDate() + step);
    }
    var todayMark = (today >= d0 && today <= d1)
        ? '<span class="g-today" style="left:' + pct(today) + '%"></span>' : '';

    var h = '<div class="gantt-wrap"><div class="gantt">'
      + '<div class="gantt-row gantt-head"><div class="gantt-lbl">目標／主要任務</div>'
      + '<div class="gantt-own">負責人</div>'
      + '<div class="gantt-track" style="min-height:24px;"><div class="gantt-grid">' + grid + '</div>' + ticks + '</div></div>';

    var grouped = groupTasks(goals, tasks);
    $.each(grouped, function (gi, g) {
        h += '<div class="gantt-row goal"><div class="gantt-lbl" title="' + esc(g.goal_name) + '">'
           + esc((gi + 1) + '. ' + g.goal_name) + '</div>'
           + '<div class="gantt-own">' + esc(g.dept_name || '') + '</div>'
           + '<div class="gantt-track"><div class="gantt-grid">' + grid + '</div>' + todayMark + '</div></div>';
        $.each(g.tasks, function (ti, t) {
            h += '<div class="gantt-row"><div class="gantt-lbl" style="padding-left:22px;" title="' + esc(t.task_name) + '">'
               + esc(t.task_name) + '</div>'
               + '<div class="gantt-own">' + esc(t.owner_name || '－') + '</div>'
               + '<div class="gantt-track"><div class="gantt-grid">' + grid + '</div>' + todayMark
               + barsFor(t, d0, span, today) + '</div></div>';
        });
    });
    h += '</div></div>'
      + '<div class="gantt-legend">'
      + '<span><em style="background:#F7E0BD;border:1px solid #E0C9A2;"></em> 預計</span>'
      + '<span><em style="background:#C97B2E;"></em> 實際</span>'
      + '<span><em style="background:#DD5138;"></em> 逾期未完成</span>'
      + '<span><em style="background:#8A5A2B;width:10px;height:10px;transform:rotate(45deg);border-radius:2px;"></em> 里程碑</span>'
      + '<span><em style="background:#DD5138;width:2px;height:14px;border-radius:0;"></em> 今天（' + dispDate(META.today) + '）</span>'
      + '</div>';
    $('#ganttBox').html(h);
}

function tickLabel(d) {
    if (GSCALE === 'month') return (d.getMonth() + 1) + '月';
    return (d.getMonth() + 1) + '/' + d.getDate();
}

function barsFor(t, d0, span, today) {
    var out = '';
    function pos(a, b) {
        var s = dayDiff(d0, a) / span * 100;
        var w = Math.max(0.6, dayDiff(a, b) / span * 100);
        return 'left:' + s + '%;width:' + w + '%;';
    }
    var ps = t.plan_start ? new Date(t.plan_start + 'T00:00:00') : null;
    var pe = t.plan_end ? new Date(t.plan_end + 'T00:00:00') : null;
    var as = t.act_start ? new Date(t.act_start + 'T00:00:00') : null;
    var ae = t.act_end ? new Date(t.act_end + 'T00:00:00') : null;

    if (num(t.is_milestone) && (pe || ps)) {
        var m = pe || ps;
        out += '<span class="g-ms" style="left:' + (dayDiff(d0, m) / span * 100) + '%" title="里程碑：' + esc(t.task_name) + '"></span>';
        return out;
    }
    if (ps && pe) out += '<span class="g-plan" style="' + pos(ps, pe) + '" title="預計 ' + dispDate(t.plan_start) + ' ~ ' + dispDate(t.plan_end) + '"></span>';
    if (as || ae) {
        var a1 = as || ps, a2 = ae || today;
        if (a1 && a2) {
            /* 逾期＝已過預計完成日但還沒實際完成 */
            var late = (!ae && pe && today > pe);
            out += '<span class="g-act' + (late ? ' late' : '') + '" style="' + pos(a1, a2) + '" title="實際 '
                 + dispDate(t.act_start) + ' ~ ' + (t.act_end ? dispDate(t.act_end) : '進行中') + '"></span>';
        }
    } else if (ps && pe && !ae && today > pe) {
        out += '<span class="g-act late" style="' + pos(pe, today) + '" title="逾期未完成"></span>';
    }
    return out;
}

function drawGanttList(res) {
    var grouped = groupTasks(res.goals || [], res.tasks || []);
    var h = '<div class="pj-table-wrap"><table class="pj-table"><thead><tr>'
      + '<th style="width:34px;">項次</th><th>目標／主要任務</th><th style="width:84px;">負責人</th>'
      + '<th style="width:170px;">預計</th><th style="width:170px;">實際</th>'
      + '<th style="width:96px;">進度</th><th style="width:82px;">狀態</th></tr></thead><tbody>';
    if (!grouped.length) h += '<tr><td colspan="7" style="padding:14px;color:#8a6d45;">尚未建立目標與任務</td></tr>';
    $.each(grouped, function (gi, g) {
        h += '<tr style="background:#FBF3E6;font-weight:bold;"><td>' + (gi + 1) + '</td>'
           + '<td class="l">' + esc(g.goal_name) + '</td><td>' + esc(g.dept_name || '') + '</td>'
           + '<td colspan="4"></td></tr>';
        $.each(g.tasks, function (ti, t) {
            var stt = taskState(t, META.today);
            h += '<tr><td>' + (gi + 1) + '.' + (ti + 1) + '</td>'
               + '<td class="l" style="padding-left:22px;">' + esc(t.task_name)
               + (num(t.is_milestone) ? ' <span style="color:#8A5A2B;">◆里程碑</span>' : '') + '</td>'
               + '<td>' + esc(t.owner_name || '－') + '</td>'
               + '<td>' + dispDate(t.plan_start) + ' ~ ' + dispDate(t.plan_end) + '</td>'
               + '<td>' + dispDate(t.act_start) + ' ~ ' + dispDate(t.act_end) + '</td>'
               + '<td>' + barHtml(num(t.progress)) + '</td>'
               + '<td>' + stateBadge(stt) + '</td></tr>';
        });
    });
    h += '</tbody></table></div>';
    $('#ganttBox').html(h);
}

/* 與後端 prj_task_state() 同一套判定（有實際完成日時一律由日期決定，不看目前進度％） */
function taskState(t, asof) {
    var pe = t.plan_end || '', ps = t.plan_start || '', ae = t.act_end || '';
    if (ae) { if (ae <= asof) return 'done'; }
    else if (num(t.progress) >= 100) return 'done';
    if (!pe && !ps) return 'noplan';
    if (pe && pe < asof) return 'overdue';
    if (ps && ps <= asof) return 'doing';
    return 'pending';
}
function stateBadge(s) {
    var m = { done: ['已完成', 'st-approved'], overdue: ['逾期', 'st-rejected'],
              doing: ['進行中', 'st-submitted'], pending: ['未開始', 'st-draft'], noplan: ['未排程', 'st-draft'] };
    var x = m[s] || m.pending;
    return '<span class="st ' + x[1] + '">' + x[0] + '</span>';
}

function groupTasks(goals, tasks) {
    var out = [];
    $.each(goals, function (i, g) {
        out.push({ goal_id: num(g.goal_id), goal_name: g.goal_name, dept_name: g.dept_name,
                   tasks: $.grep(tasks, function (t) { return num(t.goal_id) === num(g.goal_id); }) });
    });
    var orphan = $.grep(tasks, function (t) { return !num(t.goal_id); });
    if (orphan.length) out.push({ goal_id: 0, goal_name: '（未歸類）', dept_name: '', tasks: orphan });
    return out;
}

function ganttRange(p, tasks) {
    var min = p.start_date || '', max = p.end_date || '';
    $.each(tasks, function (i, t) {
        $.each(['plan_start', 'plan_end', 'act_start', 'act_end'], function (j, k) {
            var v = t[k] || '';
            if (!v || v === '0000-00-00') return;
            if (!min || v < min) min = v;
            if (!max || v > max) max = v;
        });
    });
    return (min && max) ? { start: min, end: max } : null;
}
function dayDiff(a, b) { return (b - a) / 86400000; }

/* ── 規劃表編輯器（可增列表格：末列↓加列、空白末列↑移除＝共用檔規則） ── */
function drawPlanEditor(res) {
    var grouped = groupTasks(res.goals || [], res.tasks || []);
    var ownerOpt = '<option value="">（未指定）</option>';
    $.each(META.people || [], function (i, x) { ownerOpt += '<option value="' + x.id + '">' + esc(peopleLabel(x)) + '</option>'; });
    var deptOpt = '<option value="">（無）</option>';
    $.each(META.depts || [], function (i, x) { deptOpt += '<option value="' + x.id + '">' + esc(x.name) + '</option>'; });

    var h = '<div class="sec"><h5>編排目標與主要任務</h5>'
      + '<p class="pj-hint">末列按 <b>↓</b> 自動加一列、沒填東西的末列按 <b>↑</b> 自動移除；'
      + '任務改了日期，時間軸與管理卡的「目前應達成基準」都會跟著重算。</p>';
    if (!grouped.length) h += '<div class="pj-hint">還沒有目標，請按上方「新增目標」。</div>';
    $.each(grouped, function (gi, g) {
        h += '<div class="sec" data-goal="' + g.goal_id + '" data-gkey="g' + gi + '" style="background:#fff;">'
          + '<div class="grid3" style="margin-bottom:6px;">'
          + '<div style="grid-column:1 / 2;"><label>目標 ' + (gi + 1) + ' <span style="color:#DD5138;">*</span></label>'
          + '<input type="text" class="g-name" value="' + esc(g.goal_name) + '"></div>'
          + '<div><label>主辦單位</label><select class="g-dept">' + deptOpt + '</select></div>'
          + '<div style="display:flex;align-items:flex-end;gap:6px;">'
          + '<button class="g-del" style="height:30px;padding:0 12px;border:1px solid #C4442D;border-radius:4px;background:#DD5138;color:#fff;cursor:pointer;">刪除此目標</button></div>'
          + '</div>'
          + '<table class="sub-tbl"><thead><tr>'
          + '<th style="width:30px;">#</th><th>主要任務</th><th style="width:126px;">預計開始</th><th style="width:126px;">預計完成</th>'
          + '<th style="width:126px;">實際開始</th><th style="width:126px;">實際完成</th>'
          + '<th style="width:150px;">負責人</th><th style="width:66px;">進度%</th>'
          + '<th style="width:46px;">里程碑</th><th style="width:34px;"></th></tr></thead>'
          + '<tbody class="t-body" data-eg-row-add="planRowAdd" data-eg-row-del="planRowDel">';
        var list = g.tasks.length ? g.tasks : [{}];
        $.each(list, function (ti, t) { h += planRowHtml(t, ti, ownerOpt); });
        h += '</tbody></table></div>';
    });
    h += '</div>';
    $('#planEditBox').html(h);
    /* 部門/負責人下拉的選中值用 .val() 設，避免字串比對出錯 */
    $('#planEditBox .sec[data-goal]').each(function (gi) {
        var g = grouped[gi];
        if (!g) return;
        var $s = $(this).find('.g-dept');
        $s.find('option').each(function () { if ($(this).text() === g.dept_name) $s.val($(this).val()); });
        $(this).find('.t-body tr').each(function (ti) {
            var t = g.tasks[ti];
            if (t) $(this).find('.t-owner').val(t.owner_id || '');
        });
    });
}

function planRowHtml(t, i, ownerOpt) {
    t = t || {};
    return '<tr data-task="' + num(t.task_id) + '">'
      + '<td>' + (i + 1) + '</td>'
      + '<td><input type="text" class="t-name" value="' + esc(t.task_name || '') + '"></td>'
      + '<td><input type="date" class="t-ps" value="' + esc(t.plan_start || '') + '"></td>'
      + '<td><input type="date" class="t-pe" value="' + esc(t.plan_end || '') + '"></td>'
      + '<td><input type="date" class="t-as" value="' + esc(t.act_start || '') + '"></td>'
      + '<td><input type="date" class="t-ae" value="' + esc(t.act_end || '') + '"></td>'
      + '<td><select class="t-owner" data-eg-filter="輸入姓名篩選…">' + ownerOpt + '</select></td>'
      + '<td><input type="number" class="t-pg" min="0" max="100" value="' + num(t.progress) + '"></td>'
      + '<td><input type="checkbox" class="t-ms" data-eg-skip="1"' + (num(t.is_milestone) ? ' checked' : '') + '></td>'
      + '<td><span class="pj-op t-del" title="刪除這一列">✕</span></td></tr>';
}

/* 共用檔 eg_input_rules.js 的可增列表格掛勾（禁各頁自刻增刪列邏輯） */
function planRowAdd($tbody) {
    var ownerOpt = $tbody.find('.t-owner').first().html() || '';
    $tbody.append(planRowHtml({}, $tbody.find('tr').length, ownerOpt));
    renumberPlan($tbody);
    return $tbody.find('tr').last();
}
function planRowDel($tr) {
    var $tbody = $tr.closest('tbody');
    if ($tbody.find('tr').length <= 1) return false;   // 只剩一列時不刪
    $tr.remove();
    renumberPlan($tbody);
    return true;
}
function renumberPlan($tbody) { $tbody.find('tr').each(function (i) { $(this).find('td').first().text(i + 1); }); }

$(document).on('click', '#planEditBox .t-del', function () { planRowDel($(this).closest('tr')); });
$(document).on('click', '#planEditBox .g-del', function () {
    if (!confirm('刪除這個目標？底下的任務會一起移除（要按「儲存規劃表」才會真的寫入）。')) return;
    $(this).closest('.sec[data-goal]').remove();
});
$(document).on('change', '#gView', function () { GVIEW = $(this).val(); drawGantt(CUR); });
$(document).on('change', '#gScale', function () { GSCALE = $(this).val(); drawGantt(CUR); });
$(document).on('click', '#btnGoalAdd', function () {
    var res = $.extend(true, {}, CUR);
    res.goals = (res.goals || []).concat([{ goal_id: 0, goal_name: '', dept_name: '' }]);
    CUR.goals = res.goals;
    drawPlanEditor(CUR);
});
$(document).on('click', '#btnPlanSave', function () {
    var goals = [], tasks = [], bad = '';
    $('#planEditBox .sec[data-goal]').each(function (gi) {
        var gkey = 'g' + gi;
        var name = $.trim($(this).find('.g-name').val());
        if (!name) { bad = bad || '目標 ' + (gi + 1) + ' 沒有名稱'; return; }
        goals.push({ goal_key: gkey, goal_id: num($(this).data('goal')), goal_name: name, dept_id: $(this).find('.g-dept').val() });
        $(this).find('.t-body tr').each(function () {
            var tn = $.trim($(this).find('.t-name').val());
            if (!tn) return;   // 空白列直接略過（不是錯誤）
            var ps = $(this).find('.t-ps').val(), pe = $(this).find('.t-pe').val();
            var as = $(this).find('.t-as').val(), ae = $(this).find('.t-ae').val();
            if (ps && pe && ps > pe) bad = bad || ('任務「' + tn + '」的預計完成日早於預計開始日');
            if (as && ae && as > ae) bad = bad || ('任務「' + tn + '」的實際完成日早於實際開始日');
            tasks.push({
                goal_key: gkey, task_id: num($(this).data('task')), task_name: tn,
                plan_start: ps, plan_end: pe, act_start: as, act_end: ae,
                owner_id: $(this).find('.t-owner').val(), progress: $(this).find('.t-pg').val(),
                is_milestone: $(this).find('.t-ms').is(':checked') ? 1 : 0
            });
        });
    });
    if (bad) { alert(bad); return; }
    api('plan_save', { project_id: CUR.project.project_id, goals: JSON.stringify(goals), tasks: JSON.stringify(tasks) }, 'POST')
        .done(function (res) { alert(res.message); openProject(num(CUR.project.project_id)); loadList(); });
});

/* ══════════════════════════ 專案管理卡（2-GM-02-03） ══════════════════════════ */
function renderCards(res) {
    var p = res.project;
    if (!num(p.project_id)) { $('#paneCard').html('<div class="pj-hint" style="padding:14px;">請先儲存專案。</div>'); return; }
    var h = '<div class="pj-toolbar" style="margin-bottom:8px;">'
      + (res.can_edit ? '<label>檢討日期</label><input type="date" id="cdDate" value="' + esc(META.today) + '">'
                      + '<button class="btn-warm" id="btnCardNew"><i class="fa fa-plus"></i> 開新的管理卡</button>' : '')
      + '<span class="pj-hint" style="margin-left:8px;">目標／主辦單位／承辦人自動帶入，「目前應達成基準」由日程自動算出，只需填問題與後續辦法。</span>'
      + '</div>';
    if (!(res.cards || []).length) {
        h += '<div class="pj-hint" style="padding:14px;">還沒有管理卡。程序書 §6.10.1 要求依管理卡定期檢查各工作項目進度。</div>';
    } else {
        h += '<div class="pj-table-wrap"><table class="pj-table"><thead><tr>'
          + '<th style="width:120px;">管理卡編號</th><th style="width:110px;">檢討日期</th><th style="width:64px;">項次數</th>'
          + '<th style="width:88px;">狀態</th><th style="width:100px;">製表</th><th>操作</th></tr></thead><tbody>';
        $.each(res.cards, function (i, c) {
            h += '<tr><td><b>' + esc(c.card_no || '') + '</b></td>'
              + '<td>' + dispDate(c.review_date) + '</td><td>' + num(c.item_cnt) + '</td>'
              + '<td><span class="st st-' + esc(c.status) + '">' + esc(STATUS_LABEL[c.status] || c.status) + '</span></td>'
              + '<td>' + esc(c.created_by_name || '') + '</td>'
              + '<td><span class="pj-op" data-card="' + c.card_id + '">開啟</span>'
              + '<span class="pj-op" data-cardprint="' + c.card_id + '">列印</span>'
              + (PERM.canAdmin ? '<span class="pj-op" data-carddel="' + c.card_id + '" style="color:#DD5138;">刪除</span>' : '')
              + '</td></tr>';
        });
        h += '</tbody></table></div>';
    }
    h += '<div id="cardEditBox" style="margin-top:12px;"></div>';
    $('#paneCard').html(h);
}

$(document).on('click', '#btnCardNew', function () {
    var d = $('#cdDate').val() || META.today;
    if (!(CUR.goals || []).length) { alert('請先在「執行規劃表」建立至少一個目標，管理卡的項次是由目標帶入的。'); return; }
    api('card_create', { project_id: CUR.project.project_id, review_date: d }, 'POST').done(function (res) {
        alert(res.message);
        openProject(num(CUR.project.project_id));
        setTimeout(function () { $('.pj-tab[data-pane="paneCard"]').click(); openCard(num(res.card_id)); }, 300);
    });
});
$(document).on('click', '[data-card]', function () { openCard(num($(this).data('card'))); });
$(document).on('click', '[data-carddel]', function () {
    if (!confirm('確定刪除這張管理卡？')) return;
    api('card_delete', { card_id: num($(this).data('carddel')) }, 'POST')
        .done(function (r) { alert(r.message); openProject(num(CUR.project.project_id)); });
});
$(document).on('click', '[data-cardprint]', function () {
    api('card_get', { card_id: num($(this).data('cardprint')) }).done(function (res) { printCard(res); });
});

function openCard(cardId) {
    api('card_get', { card_id: cardId }).done(function (res) {
        var c = res.card, ro = res.can_edit ? '' : ' readonly';
        var h = '<div class="sec" data-card="' + c.card_id + '"><h5>管理卡 ' + esc(c.card_no || '') + '</h5>'
          + '<div class="grid3" style="margin-bottom:8px;">'
          + '<div><label>檢討日期</label><input type="date" id="ciDate" value="' + esc(c.review_date) + '"'
          + (res.can_edit ? '' : ' disabled') + '></div>'
          + '<div><label>狀態</label><input type="text" class="ro-auto" readonly value="'
          + esc(STATUS_LABEL[c.status] || c.status) + '"></div>'
          + '<div><label>製表</label><input type="text" class="ro-auto" readonly value="' + esc(c.created_by_name || '') + '"></div>'
          + '</div>'
          + '<div style="overflow-x:auto;"><table class="sub-tbl" id="cardItems"><thead><tr>'
          + '<th style="width:34px;">項次</th><th style="width:170px;">各項目標名稱</th>'
          + '<th style="width:96px;">主辦單位</th><th style="width:96px;">承辦人</th>'
          + '<th style="width:250px;">目前應達成基準</th><th>現階段問題</th><th>後續辦理方法</th>'
          + '<th style="width:110px;">備註</th><th style="width:58px;">依計畫<br>進行</th></tr></thead><tbody>';
        $.each(c.items || [], function (i, it) {
            h += '<tr data-item="' + it.item_id + '"><td>' + (i + 1) + '</td>'
              + '<td><input type="text" class="i-goal" value="' + esc(it.goal_name || '') + '"' + ro + '></td>'
              + '<td><input type="text" class="i-dept" value="' + esc(it.dept_name || '') + '"' + ro + '></td>'
              + '<td><input type="text" class="i-owner" value="' + esc(it.owner_name || '') + '"' + ro + '></td>'
              + '<td><textarea class="i-base" rows="2"' + ro + '>' + esc(it.baseline || '') + '</textarea>'
              + '<label style="font-size:11px;display:block;margin-top:2px;">'
              + '<input type="checkbox" class="i-auto" data-eg-skip="1"' + (num(it.baseline_auto) ? ' checked' : '')
              + (res.can_edit ? '' : ' disabled') + '> 跟著日程自動更新</label></td>'
              + '<td><textarea class="i-issue" rows="2"' + ro + '>' + esc(it.issue_text || '') + '</textarea></td>'
              + '<td><textarea class="i-follow" rows="2"' + ro + '>' + esc(it.follow_text || '') + '</textarea></td>'
              + '<td><input type="text" class="i-note" value="' + esc(it.note || '') + '"' + ro + '></td>'
              + '<td><input type="checkbox" class="i-ontrack" data-eg-skip="1"' + (num(it.on_track) ? ' checked' : '')
              + (res.can_edit ? '' : ' disabled') + '></td></tr>';
        });
        h += '</tbody></table></div>';
        if (res.can_edit) {
            h += '<div style="margin-top:8px;text-align:right;">'
              + '<button id="btnCardSave" style="height:30px;padding:0 14px;border:1px solid #d98a33;border-radius:4px;background:#F0A24B;color:#fff;cursor:pointer;">儲存</button>'
              + (c.status === 'draft'
                 ? ' <button id="btnCardSubmit" style="height:30px;padding:0 14px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;">送出（蓋章）</button>' : '')
              + ' <button id="btnCardPrint" style="height:30px;padding:0 14px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;">列印</button>'
              + '</div>';
        }
        h += '</div>';
        $('#cardEditBox').html(h).data('card', res);
    });
}

function collectCardItems() {
    var items = [];
    $('#cardItems tbody tr').each(function () {
        items.push({
            item_id: num($(this).data('item')),
            goal_name: $(this).find('.i-goal').val(), dept_name: $(this).find('.i-dept').val(),
            owner_name: $(this).find('.i-owner').val(), baseline: $(this).find('.i-base').val(),
            baseline_auto: $(this).find('.i-auto').is(':checked') ? 1 : 0,
            issue_text: $(this).find('.i-issue').val(), follow_text: $(this).find('.i-follow').val(),
            note: $(this).find('.i-note').val(),
            on_track: $(this).find('.i-ontrack').is(':checked') ? 1 : 0
        });
    });
    return items;
}
$(document).on('click', '#btnCardSave', function () {
    var cid = num($('#cardEditBox .sec').data('card'));
    api('card_save', { card_id: cid, review_date: $('#ciDate').val(), items: JSON.stringify(collectCardItems()) }, 'POST')
        .done(function (r) { alert(r.message); openCard(cid); });
});
$(document).on('click', '#btnCardSubmit', function () {
    var cid = num($('#cardEditBox .sec').data('card'));
    /* 先存再送，避免使用者剛填的內容還沒寫進去就被判定「沒交代」 */
    api('card_save', { card_id: cid, review_date: $('#ciDate').val(), items: JSON.stringify(collectCardItems()) }, 'POST')
        .done(function () {
            api('card_submit', { card_id: cid }, 'POST').done(function (r) {
                alert(r.message);
                openProject(num(CUR.project.project_id));
                setTimeout(function () { $('.pj-tab[data-pane="paneCard"]').click(); openCard(cid); }, 300);
            });
        });
});
$(document).on('click', '#btnCardPrint', function () {
    api('card_get', { card_id: num($('#cardEditBox .sec').data('card')) }).done(function (res) { printCard(res); });
});
/* 檢討日期改了就把自動列重算（推導欄位鐵則：來源一改就重算，不留舊值） */
$(document).on('change', '#ciDate', function () {
    var cid = num($('#cardEditBox .sec').data('card'));
    api('card_save', { card_id: cid, review_date: $(this).val(), items: JSON.stringify(collectCardItems()) }, 'POST')
        .done(function () { openCard(cid); });
});

/* ══════════════════════════ 關聯資料（訂單／料號／製程） ══════════════════════════ */
function renderRel(res) {
    var p = res.project;
    if (!num(p.project_id)) { $('#paneRel').html('<div class="pj-hint" style="padding:14px;">請先儲存專案。</div>'); return; }
    var h = '';

    /* 訂單 */
    h += '<div class="sec"><h5>訂單（專案主軸）'
      + (res.can_edit ? ' <button id="btnRelAddOrder" style="float:right;height:26px;padding:0 10px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;font-size:12px;">加入訂單</button>' : '')
      + '</h5>';
    if (!(res.orders || []).length) {
        h += '<div class="pj-hint">還沒有綁定訂單。開發型專案可以先在下方直接掛料號，之後訂單進來再用「訂單轉專案 → 加入既有專案」併進來。</div>';
    } else {
        h += '<div style="overflow-x:auto;"><table class="sub-tbl"><thead><tr>'
          + '<th>訂單編號</th><th>客戶單號</th><th>客戶</th><th>料號</th><th style="width:60px;">數量</th>'
          + '<th style="width:88px;">接單日</th><th style="width:88px;">交期</th><th style="width:70px;">狀態</th>'
          + '<th style="width:90px;">報價單</th>' + (res.can_edit ? '<th style="width:50px;"></th>' : '') + '</tr></thead><tbody>';
        $.each(res.orders, function (i, o) {
            h += '<tr><td>' + esc(o.Order_oo) + '</td><td>' + esc(o.C_order || '') + '</td>'
              + '<td>' + esc(o.Client_name || '') + '</td><td>' + esc(o.master_part_no || o.part_no) + '</td>'
              + '<td>' + num(o.Qty) + '</td><td>' + dispDate(o.Order_date) + '</td><td>' + dispDate(o.Delivery_date) + '</td>'
              + '<td>' + esc(o.status_label) + '</td>'
              + '<td>' + (o.quote_no ? esc(o.quote_no) : '－') + '</td>'
              + (res.can_edit ? '<td><span class="pj-op" data-unlink="' + o.Order_id + '" style="color:#DD5138;">移出</span></td>' : '')
              + '</tr>';
        });
        h += '</tbody></table></div>';
    }
    h += '</div>';

    /* 料號 */
    h += '<div class="sec"><h5>料號（由訂單自動帶出，可手動補掛）</h5>';
    if (res.can_edit) {
        h += '<div style="display:flex;gap:6px;margin-bottom:8px;align-items:flex-end;">'
          + '<div style="flex:1;"><label>搜尋料號加入</label>'
          + '<input type="text" id="partKw" placeholder="輸入料號或圖號關鍵字後按 Enter"></div></div>'
          + '<div id="partFound"></div>';
    }
    if (!(res.parts || []).length) {
        h += '<div class="pj-hint">尚無料號。</div>';
    } else {
        h += '<div style="overflow-x:auto;"><table class="sub-tbl"><thead><tr>'
          + '<th>料號</th><th>圖號</th><th>規格</th><th style="width:60px;">版次</th><th style="width:100px;">客戶</th>'
          + '<th style="width:80px;">來源</th>' + (res.can_edit ? '<th style="width:50px;"></th>' : '') + '</tr></thead><tbody>';
        $.each(res.parts, function (i, x) {
            h += '<tr><td><b>' + esc(x.part_no) + '</b>' + (num(x.Is_Assembly) ? ' <span class="pj-hint">(組合件)</span>' : '') + '</td>'
              + '<td>' + esc(x.Drawing_No || '') + '</td><td>' + esc(x.Spec_No || '') + '</td>'
              + '<td>' + esc(x.Revision || '') + '</td><td>' + esc(x.customer_name || '') + '</td>'
              + '<td>' + (x.source === 'order' ? '訂單帶出' : '手動') + '</td>'
              + (res.can_edit ? '<td>' + (x.source === 'manual'
                    ? '<span class="pj-op" data-partdel="' + x.ds_pk + '" style="color:#DD5138;">移除</span>'
                    : '<span class="pj-hint" title="要移除請改移出對應訂單">－</span>') + '</td>' : '')
              + '</tr>';
        });
        h += '</tbody></table></div>';
    }
    h += '</div>';

    /* 製程（BOM） */
    h += '<div class="sec"><h5>製程（由已開立的 BOM 製令自動帶入）'
      + (res.can_edit ? ' <button id="btnBomSync" style="float:right;height:26px;padding:0 10px;border:1px solid #d98a33;border-radius:4px;background:#F0A24B;color:#fff;cursor:pointer;font-size:12px;">同步 BOM</button>' : '')
      + '</h5>';
    if (!(res.processes || []).length) {
        h += '<div class="pj-hint">這些訂單還沒有開立 BOM 製令，或 BOM 沒有對應到本專案的訂單。BOM 一開立就會自動帶進來。</div>';
    } else {
        h += '<div style="overflow-x:auto;"><table class="sub-tbl"><thead><tr>'
          + '<th style="width:110px;">製令單</th><th>料號</th><th style="width:52px;">順序</th><th>製程</th>'
          + '<th style="width:110px;">廠商</th><th style="width:60px;">發包數</th>'
          + '<th style="width:88px;">發包日</th><th style="width:88px;">回廠日</th>'
          + '<th style="width:64px;">檢驗</th><th style="width:44px;">里程碑</th><th>專案註記</th></tr></thead><tbody>';
        var lastBom = '';
        $.each(res.processes, function (i, x) {
            var show = (x.bom !== lastBom); lastBom = x.bom;
            h += '<tr data-proc="' + x.id + '"><td>' + (show ? '<b>' + esc(x.bom) + '</b>' : '') + '</td>'
              + '<td>' + esc(x.part_no || '') + '</td><td>' + num(x.bom_sn) + '</td>'
              + '<td>' + esc(x.process_name || ('製程' + num(x.process_no))) + '</td>'
              + '<td>' + esc(x.maker_name || '－') + '</td><td>' + num(x.sqty) + '</td>'
              + '<td>' + dispDate(x.outsource_date) + '</td><td>' + dispDate(x.return_date) + '</td>'
              + '<td>' + qcLabel(x.qc_check) + '</td>'
              + '<td><input type="checkbox" class="p-ms" data-eg-skip="1"' + (num(x.is_milestone) ? ' checked' : '')
              + (res.can_edit ? '' : ' disabled') + '></td>'
              + '<td><input type="text" class="p-note" value="' + esc(x.note || '') + '"' + (res.can_edit ? '' : ' readonly') + '></td>'
              + '</tr>';
        });
        h += '</tbody></table></div>'
          + '<p class="pj-hint">本頁只讀 BOM，不會改動 BOM 任何資料；你在這裡加的註記與里程碑同步時不會被覆蓋。</p>';
    }
    h += '</div>';
    $('#paneRel').html(h);
}

function qcLabel(q) {
    var m = { ok: '允收', ng: '驗退', QQ: '異常', AOD: '特採' };
    if (!q) return '－';
    var cls = (q === 'ng' || q === 'QQ') ? 'st-rejected' : (q === 'ok' ? 'st-approved' : 'st-submitted');
    return '<span class="st ' + cls + '">' + esc(m[q] || q) + '</span>';
}

$(document).on('click', '[data-unlink]', function () {
    if (!confirm('把這張訂單移出專案？（由它帶進來的料號也會跟著退場）')) return;
    api('order_unlink', { project_id: CUR.project.project_id, order_id: num($(this).data('unlink')) }, 'POST')
        .done(function (r) { alert(r.message); openProject(num(CUR.project.project_id)); loadList(); });
});
$(document).on('click', '[data-partdel]', function () {
    api('part_remove', { project_id: CUR.project.project_id, ds_pk: num($(this).data('partdel')) }, 'POST')
        .done(function (r) { alert(r.message); openProject(num(CUR.project.project_id)); });
});
$(document).on('keydown', '#partKw', function (e) {
    if (e.which !== 13) return;
    e.preventDefault();
    var kw = $.trim($(this).val());
    if (!kw) return;
    api('part_search', { kw: kw }).done(function (res) {
        if (!(res.rows || []).length) { $('#partFound').html('<div class="pj-hint">找不到符合的料號</div>'); return; }
        var h = '<table class="sub-tbl"><thead><tr><th>料號</th><th>規格</th><th>客戶</th><th style="width:60px;"></th></tr></thead><tbody>';
        $.each(res.rows, function (i, x) {
            h += '<tr><td>' + esc(x.part_no) + '</td><td>' + esc(x.Spec_No || '') + '</td>'
              + '<td>' + esc(x.customer_name || '') + '</td>'
              + '<td><span class="pj-op" data-partadd="' + x.ds_pk + '">加入</span></td></tr>';
        });
        $('#partFound').html(h + '</tbody></table>');
    });
});
$(document).on('click', '[data-partadd]', function () {
    api('part_add', { project_id: CUR.project.project_id, ds_pk: num($(this).data('partadd')) }, 'POST')
        .done(function (r) { alert(r.message); openProject(num(CUR.project.project_id)); });
});
$(document).on('click', '#btnBomSync', function () {
    api('bom_sync', { project_id: CUR.project.project_id }, 'POST').done(function (r) {
        alert(r.message); openProject(num(CUR.project.project_id)); loadList();
    });
});
$(document).on('change', '#paneRel .p-ms, #paneRel .p-note', function () {
    var $tr = $(this).closest('tr');
    api('process_note', {
        project_id: CUR.project.project_id, id: num($tr.data('proc')),
        note: $tr.find('.p-note').val(), is_milestone: $tr.find('.p-ms').is(':checked') ? 1 : 0
    }, 'POST');
});
$(document).on('click', '#btnRelAddOrder', function () { openO2P('append'); });

/* ══════════════════════════ 文件檢核 ══════════════════════════ */
function renderCheck(res) {
    var rows = res.doc_check || [], defs = META.doc_checks || {};
    var totalMiss = 0;
    $.each(rows, function (i, r) { totalMiss += num(r.missing); });
    $('#chkBadge').html(totalMiss > 0 ? '<span class="pj-miss-badge">' + totalMiss + '</span>' : '');

    if (!rows.length) {
        $('#paneChk').html('<div class="pj-hint" style="padding:14px;">專案還沒有料號，無法檢核。請先綁定訂單或手動掛料號。</div>');
        return;
    }
    var h = '<p class="pj-hint">每個料號都應該有這四份技術文件。<b>✗ 可以直接點下去開啟對應頁面並帶入該料號</b>；'
          + '那四個頁面自己的「建議建立清單／缺件偵測」也會列出「有專案但未建立」的料號。</p>'
          + '<div class="pj-table-wrap"><table class="pj-table"><thead><tr><th>料號</th><th style="width:110px;">客戶</th>';
    $.each(defs, function (k, d) { h += '<th style="width:130px;">' + esc(d[0]) + '</th>'; });
    h += '<th style="width:64px;">缺件</th></tr></thead><tbody>';
    $.each(rows, function (i, r) {
        h += '<tr><td class="l"><b>' + esc(r.part_no) + '</b></td><td>' + esc(r.customer_name || '') + '</td>';
        $.each(defs, function (k, d) {
            h += '<td>' + (num(r[k])
                ? '<span class="chk-y">✓ 已建立</span>'
                : '<span class="chk-n" data-go="' + esc(d[1]) + '" data-kw="' + esc(r.part_no) + '">✗ 未建立</span>') + '</td>';
        });
        h += '<td>' + (num(r.missing) ? '<span class="pj-miss-badge">' + num(r.missing) + '</span>' : '<span class="pj-ok-badge">齊全</span>') + '</td></tr>';
    });
    h += '</tbody></table></div>';
    $('#paneChk').html(h);
}
$(document).on('click', '.chk-n', function () {
    window.open($(this).data('go') + '?kw=' + encodeURIComponent($(this).data('kw')), '_blank');
});

/* ══════════════════════════ 會簽／核准 ══════════════════════════ */
function renderSign(res) {
    var p = res.project;
    if (!num(p.project_id)) { $('#paneSign').html('<div class="pj-hint" style="padding:14px;">請先儲存專案。</div>'); return; }
    var h = '<div class="sec"><h5>目前狀態</h5><div class="grid3">'
      + '<div><label>狀態</label><div><span class="st st-' + esc(p.status) + '">' + esc(STATUS_LABEL[p.status] || p.status) + '</span></div></div>'
      + '<div><label>送簽日期</label><div>' + (p.submit_date ? dispDate(p.submit_date) : '－') + '</div></div>'
      + '<div><label>核准</label><div>' + (p.approved_date ? (esc(p.approver_name || '') + '　' + dispDate(p.approved_date)) : '－') + '</div></div>'
      + '</div>'
      + (p.decide_note ? '<div style="margin-top:8px;color:#DD5138;"><b>退回原因：</b>' + esc(p.decide_note) + '</div>' : '')
      + '</div>';

    if ((p.status === 'draft' || p.status === 'rejected') && res.can_edit) {
        h += '<div class="sec"><h5>送簽：選擇會簽單位</h5>'
          + '<p class="pj-hint">會簽人＝該部門主管，系統會自動套用<b>代理人</b>（代理人簽的章右下角會加「代」字）。</p>'
          + '<div id="cosignPick" style="display:flex;flex-wrap:wrap;gap:6px;"></div></div>';
    }
    if ((res.cosigns || []).length) {
        h += '<div class="sec"><h5>會簽紀錄</h5><table class="sub-tbl"><thead><tr>'
          + '<th style="width:110px;">會簽單位</th><th style="width:100px;">會簽人</th><th style="width:70px;">結果</th>'
          + '<th>審查意見</th><th style="width:100px;">簽署日期</th><th style="width:80px;"></th></tr></thead><tbody>';
        $.each(res.cosigns, function (i, c) {
            var mine = num(c.user_id) === num(PERM.uid) && !c.signed_at;
            h += '<tr><td>' + esc(c.dept_name || '') + '</td>'
              + '<td>' + esc(c.user_name || '－') + (num(c.is_delegate) ? ' <span class="pj-hint">(代)</span>' : '') + '</td>'
              + '<td>' + (c.result ? '<span class="st ' + (c.result === 'agree' ? 'st-approved' : 'st-rejected') + '">'
                        + (c.result === 'agree' ? '同意' : '不同意') + '</span>' : '<span class="st st-draft">待會簽</span>') + '</td>'
              + '<td class="l">' + esc(c.opinion || '') + '</td>'
              + '<td>' + (c.signed_date ? dispDate(c.signed_date) : '－') + '</td>'
              + '<td>' + (mine ? '<span class="pj-op" data-cosign="' + c.id + '">我要會簽</span>' : '') + '</td></tr>';
        });
        h += '</tbody></table></div>';
    }
    $('#paneSign').html(h);

    if ($('#cosignPick').length) {
        var def = (META.default_cosign_depts || '').split(',');
        var hh = '';
        $.each(META.depts || [], function (i, d) {
            var on = $.inArray(String(d.id), def) >= 0;
            hh += '<span class="pj-tag' + (on ? ' on' : '') + '" data-cos="' + d.id + '">' + esc(d.name) + '</span>';
        });
        $('#cosignPick').html(hh);
    }
}
$(document).on('click', '#cosignPick .pj-tag', function () { $(this).toggleClass('on'); });

$(document).on('click', '#btnSubmit', function () {
    var depts = [];
    $('#cosignPick .pj-tag.on').each(function () { depts.push(num($(this).data('cos'))); });
    if (!depts.length && !confirm('沒有選擇任何會簽單位，確定直接送簽？')) return;
    api('submit', { project_id: CUR.project.project_id, cosign_depts: depts.join(',') }, 'POST')
        .done(function (r) { alert(r.message); openProject(num(CUR.project.project_id)); loadList(); })
        .fail(function (xhr) {
            try { showFieldErrors((JSON.parse(xhr.responseText) || {}).fields); } catch (e) { /* ajaxError 已提示 */ }
        });
});
$(document).on('click', '[data-cosign]', function () {
    var id = num($(this).data('cosign'));
    var h = '<div class="sec"><h5>會簽</h5>'
      + '<label>結果 <span style="color:#DD5138;">*</span>（<b>要先選同意或不同意才能填意見</b>）</label>'
      + '<div style="margin:4px 0 10px;">'
      + '<label style="display:inline;margin-right:16px;"><input type="radio" name="cosRes" value="agree" data-eg-skip="1"> 同意</label>'
      + '<label style="display:inline;"><input type="radio" name="cosRes" value="disagree" data-eg-skip="1"> 不同意</label></div>'
      + '<label>審查意見（非必填）</label><textarea id="cosOpinion" rows="4" disabled placeholder="請先選擇同意／不同意"></textarea>'
      + '</div>';
    showDialog('會簽', h, function () {
        var r = $('input[name=cosRes]:checked').val();
        if (!r) { alert('請先選擇同意或不同意'); return false; }
        api('cosign_save', { cosign_id: id, result: r, opinion: $('#cosOpinion').val() }, 'POST')
            .done(function (res) { alert(res.message); closeMask('dlgMask'); openProject(num(CUR.project.project_id)); });
        return false;
    });
});
$(document).on('change', 'input[name=cosRes]', function () { $('#cosOpinion').prop('disabled', false).attr('placeholder', ''); });

$(document).on('click', '#btnApprove', function () {
    var h = '<div class="sec"><label>核准日期</label><input type="date" id="apDate" value="' + esc(META.today) + '">'
          + '<label style="margin-top:8px;">備註（非必填）</label><textarea id="apNote" rows="3"></textarea></div>';
    showDialog('核准專案', h, function () {
        doDecide('approve', $('#apDate').val(), $('#apNote').val(), 0);
        return false;
    });
});
$(document).on('click', '#btnReject', function () {
    var h = '<div class="sec"><label>退回原因 <span style="color:#DD5138;">*</span></label>'
          + '<textarea id="apNote" rows="4" placeholder="請說明退回原因"></textarea><div class="pj-err" id="apErr"></div></div>';
    showDialog('退回專案', h, function () {
        if (!$.trim($('#apNote').val())) { $('#apErr').text('退回一定要填原因').show(); return false; }
        doDecide('reject', '', $('#apNote').val(), 0);
        return false;
    });
});
function doDecide(dec, date, note, force) {
    api('decide', { project_id: CUR.project.project_id, decision: dec, approved_date: date, note: note, force: force }, 'POST')
        .done(function (r) { alert(r.message); closeMask('dlgMask'); openProject(num(CUR.project.project_id)); loadList(); })
        .fail(function (xhr) {
            var j = {};
            try { j = JSON.parse(xhr.responseText) || {}; } catch (e) { return; }
            if (j.need_force && confirm(j.error)) doDecide(dec, date, note, 1);
        });
}

$(document).on('click', '#btnClose', function () {
    var h = '<div class="sec"><label>結案日期</label><input type="date" id="clDate" value="' + esc(META.today) + '">'
          + '<label style="margin-top:8px;">專案總結報告 <span style="color:#DD5138;">*</span></label>'
          + '<textarea id="clSummary" rows="6" placeholder="程序書 §6.11.1 A：專案小組彙整專案總結報告呈總經理，並在管理審查會議上提報"></textarea>'
          + '<div class="pj-err" id="clErr"></div></div>';
    showDialog('專案結案', h, function () {
        if (!$.trim($('#clSummary').val())) { $('#clErr').text('請填寫專案總結報告').show(); return false; }
        doClose($('#clDate').val(), $('#clSummary').val(), 0);
        return false;
    });
});
function doClose(date, summary, force) {
    api('close', { project_id: CUR.project.project_id, close_date: date, close_summary: summary, force: force }, 'POST')
        .done(function (r) { alert(r.message); closeMask('dlgMask'); openProject(num(CUR.project.project_id)); loadList(); })
        .fail(function (xhr) {
            var j = {};
            try { j = JSON.parse(xhr.responseText) || {}; } catch (e) { return; }
            if (j.need_force) {
                if (confirm(j.error + '\n\n你是管理員，要強制結案嗎？')) doClose(date, summary, 1);
            } else if (j.error) {
                alert(j.error);
            }
        });
}

/* 通用小跳窗（動態建立，避免每個動作都在 HTML 裡放一個空殼） */
function showDialog(title, bodyHtml, onOk) {
    if (!$('#dlgMask').length) {
        $('body').append('<div class="pj-mask" id="dlgMask"><div class="pj-modal mid">'
            + '<div class="m-head"><span id="dlgTitle"></span><span class="m-close" onclick="closeMask(\'dlgMask\')">✕</span></div>'
            + '<div class="m-body" id="dlgBody"></div>'
            + '<div class="m-foot"><button onclick="closeMask(\'dlgMask\')">取消</button>'
            + '<button class="b-ok" id="dlgOk">確定</button></div></div></div>');
    }
    $('#dlgTitle').text(title);
    $('#dlgBody').html(bodyHtml);
    $('#dlgOk').off('click').on('click', function () { if (onOk() !== false) closeMask('dlgMask'); });
    openMask('dlgMask');
}

/* ══════════════════════════ 訂單轉專案 ══════════════════════════ */
function openO2P(forceMode) {
    var typeOpt = '';
    $.each(META.types || {}, function (k, v) { typeOpt += '<option value="' + k + '"' + (k === 'C' ? ' selected' : '') + '>' + esc(v + '型（' + k + '）') + '</option>'; });
    $('#o2pType').html(typeOpt);
    /* 專案負責人只列合格的人（模組設定 → 專案負責人資格）；沒設定時 owner_people＝全體 */
    var ownerOpt = '';
    $.each(ownerPeople(), function (i, x) {
        ownerOpt += '<option value="' + x.id + '"' + (num(x.id) === num(PERM.uid) ? ' selected' : '') + '>' + esc(peopleLabel(x)) + '</option>';
    });
    $('#o2pOwner').html(ownerOpt || '<option value="">（沒有符合資格的人員，請洽管理員設定）</option>');
    $('#oCust').val('');   /* 客戶改為模糊輸入（客戶ID或名稱），不再提供下拉 */
    renderTagPick('o2pTagBar', 'project', [], true);
    $('#oBody').html('<tr><td colspan="9" style="padding:12px;color:#8a6d45;">請先按「查詢」</td></tr>');
    $('#oCount').text('');

    /* 從專案詳情按「加入訂單」進來時，直接鎖定 append 模式並選好目標專案 */
    var isAppend = (forceMode === 'append');
    $('input[name=o2pMode][value=' + (isAppend ? 'append' : 'new') + ']').prop('checked', true).trigger('change');
    var prjOpt = '';
    $.each(LIST, function (i, r) {
        if (r.status === 'closed' || r.status === 'terminated') return;
        prjOpt += '<option value="' + r.project_id + '"' + (CUR && num(CUR.project.project_id) === num(r.project_id) ? ' selected' : '') + '>'
                + esc(r.project_no + '　' + r.project_name) + '</option>';
    });
    $('#o2pPrj').html(prjOpt || '<option value="">（沒有可加入的專案）</option>');
    openMask('o2pMask');
}
$(document).on('change', 'input[name=o2pMode]', function () {
    var m = $('input[name=o2pMode]:checked').val();
    $('#o2pNewBox').toggle(m === 'new');
    $('#o2pAppendBox').toggle(m === 'append');
});
$(document).on('click', '#btnOSearch', function () {
    $('#oBody').html('<tr><td colspan="9" style="padding:12px;color:#8a6d45;">查詢中…</td></tr>');
    api('order_candidates', {
        kw: $('#oKw').val(), cust: $('#oCust').val(), from: $('#oFrom').val(), to: $('#oTo').val(),
        include_closed: $('#oClosed').is(':checked') ? 1 : 0
    }).done(function (res) {
        var rows = res.rows || [];
        $('#oCount').text('找到 ' + rows.length + ' 張未綁定的訂單' + (rows.length >= 500 ? '（僅顯示前 500 張，請縮小條件）' : ''));
        if (!rows.length) { $('#oBody').html('<tr><td colspan="9" style="padding:12px;color:#8a6d45;">沒有符合條件的訂單</td></tr>'); return; }
        var h = '';
        $.each(rows, function (i, o) {
            h += '<tr><td><input type="checkbox" class="o-ck" value="' + o.Order_id + '" data-eg-skip="1"></td>'
              + '<td>' + esc(o.Order_oo) + '</td><td>' + esc(o.C_order || '') + '</td>'
              + '<td>' + esc(o.Client_name || '') + '</td><td>' + esc(o.part_no) + '</td>'
              + '<td>' + num(o.Qty) + '</td><td>' + dispDate(o.Order_date) + '</td>'
              + '<td>' + dispDate(o.Delivery_date) + '</td><td>' + esc(o.Processing_items || '') + '</td></tr>';
        });
        $('#oBody').html(h);
    });
});
$(document).on('change', '#oCkAll', function () { $('.o-ck').prop('checked', $(this).is(':checked')); });
$(document).on('click', '#btnO2pGo', function () {
    var ids = [];
    $('.o-ck:checked').each(function () { ids.push(num($(this).val())); });
    if (!ids.length) { alert('請至少勾選一張訂單'); return; }
    var mode = $('input[name=o2pMode]:checked').val();
    var d = { mode: mode, order_ids: ids.join(',') };
    if (mode === 'append') {
        d.project_id = num($('#o2pPrj').val());
        if (!d.project_id) { alert('請選擇要加入的專案'); return; }
    } else {
        d.project_type = $('#o2pType').val();
        d.project_name = $.trim($('#o2pName').val());
        d.owner_id = $('#o2pOwner').val();
        d.tag_ids = pickedTags('o2pTagBar');
    }
    api('order_to_project', d, 'POST').done(function (res) {
        alert(res.message);
        closeMask('o2pMask');
        loadList();
        openProject(num(res.project_id));
    });
});

/* ══════════════════════════ 常用語句（專案目的／專案目標） ══════════════════════════ */
/* PH.target＝要帶入的 textarea id；由工具列打開時為空＝只做維護、不顯示「帶入」 */
var PH = { field: 'purpose', target: '', editId: 0, rows: [] };

function phraseLabel(fk) { return (META.phrase_fields || {})[fk] || fk; }

function openPhrase(fieldKey, targetId) {
    PH.field = (META.phrase_fields || {})[fieldKey] ? fieldKey : 'purpose';
    PH.target = targetId || '';
    var opt = '';
    $.each(META.phrase_fields || {}, function (k, v) {
        opt += '<option value="' + k + '"' + (k === PH.field ? ' selected' : '') + '>' + esc(v) + '</option>';
    });
    $('#phField').html(opt).prop('disabled', !!PH.target);   /* 從欄位點進來就鎖定該欄位，避免帶錯欄 */
    $('#phFieldHint').toggle(!!PH.target);
    $('#phEditBox').toggle(!!PERM.canEdit);
    phraseResetForm();
    loadPhrase();
    openMask('phMask');
}

function loadPhrase() {
    api('phrase_list', { field_key: PH.field }).done(function (res) { renderPhrase(res.rows || []); });
}

function renderPhrase(rows) {
    PH.rows = rows || [];
    var h = '';
    if (!PH.rows.length) {
        h = '<tr><td colspan="2" style="padding:12px;color:#8a6d45;">'
          + '尚未建立「' + esc(phraseLabel(PH.field)) + '」的常用語句'
          + (PERM.canEdit ? '（可在下方新增）' : '') + '</td></tr>';
    }
    $.each(PH.rows, function (i, r) {
        h += '<tr><td class="l" style="text-align:left;white-space:pre-wrap;">' + esc(r.phrase_text) + '</td><td>'
          + (PH.target ? '<span class="pj-op" data-phuse="' + r.phrase_id + '"><i class="fa fa-check"></i> 帶入</span>' : '')
          + (PERM.canEdit
              ? '<span class="pj-op" data-phedit="' + r.phrase_id + '">修改</span>'
                + '<span class="pj-op" data-phdel="' + r.phrase_id + '" style="color:#DD5138;">刪除</span>'
              : '')
          + '</td></tr>';
    });
    $('#phTitle').text(phraseLabel(PH.field));
    $('#phBody').html(h);
}

function phraseResetForm() {
    PH.editId = 0;
    $('#phText').val('');
    $('#phFormTitle').text('新增語句');
    $('#phSave').text('新增');
    $('#phCancel').hide();
    $('#phErr').hide().text('');
    $('#phText').removeClass('fld-bad');
}

/* 前端即時驗證（表單三總則③；後端 phrase_save 同規則再擋一次＝鐵律8） */
function phraseCheck(silent) {
    var t = $.trim($('#phText').val()), msg = '';
    if (!t) msg = '請填語句內容';
    else if (t.length > 500) msg = '語句最多 500 字（目前 ' + t.length + ' 字）';
    if (msg && !(silent && !$('#phText').val())) {
        $('#phErr').text(msg).show(); $('#phText').addClass('fld-bad');
    } else { $('#phErr').hide().text(''); $('#phText').removeClass('fld-bad'); }
    return msg ? '' : t;
}
$(document).on('input', '#phText', function () { phraseCheck(true); });

$(document).on('click', '[data-phrase]', function () {
    openPhrase($(this).data('phrase'), $(this).data('phtarget'));
});
$(document).on('change', '#phField', function () {
    PH.field = $(this).val(); phraseResetForm(); loadPhrase();
});
$(document).on('click', '[data-phuse]', function () {
    var id = num($(this).data('phuse')), row = null;
    $.each(PH.rows, function (i, r) { if (num(r.phrase_id) === id) row = r; });
    if (!row || !PH.target) return;
    var $t = $('#' + PH.target);
    if (!$t.length) return;
    var cur = $.trim($t.val());
    if (cur && cur !== row.phrase_text && !confirm('這個欄位已經有內容，要用選取的語句取代嗎？\n（按「取消」則改為接在原內容後面另起一行）')) {
        $t.val($t.val().replace(/\s+$/, '') + '\n' + row.phrase_text);
    } else {
        $t.val(row.phrase_text);
    }
    closeMask('phMask');
    $t.focus();
});
$(document).on('click', '[data-phedit]', function () {
    var id = num($(this).data('phedit')), row = null;
    $.each(PH.rows, function (i, r) { if (num(r.phrase_id) === id) row = r; });
    if (!row) return;
    PH.editId = id;
    $('#phText').val(row.phrase_text);
    $('#phFormTitle').text('修改語句');
    $('#phSave').text('儲存');
    $('#phCancel').show();
    $('#phErr').hide().text('');
    $('#phText').focus();
});
$(document).on('click', '#phCancel', function () { phraseResetForm(); });
$(document).on('click', '#phSave', function () {
    var t = phraseCheck(false);
    if (!t) { $('#phText').focus(); return; }
    api('phrase_save', { phrase_id: PH.editId, field_key: PH.field, phrase_text: t }, 'POST')
        .done(function (res) { phraseResetForm(); renderPhrase(res.rows || []); });
});
$(document).on('click', '[data-phdel]', function () {
    if (!confirm('刪除這句常用語句？（已經填進專案的文字不受影響）')) return;
    api('phrase_delete', { phrase_id: num($(this).data('phdel')) }, 'POST')
        .done(function (res) { phraseResetForm(); renderPhrase(res.rows || []); });
});

/* ══════════════════════════ 標籤設定 ══════════════════════════ */
function openTagSetting() {
    var kindOpt = '';
    $.each(META.tag_kinds || {}, function (k, v) { kindOpt += '<option value="' + k + '">' + esc(v) + '</option>'; });
    $('#tgKind').html(kindOpt);
    var colorOpt = '';
    $.each(WARM_COLORS, function (i, c) { colorOpt += '<option value="' + c[0] + '">' + esc(c[1]) + '（' + c[0] + '）</option>'; });
    $('#tgColor').html(colorOpt);
    loadTagTable();
    openMask('tagMask');
}
function loadTagTable() {
    api('tag_list').done(function (res) {
        var h = '';
        if (!(res.rows || []).length) h = '<tr><td colspan="5" style="padding:12px;color:#8a6d45;">尚未建立任何標籤</td></tr>';
        $.each(res.rows || [], function (i, t) {
            h += '<tr><td>' + esc((META.tag_kinds || {})[t.tag_kind] || t.tag_kind) + '</td>'
              + '<td class="l">' + esc(t.tag_name) + '</td>'
              + '<td><span class="pj-tag ro" style="background:' + esc(t.color || '#FBF3E6') + ';color:#fff;">' + esc(t.tag_name) + '</span></td>'
              + '<td>' + (num(t.is_active) ? '啟用' : '停用') + '</td>'
              + '<td><span class="pj-op" data-tgtoggle="' + t.tag_id + '" data-on="' + num(t.is_active) + '" data-kind="' + esc(t.tag_kind) + '" data-name="' + esc(t.tag_name) + '" data-color="' + esc(t.color || '') + '">'
              + (num(t.is_active) ? '停用' : '啟用') + '</span>'
              + '<span class="pj-op" data-tgdel="' + t.tag_id + '" style="color:#DD5138;">刪除</span></td></tr>';
        });
        $('#tagBody').html(h);
    });
}
$(document).on('click', '#btnTagAdd', function () {
    var name = $.trim($('#tgName').val());
    if (!name) { $('#tgErr').text('請填標籤名稱').show(); return; }
    $('#tgErr').hide();
    api('tag_save', { tag_kind: $('#tgKind').val(), tag_name: name, color: $('#tgColor').val(), is_active: 1 }, 'POST')
        .done(function (res) {
            $('#tgName').val('');
            META.tags = res.rows || META.tags;
            loadTagTable(); renderTagFilter();
        });
});
$(document).on('click', '[data-tgtoggle]', function () {
    var $t = $(this);
    api('tag_save', {
        tag_id: num($t.data('tgtoggle')), tag_kind: $t.data('kind'), tag_name: $t.data('name'),
        color: $t.data('color'), is_active: num($t.data('on')) ? 0 : 1
    }, 'POST').done(function (res) { META.tags = res.rows || META.tags; loadTagTable(); renderTagFilter(); });
});
$(document).on('click', '[data-tgdel]', function () {
    if (!confirm('刪除這個標籤？（若已被使用會自動改為停用，既有資料保留）')) return;
    api('tag_delete', { tag_id: num($(this).data('tgdel')) }, 'POST').done(function (res) {
        alert(res.message);
        if (res.rows) META.tags = res.rows;
        loadTagTable(); renderTagFilter();
    });
});

/* ══════════════════════════ 模組設定 ══════════════════════════ */
function openSetting() {
    api('setting_get').done(function (res) {
        var s = res.setting || {};
        var uOpt = '<option value="0">（不指定）</option>';
        $.each(META.people || [], function (i, x) { uOpt += '<option value="' + x.id + '">' + esc(peopleLabel(x)) + '</option>'; });
        $('#setApUser').html(uOpt).val(s.approver_user_id || '0');
        var dOpt = '<option value="0">（不指定）</option>';
        $.each(META.depts || [], function (i, x) { dOpt += '<option value="' + x.id + '">' + esc(x.name) + '</option>'; });
        $('#setApDept').html(dOpt).val(s.approver_dept_id || '0');
        $('#setBlockClose').prop('checked', String(s.block_close_on_missing) === '1');

        var def = String(s.default_cosign_depts || '').split(',');
        var hh = '';
        $.each(META.depts || [], function (i, d) {
            var on = $.inArray(String(d.id), def) >= 0;
            hh += '<span class="pj-tag' + (on ? ' on' : '') + '" data-setcos="' + d.id + '">' + esc(d.name) + '</span>';
        });
        $('#setCosignBox').html(hh);

        /* 圖章模板下拉一定要先填好選項並帶回目前值，否則按「儲存設定」會把已設好的模板洗成 0 */
        var tOpt = '<option value="0">（預設圖章）</option>';
        $.each(META.stamp_tpls || [], function (i, x) { tOpt += '<option value="' + x.id + '">' + esc(x.tpl_name) + '</option>'; });
        $('#setPlanTpl').html(tOpt).val(s.plan_stamp_tpl_id || '0');
        $('#setCardTpl').html(tOpt).val(s.card_stamp_tpl_id || '0');

        /* 專案負責人資格（部門×職稱） */
        var odOpt = '<option value="">（請選擇部門）</option>';
        $.each(META.depts || [], function (i, x) { odOpt += '<option value="' + x.id + '">' + esc(x.name) + '</option>'; });
        $('#setOwnDept').html(odOpt).val('');
        OWN_SCOPE = (res.owner_scope_rows || []).slice();
        ownScopeReset();
        renderOwnScope();

        var plan = (META.asdoc || {}).plan || {}, card = (META.asdoc || {}).card || {};
        $('#asPlanTxt').val(plan.bound ? (plan.doc_no + '　' + plan.doc_name) : '（未綁定）');
        $('#asCardTxt').val(card.bound ? (card.doc_no + '　' + card.doc_name) : '（未綁定）');
        openMask('setMask');
    });
}
/* ── 專案負責人資格（部門×職稱）───────────────────────────────
   操作方式：選一個部門 → 在右邊點選要開放的職稱（可複選，「全部職稱」與個別職稱互斥）→ 按「加入」一次寫進去。
   清單以**部門為一列**顯示，右邊直接列出該部門選定的職稱，每列有「修改」（把該部門讀回上面繼續改）與「刪除」。
   存進去的資料仍是 {d:部門id, p:職稱id} 的組合（p=0＝全部職稱），存檔時後端會再 parse 正規化一次。 */
var OWN_SCOPE = [];       // [{d,p,dept_name,pos_name}, …]
var OWN_EDIT  = 0;        // 目前正在「修改」哪個部門（0＝新增模式）

function ownPosName(pid) {
    if (num(pid) === 0) return '全部職稱';
    var hit = $.grep(META.positions || [], function (x) { return num(x.id) === num(pid); });
    return hit.length ? hit[0].name : ('（已刪除的職稱 #' + pid + '）');
}
function ownDeptName(did) {
    var hit = $.grep(META.depts || [], function (x) { return num(x.id) === num(did); });
    return hit.length ? hit[0].name : ('（已刪除的部門 #' + did + '）');
}
/* 職稱膠囊列：全部職稱擺第一顆，其餘依 position.sort_order（META.positions 已排好） */
function renderOwnPosBar(selected) {
    selected = selected || [];
    var on = function (pid) { return $.inArray(num(pid), $.map(selected, num)) >= 0; };
    var h = '<span class="pj-tag' + (on(0) ? ' on' : '') + '" data-ownpos="0">全部職稱</span>';
    $.each(META.positions || [], function (i, x) {
        h += '<span class="pj-tag' + (on(x.id) ? ' on' : '') + '" data-ownpos="' + x.id + '">' + esc(x.name) + '</span>';
    });
    $('#setOwnPosBar').html(h);
}
function ownPickedPos() {
    var out = [];
    $('#setOwnPosBar .pj-tag.on').each(function () { out.push(num($(this).data('ownpos'))); });
    return out;
}
/* 「全部職稱」和個別職稱互斥：點哪個就清掉另一邊，避免存進去一堆被蓋掉的多餘列 */
$(document).on('click', '#setOwnPosBar .pj-tag', function () {
    var pid = num($(this).data('ownpos'));
    if (pid === 0) {
        var turnOn = !$(this).hasClass('on');
        $('#setOwnPosBar .pj-tag').removeClass('on');
        if (turnOn) $(this).addClass('on');
    } else {
        $('#setOwnPosBar .pj-tag[data-ownpos="0"]').removeClass('on');
        $(this).toggleClass('on');
    }
    $('#setOwnErr').text('');
});

/* 把部門下拉選到指定部門。
   共用檔的下拉篩選（data-eg-filter）在使用者打過字時只會保留符合的選項，
   直接 .val() 可能因為那個選項已被篩掉而落空——所以先把篩選框清空還原完整清單再選。 */
function ownSelectDept(d) {
    var $sel = $('#setOwnDept'), $box = $sel.prev('.eg-filter-box');
    if ($box.length && $box.val() !== '') { $box.val(''); $box[0].dispatchEvent(new Event('input')); }
    $sel.val(d ? String(d) : '');
}

function ownScopeReset() {
    OWN_EDIT = 0;
    ownSelectDept(0);
    renderOwnPosBar([]);
    $('#btnOwnScopeAdd').text('加入');
    $('#btnOwnScopeCancel').hide();
    $('#setOwnErr').text('');
}

function renderOwnScope() {
    /* 依部門分組，部門順序沿用 META.depts（後端已依 sort_order 由小到大） */
    var byDept = {};
    $.each(OWN_SCOPE, function (i, r) { (byDept[num(r.d)] = byDept[num(r.d)] || []).push(num(r.p)); });
    var order = $.map(META.depts || [], function (x) { return num(x.id); });
    $.each(byDept, function (k) { if ($.inArray(num(k), order) < 0) order.push(num(k)); });  // 已刪除的部門排最後

    var h = '', n = 0;
    $.each(order, function (i, did) {
        if (!byDept[did]) return;
        n++;
        var names = $.map(byDept[did], function (pid) { return ownPosName(pid); });
        h += '<tr><td style="white-space:nowrap;">' + esc(ownDeptName(did)) + '</td>'
           + '<td style="text-align:left;">' + esc(names.join('、')) + '</td>'
           + '<td style="white-space:nowrap;">'
           + '<button class="own-scope-edit" data-d="' + did + '" style="height:24px;padding:0 8px;border:1px solid #d98a33;border-radius:4px;background:#fff;color:#b5762a;cursor:pointer;margin-right:4px;">修改</button>'
           + '<button class="own-scope-del" data-d="' + did + '" style="height:24px;padding:0 8px;border:1px solid #DD5138;border-radius:4px;background:#fff;color:#DD5138;cursor:pointer;">刪除</button>'
           + '</td></tr>';
    });
    if (!n) h = '<tr><td colspan="3" style="padding:10px;color:#8a6d45;">（未設定＝不限制，全體在職員工都可以當專案負責人）</td></tr>';
    $('#ownScopeBody').html(h);
    $('#ownScopeCount').html(n ? ('目前設定 ' + n + ' 個部門；儲存後負責人下拉只會列出符合的人員。' + ownScopeWhoText()) : '');
}
/* 目前（已儲存的設定下）符合資格的是誰。
   兼任的人在下拉上顯示的是「職級最高的那個職務」，只看部門×職稱清單看不出來到底誰會出現，所以把名單直接列出來。 */
function ownScopeWhoText() {
    if (!(META.owner_scope && META.owner_scope.length)) return '';
    /* 這裡要看的是「資格」命中誰（全公司），不是目前這位管理員自己能挑誰 */
    var ps = META.owner_scope_all || META.owner_people || [];
    if (!ps.length) return '<br><span style="color:#DD5138;">目前沒有任何人符合已儲存的設定，負責人會選不到人。</span>';
    var names = $.map(ps, function (x) { return (x.dept_name ? x.dept_name + ' ' : '') + (x.position_name ? x.position_name + ' ' : '') + x.user_cname; });
    return '<br>目前符合資格（依<b>已儲存</b>的設定）共 ' + ps.length + ' 人：' + esc(names.join('、'))
         + '<br><span style="color:#8a6d45;">※ 兼任者以<b>職級最高的職務</b>顯示，所以名單上的部門/職稱可能跟你設的那一組不同（例：兼任技術部課長的董事長，設技術部後也會出現）。</span>';
}

$(document).on('click', '#btnOwnScopeAdd', function () {
    var d = num($('#setOwnDept').val()), picked = ownPickedPos(), $e = $('#setOwnErr');
    if (!d)              { $e.text('請先選擇部門'); return; }
    if (!picked.length)  { $e.text('請至少點選一個職稱（或選「全部職稱」）'); return; }
    if ($.inArray(0, picked) >= 0) picked = [0];          // 全部職稱＝該部門只留這一列
    /* 同一個部門一律整組取代（新增與修改行為一致，不會殘留舊職稱） */
    OWN_SCOPE = $.grep(OWN_SCOPE, function (r) { return num(r.d) !== d; });
    $.each(picked, function (i, pid) {
        OWN_SCOPE.push({ d: d, p: pid, dept_name: ownDeptName(d), pos_name: ownPosName(pid) });
    });
    ownScopeReset();
    renderOwnScope();
});
$(document).on('click', '#btnOwnScopeCancel', function () { ownScopeReset(); });
$(document).on('click', '.own-scope-edit', function () {
    var d = num($(this).data('d'));
    OWN_EDIT = d;
    ownSelectDept(d);
    renderOwnPosBar($.map($.grep(OWN_SCOPE, function (r) { return num(r.d) === d; }), function (r) { return num(r.p); }));
    $('#btnOwnScopeAdd').text('更新此部門');
    $('#btnOwnScopeCancel').show();
    $('#setOwnErr').text('');
});
$(document).on('click', '.own-scope-del', function () {
    var d = num($(this).data('d'));
    OWN_SCOPE = $.grep(OWN_SCOPE, function (r) { return num(r.d) !== d; });
    if (OWN_EDIT === d) ownScopeReset();
    renderOwnScope();
});

$(document).on('click', '#setCosignBox .pj-tag', function () { $(this).toggleClass('on'); });
$(document).on('click', '#btnSetSave', function () {
    var cos = [];
    $('#setCosignBox .pj-tag.on').each(function () { cos.push(num($(this).data('setcos'))); });
    api('setting_save', {
        approver_user_id: $('#setApUser').val(), approver_dept_id: $('#setApDept').val(),
        default_cosign_depts: cos.join(','),
        block_close_on_missing: $('#setBlockClose').is(':checked') ? '1' : '0',
        plan_stamp_tpl_id: $('#setPlanTpl').val() || '0', card_stamp_tpl_id: $('#setCardTpl').val() || '0',
        owner_scope: JSON.stringify($.map(OWN_SCOPE, function (r) { return { d: num(r.d), p: num(r.p) }; }))
    }, 'POST').done(function (r) {
        alert(r.message);
        META.default_cosign_depts = cos.join(',');
        /* 資格改完馬上生效：負責人下拉的候選名單同步換掉，不必重新整理頁面 */
        META.owner_scope     = r.owner_scope_rows || [];
        META.owner_people    = r.owner_people || [];
        META.owner_scope_all = r.owner_scope_all || null;
        renderOwnScope();
        closeMask('setMask');
    });
});
/* AS 文件綁定一律走共用挑選器（禁純下拉、禁各頁自刻＝ai-rules/16 第一之三節）
   兩件事一定要給對，少一個跳窗就是廢的：
   ・docs＝完整 AS 文件清單（來自 meta 的 as_docs）。沒傳的話清單是空的，打字永遠「符合 0 筆」。
   ・回呼名叫 onSave(id, doc)，不是 onPick——名字錯的話按「儲存綁定」不會有任何反應。 */
function pickAsDoc(module, txtSel) {
    if (typeof EGAsDoc === 'undefined') { alert('AS 文件挑選器未載入'); return; }
    var isCard = (module === 'project_card');
    EGAsDoc.open({
        docs: META.as_docs || [],
        current: (META.asdoc || {})[isCard ? 'card_id' : 'plan_id'] || 0,
        title: (isCard ? '專案管理卡' : '專案執行規劃表') + ' — AS 文件編號綁定',
        onSave: function (id) {
            api('asdoc_save', { module: module, doc_id: id || 0 }, 'POST').done(function (res) {
                alert(res.message);
                META.asdoc = META.asdoc || {};
                META.asdoc[isCard ? 'card' : 'plan'] = res.meta;
                META.asdoc[isCard ? 'card_id' : 'plan_id'] = id || 0;
                $(txtSel).val(res.meta.bound ? (res.meta.doc_no + '　' + res.meta.doc_name) : '（未綁定）');
            });
        }
    });
}
$(document).on('click', '#btnAsPlan', function () { pickAsDoc('project_plan', '#asPlanTxt'); });
$(document).on('click', '#btnAsCard', function () { pickAsDoc('project_card', '#asCardTxt'); });

/* ══════════════════════════════════════════════════════════════
   列印（ai-rules/16）
   ・大標題＝本公司全名，動態取自 customer_list.is_own_company（禁寫死）
   ・表頭表單名稱＝綁定 AS 文件的 doc_name（禁寫死）
   ・頁碼「第X頁／共Y頁」左下角，交給列印引擎的 counter(pages) 算，多頁才顯示
   ・AS 文件編號右下角每頁都印，版次依該單據的業務日期回推（第三之四節）
   ・簽章一律走 eg_stamp.js 帶日期印章，代理人右下角加「代」字
   ・紙張方向依紙本：執行規劃表＝A4 直式、專案管理卡＝A4 橫式
   ══════════════════════════════════════════════════════════════ */

/* 圖章 HTML（掃描實體章是非同步載入的，要等 whenReady 才拿得到正確的章） */
function stampHtml(name, date, isDeputy, dept, post) {
    if (!name) return '';
    /* 圖章上的日期也要走 dispDate()（ai-rules/20：顯示一律 YYYY.MM.DD）——
       這裡很容易漏，漏了就會印成 2026-09-15，其他地方卻是 2026.09.15（既有模組踩過同一個坑） */
    var d = date ? dispDate(date) : '';
    try {
        if (window.EGStamp && EGStamp.stamp) return EGStamp.stamp(name, d, !!isDeputy, null, dept || '', post || '');
    } catch (e) { /* 落到下面的純文字備援 */ }
    return '<div style="text-align:center;">' + esc(name) + '<br><span style="font-size:10px;">' + d + '</span></div>';
}

/* 列印共用 CSS：頁碼與 AS 編號都交給 @page，不用 JS 量高度自算分頁（列印分頁鐵則） */
function printBaseCss(orientation, docNo, pageCount) {
    var css = '@page { size: A4 ' + orientation + '; margin: 10mm 8mm 12mm 8mm;';
    if (pageCount > 1) {
        css += ' @bottom-left { content: "第 " counter(page) " 頁／共 " counter(pages) " 頁"; font-size:9pt; color:#333; }';
    }
    if (docNo) {
        css += ' @bottom-right { content: "' + String(docNo).replace(/"/g, '') + '"; font-size:9pt; color:#333; }';
    }
    css += ' }\n';
    css += 'body { font-family:"標楷體","DFKai-sb","Microsoft JhengHei",serif; color:#000; margin:0; }\n'
        +  '.p-co { text-align:center; font-size:16pt; font-weight:bold; letter-spacing:2px; }\n'
        +  '.p-en { text-align:center; font-size:9pt; letter-spacing:1px; margin-bottom:2mm; }\n'
        +  '.p-tt { text-align:center; font-size:14pt; font-weight:bold; margin-bottom:3mm; }\n'
        +  'table { border-collapse:collapse; width:100%; font-size:9pt; }\n'
        +  'th, td { border:1px solid #000; padding:1mm 1.5mm; vertical-align:top; }\n'
        +  'th { background:#f2f2f2; text-align:center; font-weight:bold; }\n'
        +  '.c { text-align:center; }\n'
        +  '.nb { border:none; }\n'
        +  'thead { display:table-header-group; }\n'   /* 跨頁時表頭自然重複 */
        +  'tr { page-break-inside:avoid; }\n';
    return css;
}

function egPrintWindow(html) {
    var w = window.open('', '_blank');
    if (!w) { alert('瀏覽器擋掉了新視窗，請允許本站開啟彈出視窗後再試'); return; }
    w.document.write(html);
    w.document.close();
}

/* ── 2-GM-02-02 專案執行規劃表（A4 直式，紙本 pageSetup orientation=portrait）── */
function printPlan(res) {
    var p = res.project;
    api('print_meta', { module: 'project_plan', biz_date: p.plan_date || p.start_date || META.today,
                        signer_ids: num(p.owner_id) })
    .done(function (m) {
        /* 掃描實體章是非同步載入的，沒等它有實體章的人會印成預設 SVG 章（eg_stamp.js 記過的坑） */
        var go = function () { egPrintWindow(buildPlanHtml(res, m)); };
        if (window.EGStamp && EGStamp.whenReady) EGStamp.whenReady(go); else go();
    });
}

function buildPlanHtml(res, m) {
    var p = res.project;
    var grouped = groupTasks(res.goals || [], res.tasks || []);
    var periods = planPeriods(res);
    var rowCount = 0;
    $.each(grouped, function (i, g) { rowCount += Math.max(1, g.tasks.length); });
    var pageCount = rowCount > 14 ? 2 : 1;   // 只用來決定要不要印頁碼；實際分頁交給列印引擎

    var css = printBaseCss('portrait', m.meta.doc_no, pageCount)
      + '.hdr td { border:1px solid #000; font-size:10pt; }\n'
      + '.pd { width:' + (periods.length ? (44 / periods.length) : 44) + '%; }\n'
      + '.pcell { padding:0; height:5mm; }\n'
      + '.pbar { display:block; height:3mm; margin:1mm 0; }\n'
      + '.pbar.plan { background:#d9d9d9; }\n'
      + '.pbar.act  { background:#000; }\n'
      + '.ms { font-size:10pt; text-align:center; }\n';

    var h = '<div class="p-co">' + esc(m.meta.company || '') + '</div>'
      + '<div class="p-en">EXCELLENT GEAR TECHNOLOGY CO.,LTD</div>'
      + '<div class="p-tt">' + esc(m.meta.doc_name || '專案執行規劃表') + '</div>';

    /* 表頭：專案名稱／專案負責人／專案目標／日期（比照紙本 B4/U4/B6/U6） */
    var ownerSign = stampHtml(p.owner_name, p.plan_date || p.start_date || '', false,
        (m.signers[p.owner_id] || {}).dept, (m.signers[p.owner_id] || {}).post);
    h += '<table class="hdr"><colgroup><col style="width:16%"><col style="width:44%"><col style="width:16%"><col style="width:24%"></colgroup>'
      + '<tr><td>專案名稱</td><td>' + esc(p.project_name) + '　<span style="font-size:9pt;">（專案代號 '
      + esc(p.project_no) + '）</span></td>'
      + '<td>專案負責人</td><td class="c">' + ownerSign + '</td></tr>'
      + '<tr><td>專案目標</td><td>' + esc(p.goal_desc || '').replace(/\n/g, '<br>') + '</td>'
      + '<td>日期</td><td class="c">' + dispDate(p.plan_date || p.start_date) + '</td></tr>'
      + '</table><div style="height:2mm;"></div>';

    /* 表身：目標｜主要任務｜專案完成日期(預計/實際)｜周期格狀圖｜負責人 */
    h += '<table><colgroup><col style="width:15%"><col style="width:20%"><col style="width:6%"><col style="width:9%">';
    $.each(periods, function () { h += '<col class="pd">'; });
    h += '<col style="width:11%"></colgroup><thead><tr>'
      + '<th rowspan="2">目標</th><th rowspan="2">主要任務</th>'
      + '<th colspan="2">專案完成日期</th>'
      + '<th colspan="' + Math.max(1, periods.length) + '">周期</th>'
      + '<th rowspan="2">負責人</th></tr><tr>'
      + '<th></th><th>日期</th>';
    if (periods.length) { $.each(periods, function (i, pr) { h += '<th style="font-size:8pt;">' + esc(pr.label) + '</th>'; }); }
    else h += '<th></th>';
    h += '</tr></thead><tbody>';

    if (!grouped.length) {
        h += '<tr><td colspan="' + (5 + Math.max(1, periods.length)) + '" class="c">（尚未建立目標與任務）</td></tr>';
    }
    $.each(grouped, function (gi, g) {
        var list = g.tasks.length ? g.tasks : [{ task_name: '', owner_name: '' }];
        $.each(list, function (ti, t) {
            /* 每個任務佔兩列：預計、實際（比照紙本 P9/P10 的 預計/實際） */
            h += '<tr>';
            if (ti === 0) h += '<td rowspan="' + (list.length * 2) + '">' + esc(g.goal_name) + '</td>';
            h += '<td rowspan="2">' + esc(t.task_name)
               + (num(t.is_milestone) ? '<div class="ms">◆</div>' : '') + '</td>'
               + '<td class="c">預計</td><td class="c">' + dispDate(t.plan_end) + '</td>'
               + periodCells(t, periods, 'plan')
               + '<td rowspan="2" class="c">' + esc(t.owner_name || '') + '</td></tr>'
               + '<tr><td class="c">實際</td><td class="c">' + dispDate(t.act_end) + '</td>'
               + periodCells(t, periods, 'act') + '</tr>';
        });
    });
    h += '</tbody></table>';

    return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'
        + esc(m.meta.doc_name || '專案執行規劃表') + '</title><style>' + css + '</style></head><body>' + h
        + '<scr' + 'ipt>window.onload=function(){setTimeout(function(){window.print();},350);};</scr' + 'ipt></body></html>';
}

/* 周期欄位：依專案期間切成月（超過 12 個月改成季，避免欄位窄到看不出來） */
function planPeriods(res) {
    var r = ganttRange(res.project, res.tasks || []);
    if (!r) return [];
    var d0 = new Date(r.start + 'T00:00:00'), d1 = new Date(r.end + 'T00:00:00');
    var months = (d1.getFullYear() - d0.getFullYear()) * 12 + (d1.getMonth() - d0.getMonth()) + 1;
    var out = [], cur = new Date(d0.getFullYear(), d0.getMonth(), 1);
    if (months <= 12) {
        for (var i = 0; i < months; i++) {
            var s = new Date(cur.getFullYear(), cur.getMonth(), 1);
            var e = new Date(cur.getFullYear(), cur.getMonth() + 1, 0);
            out.push({ start: fmtYmd(s), end: fmtYmd(e), label: (s.getMonth() + 1) + '月' });
            cur.setMonth(cur.getMonth() + 1);
        }
    } else {
        var q = Math.ceil(months / 3);
        for (var j = 0; j < Math.min(q, 12); j++) {
            var qs = new Date(cur.getFullYear(), cur.getMonth(), 1);
            var qe = new Date(cur.getFullYear(), cur.getMonth() + 3, 0);
            out.push({ start: fmtYmd(qs), end: fmtYmd(qe),
                       label: String(qs.getFullYear()).slice(2) + '/' + (qs.getMonth() + 1) + '~' + (qe.getMonth() + 1) });
            cur.setMonth(cur.getMonth() + 3);
        }
    }
    return out;
}
function fmtYmd(d) {
    return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
}

function periodCells(t, periods, kind) {
    if (!periods.length) return '<td></td>';
    var s = kind === 'plan' ? (t.plan_start || t.plan_end) : (t.act_start || t.act_end);
    var e = kind === 'plan' ? (t.plan_end || t.plan_start) : (t.act_end || t.act_start);
    var out = '';
    $.each(periods, function (i, pr) {
        var on = (s && e && s <= pr.end && e >= pr.start);
        out += '<td class="pcell">' + (on ? '<span class="pbar ' + kind + '"></span>' : '') + '</td>';
    });
    return out;
}

/* ── 2-GM-02-03 專案管理卡（A4 橫式，紙本 pageSetup orientation=landscape）── */
function printCard(res) {
    var c = res.card, p = res.project;
    var ids = [c.sign_approve_id, c.sign_review_id, c.sign_maker_id].filter(function (x) { return num(x); }).join(',');
    api('print_meta', { module: 'project_card', biz_date: c.review_date, signer_ids: ids }).done(function (m) {
        var go = function () { egPrintWindow(buildCardHtml(res, m)); };
        if (window.EGStamp && EGStamp.whenReady) EGStamp.whenReady(go); else go();
    });
}

function buildCardHtml(res, m) {
    var c = res.card, p = res.project;
    var items = c.items || [];
    var css = printBaseCss('landscape', m.meta.doc_no, items.length > 12 ? 2 : 1)
      + '.sign td { border:1px solid #000; height:22mm; vertical-align:middle; text-align:center; }\n'
      + '.sign .lb { width:8%; background:#f2f2f2; font-weight:bold; }\n'
      + '.meta { border:none; margin-bottom:2mm; font-size:10pt; }\n'
      + '.meta td { border:none; padding:0 2mm 1mm 0; }\n';

    var h = '<div class="p-co">' + esc(m.meta.company || '') + '</div>'
      + '<div class="p-en">EXCELLENT GEAR TECHNOLOGY CO.,LTD</div>'
      + '<div class="p-tt">' + esc(m.meta.doc_name || '專案管理卡') + '</div>'
      + '<table class="meta"><tr>'
      + '<td><b>專案代號：</b>' + esc(p.project_no) + '</td>'
      + '<td><b>專案名稱：</b>' + esc(p.project_name) + '</td>'
      + '<td><b>管理卡編號：</b>' + esc(c.card_no || '') + '</td>'
      + '<td style="text-align:right;"><b>檢討日期：</b>' + dispDate(c.review_date) + '</td>'
      + '</tr></table>';

    h += '<table><colgroup><col style="width:4%"><col style="width:14%"><col style="width:8%"><col style="width:7%">'
      + '<col style="width:19%"><col style="width:17%"><col style="width:17%"><col style="width:14%"></colgroup>'
      + '<thead><tr><th>項次</th><th>各項目標名稱</th><th>主辦單位</th><th>承辦人</th>'
      + '<th>目前應達成基準</th><th>現階段問題</th><th>後續辦理方法</th><th>備註</th></tr></thead><tbody>';
    if (!items.length) h += '<tr><td colspan="8" class="c">（無項次）</td></tr>';
    $.each(items, function (i, it) {
        h += '<tr><td class="c">' + (i + 1) + '</td>'
          + '<td>' + esc(it.goal_name || '') + '</td>'
          + '<td class="c">' + esc(it.dept_name || '') + '</td>'
          + '<td class="c">' + esc(it.owner_name || '') + '</td>'
          + '<td>' + esc(it.baseline || '') + '</td>'
          + '<td>' + (num(it.on_track) && !$.trim(it.issue_text || '')
                      ? '依計畫進行' : esc(it.issue_text || '').replace(/\n/g, '<br>')) + '</td>'
          + '<td>' + esc(it.follow_text || '').replace(/\n/g, '<br>') + '</td>'
          + '<td>' + esc(it.note || '') + '</td></tr>';
    });
    /* 紙本固定 17 列格子，不足的補空列讓版面跟紙本一致 */
    for (var k = items.length; k < 12; k++) {
        h += '<tr><td class="c">' + (k + 1) + '</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>';
    }
    h += '</tbody></table>';

    function sg(id, name, date) {
        if (!num(id) || !name) return '';
        var s = m.signers[id] || {};
        return stampHtml(name, date, false, s.dept, s.post);
    }
    h += '<table class="sign" style="margin-top:3mm;"><tr>'
      + '<td class="lb">核准</td><td>' + sg(c.sign_approve_id, c.sign_approve_name, c.sign_approve_date) + '</td>'
      + '<td class="lb">審查</td><td>' + sg(c.sign_review_id, c.sign_review_name, c.sign_review_date) + '</td>'
      + '<td class="lb">製表</td><td>' + sg(c.sign_maker_id, c.sign_maker_name, c.sign_maker_date) + '</td>'
      + '</tr></table>';

    return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'
        + esc(m.meta.doc_name || '專案管理卡') + '</title><style>' + css + '</style></head><body>' + h
        + '<scr' + 'ipt>window.onload=function(){setTimeout(function(){window.print();},350);};</scr' + 'ipt></body></html>';
}

/* ══════════════════════════ 跨專案總覽（內部用，不是 AS 表單） ══════════════════════════ */
function openOverview() {
    var h = '<p class="pj-hint">內部管理用的橫向檢視：一次看到目前所有進行中的專案與各自的進度、缺件與 BOM 提示。'
          + '<b>這不是 AS 表單，列印時不會印 AS 文件編號。</b>正式的 2-GM-02-03 專案管理卡請在各專案內開立。</p>';
    var rows = $.grep(LIST, function (r) { return r.status !== 'closed' && r.status !== 'terminated'; });
    if (!rows.length) { h += '<div class="pj-hint">目前沒有進行中的專案。</div>'; $('#ovBody').html(h); openMask('ovMask'); return; }
    h += '<div class="pj-table-wrap"><table class="pj-table" id="ovTable"><thead><tr>'
      + '<th style="width:80px;">專案代號</th><th>專案名稱</th><th style="width:110px;">客戶</th>'
      + '<th style="width:80px;">負責人</th><th style="width:58px;">階段</th><th style="width:70px;">狀態</th>'
      + '<th style="width:150px;">期間</th><th style="width:96px;">進度</th>'
      + '<th style="width:56px;">訂單</th><th style="width:56px;">料號</th><th style="width:60px;">BOM提示</th></tr></thead><tbody>';
    $.each(rows, function (i, r) {
        h += '<tr><td><b>' + esc(r.project_no) + '</b></td><td class="l">' + esc(r.project_name) + '</td>'
          + '<td class="l">' + esc(r.customer_name || '') + '</td><td>' + esc(r.owner_name || '') + '</td>'
          + '<td><span class="ph">' + esc(r.phase_label) + '</span></td>'
          + '<td><span class="st st-' + esc(r.status) + '">' + esc(STATUS_LABEL[r.status] || r.status) + '</span></td>'
          + '<td>' + dispDate(r.start_date) + ' ~ ' + dispDate(r.end_date) + '</td>'
          + '<td>' + barHtml(num(r.progress)) + '</td>'
          + '<td>' + num(r.order_cnt) + '</td><td>' + num(r.part_cnt) + '</td>'
          + '<td>' + (num(r.bom_alert_cnt) || '－') + '</td></tr>';
    });
    h += '</tbody></table></div>';
    $('#ovBody').html(h);
    openMask('ovMask');
}
$(document).on('click', '#btnOvPrint', function () {
    var rows = $.grep(LIST, function (r) { return r.status !== 'closed' && r.status !== 'terminated'; });
    var company = ((META.asdoc || {}).plan || {}).company || '';
    /* 內部用清單：不印 AS 編號（第三之三節：AS 編號只給真的對應到那份 AS 表單的列印版） */
    var css = printBaseCss('landscape', '', rows.length > 18 ? 2 : 1);
    var h = '<div class="p-co">' + esc(company) + '</div>'
      + '<div class="p-tt">專案執行狀況總覽（內部管理用）</div>'
      + '<div style="text-align:right;font-size:9pt;margin-bottom:2mm;">列印日期：' + dispDate(META.today) + '</div>'
      + '<table><thead><tr><th style="width:9%">專案代號</th><th>專案名稱</th><th style="width:12%">客戶</th>'
      + '<th style="width:9%">負責人</th><th style="width:7%">階段</th><th style="width:8%">狀態</th>'
      + '<th style="width:16%">期間</th><th style="width:7%">進度</th><th style="width:6%">訂單</th><th style="width:6%">料號</th></tr></thead><tbody>';
    $.each(rows, function (i, r) {
        h += '<tr><td class="c">' + esc(r.project_no) + '</td><td>' + esc(r.project_name) + '</td>'
          + '<td>' + esc(r.customer_name || '') + '</td><td class="c">' + esc(r.owner_name || '') + '</td>'
          + '<td class="c">' + esc(r.phase_label) + '</td>'
          + '<td class="c">' + esc(STATUS_LABEL[r.status] || r.status) + '</td>'
          + '<td class="c">' + dispDate(r.start_date) + ' ~ ' + dispDate(r.end_date) + '</td>'
          + '<td class="c">' + num(r.progress) + '%</td>'
          + '<td class="c">' + num(r.order_cnt) + '</td><td class="c">' + num(r.part_cnt) + '</td></tr>';
    });
    h += '</tbody></table>';
    egPrintWindow('<!DOCTYPE html><html><head><meta charset="utf-8"><title>專案執行狀況總覽</title><style>'
        + css + '</style></head><body>' + h
        + '<scr' + 'ipt>window.onload=function(){setTimeout(function(){window.print();},300);};</scr' + 'ipt></body></html>');
});
