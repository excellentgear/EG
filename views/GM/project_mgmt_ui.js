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
/**
 * 開啟／重新載入專案明細。
 *
 * ※ 穩定度重點（使用者回報「規劃表填的東西有時候存不起來，有時候又可以」）：
 *   這支被 18 個地方呼叫——加料號、移出訂單、同步 BOM、製程備註、開管理卡、知悉 BOM 變更…
 *   全部都會把整個跳窗重繪一次。原本重繪是拿「伺服器剛回來的資料」重畫，
 *   所以只要使用者在「執行規劃表」填到一半、中途去別的分頁做了任何一個動作，
 *   填的東西就被無聲換掉；他再按儲存，存進去的其實是伺服器原本的內容＝看起來像「沒存到」。
 *   （中途沒碰別的分頁時就正常，這正是「有時候好、有時候不好」的原因。）
 *
 *   現在的作法：
 *     同一個專案重新載入 → 把畫面上還沒存的目標／任務接回去，並保留「未儲存」狀態與所在分頁；
 *     切換到別的專案 → 先問清楚要不要放棄（不會默默丟掉）。
 */
function openProject(id, after) {
    var keep = null, keepTab = '';
    if (CUR && num(CUR.project.project_id) && (PLAN_DIRTY || CARD_DIRTY)) {
        if (num(id) === num(CUR.project.project_id)) {
            if (PLAN_DIRTY && planSyncToCur()) keep = { goals: CUR.goals, tasks: CUR.tasks };
        } else {
            if (!planLeaveOk(num(id) ? '切換到別的專案' : '離開')) return;
            PLAN_DIRTY = false; CARD_DIRTY = false;
        }
    }
    /* 重新載入後回到原本看的分頁，不要每次都被丟回「專案基本資料」 */
    if (num(id) && CUR && num(id) === num(CUR.project.project_id)) {
        keepTab = $('.pj-tab.active').data('pane') || '';
    }
    if (!id) { CUR = null; renderDetail(newProjectShell()); openMask('prjMask'); if (after) after(); return; }
    api('get', { project_id: id }).done(function (res) {
        if (keep) { res.goals = keep.goals; res.tasks = keep.tasks; }
        CUR = res;
        /* 順路把缺件數帶回清單那一列（避免清單為了算檢核去掃全部專案） */
        var miss = 0;
        $.each(res.doc_check || [], function (i, r) { miss += num(r.missing); });
        $.each(LIST, function (i, r) { if (num(r.project_id) === id) r._miss = miss; });
        renderList();
        renderDetail(res);
        if (keepTab && $('.pj-tab[data-pane="' + keepTab + '"]').length) {
            $('.pj-tab[data-pane="' + keepTab + '"]').click();
        }
        if (keep) PLAN_DIRTY = true;   // 接回去的內容仍然是「還沒儲存」
        openMask('prjMask');
        if (after) after();
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
    pjMsgClear();
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
    var all = [], firstSel = '';
    $.each(fields || {}, function (k, msg) {
        all.push(msg);
        var sel = map[k];
        if (!sel || !$(sel).length) return;
        $(sel).addClass('fld-bad').find('.pj-err').text(msg).show();
        if (!firstSel) firstSel = sel + ' input, ' + sel + ' select';
    });
    /* 欄位旁的小紅字很容易被忽略（使用者明講「根本不會認真看」），所以一律再跳一次粉紅提示條 */
    if (all.length) {
        $('.pj-tab[data-pane="paneBase"]').click();
        pjMsg(all.join('；'), { sub: '（標紅的欄位請補齊後再儲存）', focus: firstSel || null });
    }
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
        /* 有 after 就交給呼叫端收尾。
           這裡不可以自己 openProject()——重繪會把使用者在「執行規劃表」分頁填到一半、
           還沒送出的內容整個洗掉（使用者回報「儲存後重新整理沒有資料」就是這樣來的）。 */
        if (after) { after(num(res.project_id)); return; }
        alert(res.message);
        loadList();
        openProject(num(res.project_id));
    }).fail(function (xhr) {
        try { showFieldErrors((JSON.parse(xhr.responseText) || {}).fields); } catch (e) { /* ajaxError 已提示 */ }
    });
}

/* ── 底部按鈕 ── */
function renderFoot(res) {
    var p = res.project, h = '<button onclick="closeProject()">關閉</button>';
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
/* 底部「儲存」＝把這個跳窗裡填的東西一次存完（基本資料＋執行規劃表）。
   原本它只存基本資料，存完又重繪整個跳窗，於是使用者在規劃表填的目標與任務會被無聲清掉，
   看起來就是「按了儲存、重新整理卻什麼都沒有」。 */
$(document).on('click', '#btnSaveBase', function () {
    saveBase(function (pid) {
        var done = [];
        var finish = function () {
            saveCardIfDirty(function (cardSaved) {
                if (cardSaved) done.push('專案管理卡');
                savedAndReload(pid, done.length ? ('已儲存專案與' + done.join('、')) : '已儲存');
            });
        };
        /* planHasContent()＝畫面上還有內容。目標被刪光時它是 false，
           但那正是「要把伺服器上的目標刪掉」的情況，所以刪過東西就仍要送出。 */
        if (!planHasContent() && !PLAN_HAS_DEL) { finish(); return; }
        savePlan(pid, function (ok) { if (!ok) return; done.push('執行規劃表'); finish(); });
    });
});
/** 管理卡編輯區有開著、而且被動過才一起存（沒開就什麼都不做） */
function saveCardIfDirty(cb) {
    var cid = num($('#cardEditBox .sec').data('card'));
    if (!CARD_DIRTY || !cid || !$('#btnCardSave').length) { cb(false); return; }
    api('card_save', { card_id: cid, review_date: $('#ciDate').val(), items: JSON.stringify(collectCardItems()) }, 'POST')
        .done(function () { CARD_DIRTY = false; cb(true); })
        .fail(function () { cb(false); });
}
function savedAndReload(pid, msg) {
    PLAN_DIRTY = false; CARD_DIRTY = false;
    loadList();
    openProject(num(pid), function () { pjMsg(msg, { ok: true }); });
}
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
      + (res.can_edit ? '<button id="btnSeed" title="帶入 AS9100 標準流程（三個階段與各步驟）"><i class="fa fa-magic"></i> 帶入標準流程</button>'
                      + '<button id="btnGoalAdd"><i class="fa fa-plus"></i> 新增目標</button>'
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

/* ══ 預計日程的「工作天數」 ══════════════════════════════════════════
   定義（與後端 prj_plan_end_by_days()／prj_plan_days() 同一套）：
     預計開始當天算第 1 天，之後只算工作日；週六日與休假日不算、補班日算。
     所以「工作天數 1」＝當天來回，預計完成日就等於預計開始日。
   行事曆由 meta 的 workday 帶下來（來源是行事曆的 evenement，不是 calendar_workday）。 */
function wdSets() {
    var w = META.workday || {};
    if (!w.__map) {
        w.__map = { h: {}, m: {} };
        $.each(w.holidays || [], function (i, d) { w.__map.h[d] = 1; });
        $.each(w.makeups || [], function (i, d) { w.__map.m[d] = 1; });
        META.workday = w;
    }
    return w.__map;
}
function ymd(dt) {
    var m = dt.getMonth() + 1, d = dt.getDate();
    return dt.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (d < 10 ? '0' : '') + d;
}
function parseYmd(str) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec($.trim(str || ''));
    return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
}
function isWorkday(dt) {
    var map = wdSets(), key = ymd(dt), dow = dt.getDay();
    if (map.m[key]) return true;                       // 補班日優先於週末
    return dow !== 0 && dow !== 6 && !map.h[key];
}
/** 預計開始 + 工作天數 → 預計完成日 */
function planEndByDays(start, days) {
    var dt = parseYmd(start);
    if (!dt) return '';
    var left = (days || 0) - 1, guard = 0;             // 開始日已經算第 1 天
    while (left > 0 && guard++ < 4000) {
        dt.setDate(dt.getDate() + 1);
        if (isWorkday(dt)) left--;
    }
    return ymd(dt);
}
/** 預計開始～預計完成 → 工作天數（同一天＝1；完成早於開始＝0，代表算不出來） */
function planDaysBetween(start, end) {
    var a = parseYmd(start), b = parseYmd(end);
    if (!a || !b || ymd(b) < ymd(a)) return 0;
    var n = 1, guard = 0;                              // 開始日本身算 1 天
    while (ymd(a) < ymd(b) && guard++ < 4000) {
        a.setDate(a.getDate() + 1);
        if (isWorkday(a)) n++;
    }
    return n;
}

/* ══ 執行規劃表的「負責人」：先選部門、再選人 ══════════════════════════
   為什麼要兩層：eg_people_list() 一人只回一列（職級最高那筆），兼任的人在單一下拉裡
   只會以其中一個職務出現＝「多職務者沒有完整呈現」；而且全公司的人擠在一個下拉裡也找不到人。
   改成先選部門後，兼任者會在他掛的每個部門底下各自以「該部門的職稱」出現。
   哪些部門會出現在部門下拉＝模組設定「執行規劃表負責人部門」（複選；一個都沒設＝全部部門）。 */
function deptById(id) {
    var hit = $.grep(META.depts || [], function (d) { return num(d.id) === num(id); });
    return hit.length ? hit[0] : null;
}
function peopleById(id) {
    var hit = $.grep(META.people || [], function (x) { return num(x.id) === num(id); });
    return hit.length ? hit[0] : null;
}
/* 該部門＋所有子孫部門（組織是樹狀的，只比單一 id 會漏掉底下的組） */
function deptSubtreeIds(deptId) {
    var out = [num(deptId)], i = 0;
    while (i < out.length && i < 500) {
        var cur = out[i++];
        $.each(META.depts || [], function (k, d) {
            if (num(d.parent_id) === cur && $.inArray(num(d.id), out) < 0) out.push(num(d.id));
        });
    }
    return out;
}
function taskDeptAllow() { return $.map(META.task_owner_depts || [], num); }
/* 某個部門實際會出現在下拉裡的哪一列（子部門要換成被允許的上層部門） */
function taskDeptPickable(deptId) {
    var allow = taskDeptAllow();
    if (!allow.length || $.inArray(num(deptId), allow) >= 0) return num(deptId);
    for (var i = 0; i < allow.length; i++) {
        if ($.inArray(num(deptId), deptSubtreeIds(allow[i])) >= 0) return allow[i];
    }
    return num(deptId);   // 不在允許範圍內（多半是舊資料）：taskDeptOptions 會把它額外列出來
}
function taskDeptOptions(curDeptId) {
    var allow = taskDeptAllow();
    var list = allow.length
        ? $.grep(META.depts || [], function (d) { return $.inArray(num(d.id), allow) >= 0; })
        : (META.depts || []).slice();
    /* 舊資料的部門即使已不在允許清單內也要列出來，否則一打開就看不到原本設定的負責人 */
    if (num(curDeptId) && !$.grep(list, function (d) { return num(d.id) === num(curDeptId); }).length) {
        var d0 = deptById(curDeptId);
        if (d0) list = [d0].concat(list);
    }
    var h = '<option value="">（請選部門）</option>';
    $.each(list, function (i, d) {
        h += '<option value="' + d.id + '"' + (num(curDeptId) === num(d.id) ? ' selected' : '') + '>' + esc(d.name) + '</option>';
    });
    return h;
}
/* 該部門（含子部門）底下的人；兼任者以「他在這個部門的職稱」呈現，同部門樹下有多個職務時取職級最高的 */
/**
 * 某部門（含子部門）底下可挑的人。
 * keepOutsider＝true 時，會把「目前這一列已指派、但不屬於這個部門」的人保留為最後一個選項，
 * 並清楚標成（原指派）——這只用在「載入既有資料」時，避免舊資料的負責人被默默清掉。
 * 使用者**自己動手換部門**時一律 false：換了部門就該只看到那個部門的人
 * （使用者回報「品管組怎麼跳出生產課的人員」＝原本兩種情境共用同一段邏輯造成的）。
 */
function taskOwnerOptions(deptId, curOwnerId, keepOutsider) {
    var ids = deptSubtreeIds(deptId), byUser = {}, list = [];
    if (num(deptId)) {
        $.each(META.people_posts || [], function (i, ps) {
            if ($.inArray(num(ps.dept_id), ids) < 0) return;
            if (!peopleById(ps.user_id)) return;         // 在職判定一律以 people_lib 的清單為準
            var cur = byUser[ps.user_id];
            if (!cur || num(ps.position_sort) < num(cur.position_sort)) byUser[ps.user_id] = ps;
        });
        $.each(byUser, function (uid, ps) { list.push(ps); });
        /* 欄位順序與排序鍵固定 部門/職稱/姓名（ai-rules/08 第五節鐵則6） */
        list.sort(function (a, b) {
            if (num(a.dept_sort) !== num(b.dept_sort)) return num(a.dept_sort) - num(b.dept_sort);
            if (num(a.position_sort) !== num(b.position_sort)) return num(a.position_sort) - num(b.position_sort);
            var an = (peopleById(a.user_id) || {}).user_cname || '', bn = (peopleById(b.user_id) || {}).user_cname || '';
            return an < bn ? -1 : (an > bn ? 1 : 0);
        });
    }
    var h = '<option value="">（未指定）</option>', has = false;
    $.each(list, function (i, ps) {
        var per = peopleById(ps.user_id) || {};
        /* 部門下拉已經寫著部門，所以只有「其實在子部門」時才另外標出部門名稱 */
        var label = (num(ps.dept_id) === num(deptId) ? '' : (ps.dept_name ? ps.dept_name + ' ' : ''))
                  + (ps.position_name ? ps.position_name + ' ' : '') + (per.user_cname || '')
                  + (per.leave_note ? '（' + per.leave_note + '）' : '');
        if (num(ps.user_id) === num(curOwnerId)) has = true;
        h += '<option value="' + ps.user_id + '"' + (num(ps.user_id) === num(curOwnerId) ? ' selected' : '') + '>'
           + esc(label) + '</option>';
    });
    /* 載入既有資料時：已指派但不屬於這個部門的人仍要保留（不可默默清空），但要標明是原指派 */
    if (keepOutsider && num(curOwnerId) && !has) {
        var cp = peopleById(curOwnerId);
        h += '<option value="' + num(curOwnerId) + '" selected>（原指派）'
           + esc(cp ? peopleLabel(cp) : ('已離職或已移除的人員 #' + num(curOwnerId))) + '</option>';
    }
    return h;
}
/* 舊任務沒存部門時，從這個人的職務推回一個部門（優先挑落在允許清單內的） */
function guessOwnerDept(uid) {
    if (!num(uid)) return 0;
    var allow = taskDeptAllow(), allowAll = [];
    $.each(allow, function (i, d) { allowAll = allowAll.concat(deptSubtreeIds(d)); });
    var best = null, any = null;
    $.each(META.people_posts || [], function (i, ps) {
        if (num(ps.user_id) !== num(uid)) return;
        if (!any || num(ps.position_sort) < num(any.position_sort)) any = ps;
        if (allow.length && $.inArray(num(ps.dept_id), allowAll) < 0) return;
        if (!best || num(ps.position_sort) < num(best.position_sort)) best = ps;
    });
    var pick = best || any;
    return pick ? taskDeptPickable(pick.dept_id) : 0;
}
/* 長清單才長篩選框：兩個下拉都在表格儲存格裡，短清單再加篩選框只會讓每一列變高 */
function filterAttr(optHtml, ph) {
    return (optHtml.split('<option').length - 1) > 12 ? ' data-eg-filter="' + ph + '"' : '';
}

/* ══ 首件檢驗（AS9102 FAI）══════════════════════════════════════════
   使用者拍板：做成規劃表裡的固定任務列＋結果欄；未通過可重送，每一次都留紀錄。
   時序依據 AS9145：PFMEA／SOP／SIP 屬製程開發（首件之前就要有），FAI 是產品與製程驗證。 */
function faiLatest() {
    var f = (CUR && CUR.fai) || [];
    return f.length ? f[f.length - 1] : null;
}
function faiResultLabel(r) {
    return ((CUR && CUR.fai_results) || META.fai_results || {})[r] || '';
}
function faiBadgeHtml() {
    var last = faiLatest();
    if (!last) return '<span class="pj-hint">尚未送件</span>';
    var r = String(last.result || '');
    if (!r) return '<span class="st st-submitted">第 ' + num(last.seq) + ' 次已送件・待判定</span>';
    var cls = (r === 'fail') ? 'st-rejected' : 'st-approved';
    return '<span class="st ' + cls + '">第 ' + num(last.seq) + ' 次 ' + esc(faiResultLabel(r)) + '</span>'
         + (last.result_date ? '<span class="pj-hint"> ' + dispDate(last.result_date) + '</span>' : '');
}
/** 首件檢驗區塊：送件、結果、重送、歷程 */
function faiBoxHtml(res) {
    var list = res.fai || [], last = list.length ? list[list.length - 1] : null;
    var ro = res.can_edit ? '' : ' disabled';
    var passed = !!res.fai_pass_date;
    var h = '<div class="sec" style="background:#FFFDF8;"><h5>首件檢驗（AS9102）'
          + '<span class="pj-hint" style="font-weight:normal;margin-left:8px;">'
          + 'PFMEA／SOP／SIP 要在送首件<b>之前</b>備妥；型態識別文件管制表在首件通過<b>之後</b>建立。</span></h5>';

    /* 首件前應備文件的檢查：缺就在這裡直接講，不用等使用者自己去翻文件檢核 */
    var lackBefore = 0, lackParts = [];
    $.each(res.doc_check || [], function (i, r) {
        if (num(r.missing_before)) { lackBefore += num(r.missing_before); lackParts.push(r.part_no); }
    });
    if (lackBefore) {
        h += '<div style="border:2px solid #DD5138;background:#FCE4E4;color:#A32E1A;border-radius:6px;'
          + 'padding:8px 12px;margin-bottom:10px;font-size:13px;">'
          + '<b>送首件之前，這些料號還缺 ' + lackBefore + ' 份應備文件：</b>' + esc(lackParts.join('、'))
          + '<br><span style="font-weight:normal;">首件檢驗驗證的就是「這套製程＋這份文件」，'
          + '沒有 PFMEA／SOP／SIP 就沒有判定依據（AS9102／AS9145）。請到「文件檢核」分頁補齊。</span></div>';
    }

    var showNew = res.can_edit && (!last || (String(last.result || '') === 'fail'));
    if (!list.length) {
        h += '<div class="pj-hint" style="margin-bottom:8px;">還沒有送件紀錄。'
          + (res.can_edit ? '填好下面的送件日按「新增送件」即可。' : '') + '</div>';
    } else {
        h += '<table class="sub-tbl" style="margin-bottom:8px;"><thead><tr>'
          + '<th style="width:56px;">次數</th><th style="width:120px;">送件日</th>'
          + '<th style="width:130px;">結果</th><th style="width:120px;">判定日</th><th>備註／未通過原因</th>'
          + (res.can_edit ? '<th style="width:60px;"></th>' : '') + '</tr></thead><tbody>';
        $.each(list, function (i, f) {
            var isLast = (i === list.length - 1);
            var rOpt = '<option value="">（待判定）</option>';
            $.each((res.fai_results || {}), function (k, v) {
                rOpt += '<option value="' + k + '"' + (String(f.result) === k ? ' selected' : '') + '>' + esc(v) + '</option>';
            });
            /* 已經判定過的舊次數一律唯讀——AS9102 要可追溯，不可以事後改掉歷程 */
            var lock = (!res.can_edit || (!isLast && String(f.result || '') !== ''));
            var d = lock ? ' disabled' : '';
            h += '<tr data-fai="' + f.fai_id + '"><td>第 ' + num(f.seq) + ' 次</td>'
              + '<td><input type="date" class="f-send" value="' + esc(f.send_date || '') + '"' + d + '></td>'
              + '<td><select class="f-result"' + d + '>' + rOpt + '</select></td>'
              + '<td><input type="date" class="f-rdate" value="' + esc(f.result_date || '') + '"' + d + '></td>'
              + '<td><input type="text" class="f-note" value="' + esc(f.note || '') + '"' + d
              + ' placeholder="未通過必填原因／特採條件"></td>'
              + (res.can_edit ? '<td>' + (lock ? '<span class="pj-hint">已定案</span>'
                    : '<span class="pj-op f-save">儲存</span>') + '</td>' : '')
              + '</tr>';
        });
        h += '</tbody></table>';
    }
    if (showNew) {
        h += '<div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">'
          + '<div><label>送件日</label><input type="date" id="faiNewDate" style="width:150px;"' + ro + '></div>'
          + '<button id="btnFaiNew" style="height:30px;padding:0 14px;border:1px solid #d98a33;border-radius:4px;'
          + 'background:#F0A24B;color:#fff;cursor:pointer;">'
          + (list.length ? '未通過，重送一次' : '新增送件') + '</button>'
          + '<span class="pj-hint">' + (list.length ? '前一次未通過才可以重送；每一次都會留下紀錄。' : '') + '</span></div>';
    } else if (passed) {
        h += '<div class="pj-hint">首件已於 <b>' + dispDate(res.fai_pass_date) + '</b> 通過，'
          + '型態識別文件管制表現在可以建立了。</div>';
    }
    return h + '</div>';
}
$(document).on('click', '#btnFaiNew', function () {
    api('fai_save', { project_id: CUR.project.project_id, send_date: $('#faiNewDate').val() }, 'POST')
        .done(function (r) { faiApply(r); });
});
$(document).on('click', '#planEditBox .f-save', function () {
    var $tr = $(this).closest('tr');
    api('fai_save', {
        project_id: CUR.project.project_id, fai_id: num($tr.data('fai')),
        send_date: $tr.find('.f-send').val(), result: $tr.find('.f-result').val(),
        result_date: $tr.find('.f-rdate').val(), note: $tr.find('.f-note').val()
    }, 'POST').done(function (r) { faiApply(r); pjMsg(r.message || '已儲存', { ok: true }); });
});
/* 首件狀態一變，文件檢核的閘門與規劃表的固定列都要跟著重畫 */
function faiApply(r) {
    if (!CUR) return;
    CUR.fai = r.fai || [];
    CUR.fai_pass_date = r.fai_pass_date || null;
    if (r.doc_check) CUR.doc_check = r.doc_check;
    var dirty = PLAN_DIRTY;
    planSyncToCur();
    /* 後端補了 RCA／差異首件檢驗時，要用後端回來的任務清單重畫（否則新環節不會出現） */
    if (num(r.followup_added) && r.tasks) { CUR.tasks = r.tasks; CUR.goals = r.goals || CUR.goals; dirty = false; }
    drawPlanEditor(CUR);
    renderCheck(CUR);
    PLAN_DIRTY = dirty;
}

/* 任務狀態下拉（狀態驅動；1~2 天完工的製程不記時分秒＝使用者拍板） */
function taskStatusSelect(t) {
    var cur = String((t && t.status_code) || '');
    var map = (CUR && CUR.task_status) || META.task_status || {};
    var h = '<select class="t-status">';
    $.each(map, function (k, v) {
        h += '<option value="' + esc(k) + '"' + (cur === k ? ' selected' : '') + '>' + esc(v) + '</option>';
    });
    return h + '</select>';
}
/** 異常矯正單：只給連結跳過去，不在本頁另做表單（使用者明確要求） */
function carLinkHtml() {
    var p = (CUR && CUR.project) || {};
    var q = '?prj_no=' + encodeURIComponent(p.project_no || '') + '&prj=' + num(p.project_id);
    return '<a href="/EGsystem/views/QA/correction_order.php' + q + '" target="_blank" rel="noopener" '
         + 'class="pj-op" style="text-decoration:underline;"><i class="fa fa-external-link"></i> 填寫異常矯正單</a>';
}
/* 狀態與實際完成日互相對齊（與後端 prj_task_status_sync() 同一套規則） */
$(document).on('change', '#planEditBox .t-status', function () {
    var $tr = $(this).closest('tr'), $ae = $tr.find('.t-ae');
    if ($(this).val() === 'done') {
        if (!$.trim($ae.val()) && !$ae.prop('disabled')) $ae.val(META.today || '');
    } else if ($.trim($ae.val()) && !$ae.prop('disabled')) {
        $ae.val('');
    }
    planRowRecalc($tr, 'pe');
    planRowProgress($tr);
});
$(document).on('change', '#planEditBox .t-ae', function () {
    var $tr = $(this).closest('tr');
    if ($.trim($(this).val())) $tr.find('.t-status').val('done');
});

/* ── 規劃表編輯器（可增列表格：末列↓加列、空白末列↑移除＝共用檔規則） ── */
var PLAN_ACT_OPEN = false;   // 實際開始／完成現在可不可以填（＝專案是否已立案核准）
var PLAN_DIRTY    = false;   // 規劃表有沒有還沒存進去的變更（避免整桌資料被無聲丟掉）
var PLAN_HAS_DEL  = false;   // 這次有沒有刪掉目標（刪光時畫面上「沒有內容」，但還是要送出才刪得掉）
var CARD_DIRTY    = false;   // 專案管理卡編輯區同上

/* 規劃表裡任何一格被動過就標記為未儲存；重繪與存檔成功時清掉 */
$(document).on('input change', '#planEditBox input, #planEditBox select', function () { PLAN_DIRTY = true; });
$(document).on('input change', '#cardEditBox input, #cardEditBox select, #cardEditBox textarea', function () { CARD_DIRTY = true; });
/** 有未儲存變更時先問一聲；回 false＝使用者選擇留下來 */
/* 關閉專案跳窗前先確認規劃表有沒有沒存到的東西（✕ 與「關閉」都走這裡） */
function closeProject() {
    if (!planLeaveOk('關閉')) return;
    PLAN_DIRTY = false; CARD_DIRTY = false;
    closeMask('prjMask');
}
function planLeaveOk(what) {
    var box = [];
    if (PLAN_DIRTY) box.push('執行規劃表');
    if (CARD_DIRTY) box.push('專案管理卡');
    if (!box.length) return true;
    return confirm(box.join('與') + '還有沒有儲存的變更，' + (what || '離開') + '之後就會不見。\n\n要繼續嗎？（要保留請按取消，再按「儲存」）');
}

function drawPlanEditor(res) {
    var grouped = groupTasks(res.goals || [], res.tasks || []);
    PLAN_ACT_OPEN = !!res.act_open;
    var deptOpt = '<option value="">（無）</option>';
    $.each(META.depts || [], function (i, x) { deptOpt += '<option value="' + x.id + '">' + esc(x.name) + '</option>'; });

    var h = faiBoxHtml(res)
      + '<div class="sec"><h5>編排目標與主要任務</h5>'
      + '<p class="pj-hint">末列按 <b>↓</b> 自動加一列、沒填東西的末列按 <b>↑</b> 自動移除；'
      + '任務改了日期，時間軸與管理卡的「目前應達成基準」都會跟著重算。<br>'
      + '負責人<b>先選部門、再選人</b>（兼任多個部門的人會在各部門底下分別以該部門的職稱出現）；'
      + '部門清單由管理員在「模組設定 → 執行規劃表負責人部門」設定。<br>'
      + '<b>工作天數</b>與<b>預計完成</b>兩邊同動：填了開始日就自動帶出當天完成（＝1 天），'
      + '改天數會重算完成日、直接改完成日也會反算天數。天數只算工作日（週末與行事曆上的休假日不算、補班日要算）。'
      + '<b>上一列的預計完成日會自動變成下一列的預計開始日</b>——你自己改過的開始日不會被蓋掉。</p>'
      + (PLAN_ACT_OPEN ? ''
          : '<p class="pj-hint" style="color:#C4442D;">「實際開始／實際完成」兩欄要等<b>立案核准</b>之後才會出現'
            + '（目前狀態：' + esc(STATUS_LABEL[res.project.status] || res.project.status) + '），此階段只排預計日程。</p>');
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
          + '<th style="width:28px;">#</th><th>主要任務</th>'
          + '<th style="width:114px;">預計開始</th>'
          + '<th style="width:62px;" title="預計開始當天算第 1 天，只算工作日">工作天數</th>'
          + '<th style="width:114px;">預計完成</th>'
          + (PLAN_ACT_OPEN ? '<th style="width:114px;">實際開始</th><th style="width:114px;">實際完成</th>' : '')
          + '<th style="width:96px;" title="1~2 天完工的製程用狀態管理，不記時分秒">狀態</th>'
          + '<th style="width:180px;">負責人（先選部門）</th>'
          + '<th style="width:76px;" title="勾「自動」時：填了實際完成日就是 100%，否則 0%；自己改過就不再自動">進度%</th>'
          + '<th style="width:44px;">里程碑</th><th style="width:30px;"></th></tr></thead>'
          + '<tbody class="t-body" data-eg-row-add="planRowAdd" data-eg-row-del="planRowDel">';
        var list = g.tasks.length ? g.tasks : [{}];
        $.each(list, function (ti, t) { h += planRowHtml(t, ti); });
        h += '</tbody></table></div>';
    });
    h += '</div>';
    $('#planEditBox').html(h);
    $('#planEditBox .t-body tr').each(function () { planRowCheck($(this)); planRowProgress($(this)); });
    PLAN_DIRTY = false;
    /* 目標的主辦單位下拉用 .val() 設，避免字串比對出錯（負責人兩個下拉已在建 option 時標好 selected） */
    $('#planEditBox .sec[data-goal]').each(function (gi) {
        var g = grouped[gi];
        if (!g) return;
        var $s = $(this).find('.g-dept');
        $s.find('option').each(function () { if ($(this).text() === g.dept_name) $s.val($(this).val()); });
    });
}

function planRowHtml(t, i) {
    t = t || {};
    var kind  = String(t.task_kind || '');
    var isFai = (kind === 'fai');
    var isSys = (kind !== '');           // fai/rca/delta_fai 都是系統環節，名稱不可改、不可刪
    var did = num(t.owner_dept_id) || guessOwnerDept(num(t.owner_id));
    var dOpt = taskDeptOptions(did), pOpt = taskOwnerOptions(did, num(t.owner_id), true);
    /* data-pe0＝這一列目前的預計完成日。串接時用它判斷「下一列的開始日還跟著上一列」
       還是「使用者自己改過了」，改過的就不覆蓋。 */
    var days = (t.plan_start && t.plan_end) ? planDaysBetween(t.plan_start, t.plan_end) : 0;
    /* 新列預設跟著自動；既有列看資料庫存的（沒有這個欄位的舊資料視同自動） */
    var pgAuto = (t.progress_auto === undefined || t.progress_auto === null) ? true : !!num(t.progress_auto);
    return '<tr data-task="' + num(t.task_id) + '" data-kind="' + esc(kind) + '" data-pe0="' + esc(t.plan_end || '') + '">'
      + '<td>' + (i + 1) + '</td>'
      + '<td><input type="text" class="t-name' + (isSys ? ' ro-auto' : '') + '" value="'
      + esc(t.task_name || '') + '"' + (isSys ? ' readonly title="系統環節，名稱不可修改"' : '') + '>'
      + (isFai ? '<div style="margin-top:3px;">' + faiBadgeHtml() + '</div>' : '')
      + (kind === 'rca' ? '<div style="margin-top:3px;">' + carLinkHtml() + '</div>' : '')
      + (kind === 'delta_fai' ? '<div class="pj-hint" style="margin-top:3px;">矯正後只驗有變動的特性</div>' : '')
      /* 核准前不畫「實際開始／實際完成」兩欄（反正也不能填），版面讓給「主要任務」；
         值改用 hidden 帶著走，所有 .t-as/.t-ae 的讀取端不必各自判斷有沒有這一欄。
         ※ hidden 一定要放在 <td> 裡面——放在 <td> 與 <td> 之間會被 HTML 解析器搬到表格外面，
           $tr.find('.t-as') 就找不到了。 */
      + (PLAN_ACT_OPEN ? ''
            : '<input type="hidden" class="t-as" value="' + esc(t.act_start || '') + '">'
              + '<input type="hidden" class="t-ae" value="' + esc(t.act_end || '') + '">')
      + '</td>'
      + '<td><input type="date" class="t-ps"' + planMinAttr() + ' value="' + esc(t.plan_start || '') + '"></td>'
      + '<td><input type="number" class="t-days" min="1" max="999" value="' + (days > 0 ? days : '') + '"></td>'
      + '<td><input type="date" class="t-pe" value="' + esc(t.plan_end || '') + '"></td>'
      + (PLAN_ACT_OPEN
            ? '<td><input type="date" class="t-as" value="' + esc(t.act_start || '') + '"></td>'
              + '<td><input type="date" class="t-ae" value="' + esc(t.act_end || '') + '"></td>' : '')
      + '<td>' + taskStatusSelect(t) + '</td>'
      + '<td><select class="t-odept"' + filterAttr(dOpt, '輸入部門名稱篩選…') + '>' + dOpt + '</select>'
      + '<select class="t-owner" style="margin-top:3px;"' + filterAttr(pOpt, '輸入姓名篩選…') + '>' + pOpt + '</select></td>'
      + '<td><input type="number" class="t-pg" min="0" max="100" value="' + num(t.progress) + '">'
      + '<label style="font-size:11px;display:block;margin-top:2px;white-space:nowrap;" title="填了實際完成日就自動變 100%；自己改過數字就不再自動">'
      + '<input type="checkbox" class="t-pgauto" data-eg-skip="1"' + (pgAuto ? ' checked' : '') + '> 自動</label></td>'
      + '<td><input type="checkbox" class="t-ms" data-eg-skip="1"' + (num(t.is_milestone) ? ' checked' : '') + '></td>'
      + '<td>' + (isSys
            ? '<span title="系統環節，不可刪除" style="color:#b59b74;">🔒</span>'
            : '<span class="pj-op t-del" title="刪除這一列">✕</span>') + '</td></tr>';
}

/* ── 預計開始／工作天數／預計完成 三欄連動 ──────────────────────────
   規則（推導欄位鐵則：來源一改就重算，算不出來就清空，不留改之前的舊值）：
     改開始日 → 有天數就用天數算完成日；沒天數就當天來回（完成＝開始）
     改天數   → 由開始日算完成日
     改完成日 → 反算天數；完成早於開始就標紅並清掉天數
   每次完成日有變動，就往下把「還跟著上一列」的後續列一起帶著走。 */
function planRowRecalc($tr, from) {
    var $ps = $tr.find('.t-ps'), $dy = $tr.find('.t-days'), $pe = $tr.find('.t-pe');
    var ps = $.trim($ps.val()), pe = $.trim($pe.val()), dy = num($dy.val());
    if (from === 'ps') {
        if (!ps) { $dy.val(''); }
        else if (dy > 0) { pe = planEndByDays(ps, dy); $pe.val(pe); }
        else { if (!pe || pe < ps) { pe = ps; $pe.val(pe); } $dy.val(planDaysBetween(ps, pe) || ''); }
    } else if (from === 'days') {
        if (dy > 999) { dy = 999; $dy.val(999); }
        if (dy < 1) { $dy.val(''); }                 // 清掉天數不動完成日（改由完成日那邊決定）
        else if (ps) { pe = planEndByDays(ps, dy); $pe.val(pe); }
    } else {                                          // from === 'pe'
        $dy.val(ps && pe ? (planDaysBetween(ps, pe) || '') : '');
    }
    planRowCheck($tr);
    planChainFrom($tr);
}
/** 預計開始日的下限＝專案起日（瀏覽器原生也會擋一層，打錯年份時馬上看得出來） */
function planMinAttr() {
    var d = $.trim((CUR && CUR.project ? CUR.project.start_date : '') || '');
    return d ? ' min="' + esc(d) + '"' : '';
}

/**
 * 任務進度自動計算（與後端 prj_task_progress_auto() 同一套規則）：
 * 勾著「自動」時，填了實際完成日＝100%，否則 0%。
 */
function planRowProgress($tr) {
    if (!$tr.find('.t-pgauto').is(':checked')) return;      // 使用者改過就不再自動
    $tr.find('.t-pg').val($.trim($tr.find('.t-ae').val()) ? 100 : 0);
}
/* 實際完成日一改，進度跟著重算（推導欄位鐵則：來源一改就重算） */
$(document).on('change', '#planEditBox .t-ae', function () { planRowProgress($(this).closest('tr')); });
/* 自己動手改進度＝不再自動（比照管理卡「目前應達成基準」的既有作法） */
/* input 與 change 都要收：有些改法（貼上、程式寫入後補發事件、部分輸入法）只會發其中一種，
   漏收就會出現「我明明填了 60%，重繪一次又變回 0」。 */
$(document).on('input change', '#planEditBox .t-pg', function () {
    $(this).closest('tr').find('.t-pgauto').prop('checked', false);
});
/* 把「自動」勾回來就立刻重算一次，不要等下一次改日期 */
$(document).on('change', '#planEditBox .t-pgauto', function () { planRowProgress($(this).closest('tr')); });

/**
 * 一列的日期檢查：當場標紅並寫原因（表單三總則③；存檔時後端 prj_validate 會同規則再擋一次）。
 * 回傳錯誤訊息字串，沒問題回空字串——存檔前也用同一支，畫面提示與擋存檔的規則不會走鐘。
 */
function planRowCheck($tr) {
    var ps = $.trim($tr.find('.t-ps').val()), pe = $.trim($tr.find('.t-pe').val());
    var as = $.trim($tr.find('.t-as').val()), ae = $.trim($tr.find('.t-ae').val());
    var pStart = $.trim((CUR && CUR.project ? CUR.project.start_date : '') || '');
    var name = $.trim($tr.find('.t-name').val()) || '這一列';
    var msg = '', badPs = false, badPe = false;
    if (ps && pStart && ps < pStart) {
        badPs = true;
        msg = '任務「' + name + '」的預計開始日（' + dispDate(ps) + '）不可早於專案起日（' + dispDate(pStart) + '）';
        /* 最常見的其實是年份打錯（2025 打成 2026 之類）——直接把答案講出來，不要讓人自己找 */
        var fixed = String(pStart).slice(0, 4) + String(ps).slice(4);
        if (fixed !== ps && fixed >= pStart) msg += '　←　年份是不是打錯了？應該是 ' + dispDate(fixed) + ' 吧';
    }
    if (ps && pe && pe < ps) {
        badPe = true;
        msg = msg || ('任務「' + name + '」的預計完成日不可早於預計開始日');
    }
    if (as && ae && ae < as) msg = msg || ('任務「' + name + '」的實際完成日不可早於實際開始日');
    $tr.find('.t-ps').toggleClass('fld-bad', badPs)
       .attr('title', badPs ? ('不可早於專案起日 ' + dispDate(pStart) + '（可在「專案基本資料」改專案起日）') : '');
    $tr.find('.t-pe').toggleClass('fld-bad', badPe).attr('title', badPe ? '預計完成日不可早於預計開始日' : '');
    return msg;
}

/** 把後續「還跟著上一列」的列一起往後推（使用者自己改過的開始日不覆蓋，遇到就停） */
function planChainFrom($tr) {
    var guard = 0;
    while (guard++ < 500) {
        var pe = $.trim($tr.find('.t-pe').val());
        var old = String($tr.attr('data-pe0') || '');
        $tr.attr('data-pe0', pe);
        var $next = $tr.next('tr');
        if (!$next.length || !pe) return;
        var $nps = $next.find('.t-ps');
        var cur = $.trim($nps.val());
        if (cur !== '' && cur !== old) return;        // 下一列的開始日是使用者自己填的，不動它
        if (cur === pe) { $tr = $next; continue; }    // 已經一致，往後檢查下一列
        $nps.val(pe);
        var ndy = num($next.find('.t-days').val());
        var npe = ndy > 0 ? planEndByDays(pe, ndy) : pe;
        $next.find('.t-pe').val(npe).removeClass('fld-bad').attr('title', '');
        if (!(ndy > 0)) $next.find('.t-days').val(planDaysBetween(pe, npe) || '');
        $tr = $next;
    }
}
$(document).on('change', '#planEditBox .t-ps', function () { planRowRecalc($(this).closest('tr'), 'ps'); });
/* 專案起日一改，規劃表上每一列都要重驗一次（本來合法的可能就變成早於專案起日了） */
$(document).on('change', '#eStart', function () {
    if (CUR && CUR.project) CUR.project.start_date = $(this).val();
    $('#planEditBox .t-body tr').each(function () { planRowCheck($(this)); });
});
$(document).on('change', '#planEditBox .t-pe', function () { planRowRecalc($(this).closest('tr'), 'pe'); });
$(document).on('input change', '#planEditBox .t-days', function () { planRowRecalc($(this).closest('tr'), 'days'); });

/* 換部門就重建人員下拉（推導欄位鐵則：來源一改就重算；原本那個人若在新部門底下仍在就保留） */
$(document).on('change', '#planEditBox .t-odept', function () {
    var $td = $(this).closest('td'), $own = $td.find('.t-owner');
    var el = $own[0];
    if (!el) return;
    /* 使用者自己換部門 → 只列這個部門的人；原本指派的人若不在這裡就清掉（推導欄位鐵則） */
    var pOpt = taskOwnerOptions(num($(this).val()), num($own.val()), false);
    el.innerHTML = pOpt;
    /* 換掉整批選項後一定要讓共用檔的篩選框重新快照，否則它會拿舊清單把新選項洗掉
       （eg_input_rules 規則7 提供的 egFilterResnap 就是給這種情況用的，不要自己動它的內部狀態） */
    if (el.egFiltered) { if (el.egFilterResnap) el.egFilterResnap(); return; }
    if ((pOpt.split('<option').length - 1) > 12) {
        el.setAttribute('data-eg-filter', '輸入姓名篩選…');
        if (window.egSelectFilterScan) window.egSelectFilterScan($td[0]);
    }
});

/* 共用檔 eg_input_rules.js 的可增列表格掛勾（禁各頁自刻增刪列邏輯）
   ※ 共用檔呼叫這兩支時「不帶參數」，所以要自己找出游標所在的那個 tbody
     （本頁一個目標一張表，畫面上會有很多個 tbody）。
     以前寫成 planRowAdd($tbody) 需要參數 → 呼叫時丟例外被共用檔的 try/catch 吃掉
     ＝按 ↓↑ 完全沒有反應也不報錯。 */
function planActiveTbody() {
    var el = document.activeElement;
    var tb = (el && el.closest) ? el.closest('#planEditBox tbody.t-body') : null;
    return tb ? $(tb) : $('#planEditBox tbody.t-body').last();
}
function planRowAdd() {
    var $tbody = planActiveTbody();
    if (!$tbody.length) return false;
    $tbody.append(planRowHtml({}, $tbody.find('tr').length));
    renumberPlan($tbody);
    /* 新列的預計開始＝上一列的預計完成（使用者要求的接續），天數留空＝當天來回。
       這裡只寫值不發事件，所以共用檔仍然認得「這列是剛加出來、還沒動過」，按 ↑ 一樣收得回去。 */
    var $new = $tbody.find('tr').last(), $prev = $new.prev('tr');
    var prevEnd = $prev.length ? $.trim($prev.find('.t-pe').val()) : '';
    if (prevEnd) { $new.find('.t-ps').val(prevEnd); planRowRecalc($new, 'ps'); }
    return true;
}
function planRowDel() {
    var $tbody = planActiveTbody();
    if (!$tbody.length) return false;
    return planRowRemove($tbody.find('tr').last());
}
function planRowRemove($tr) {
    var $tbody = $tr.closest('tbody');
    if ($tbody.find('tr').length <= 1) return false;   // 只剩一列時不刪
    $tr.remove();
    renumberPlan($tbody);
    return true;
}
function renumberPlan($tbody) { $tbody.find('tr').each(function (i) { $(this).find('td').first().text(i + 1); }); }

$(document).on('click', '#planEditBox .t-del', function () { planRowRemove($(this).closest('tr')); });
$(document).on('click', '#planEditBox .g-del', function () {
    if (!confirm('刪除這個目標？底下的任務會一起移除（要按「儲存規劃表」才會真的寫入）。')) return;
    $(this).closest('.sec[data-goal]').remove();
    /* 只從畫面上拿掉不夠：CUR 還留著那個目標，①上方時間軸不會跟著少一列
       ②之後任何一次重繪（改模組設定、首件狀態變動）都會把它從舊資料接回來，
       看起來就是「刪了又自己跑回來、儲存也沒被移除」。 */
    if (!planSyncToCur()) { CUR.goals = []; CUR.tasks = []; }
    drawPlanEditor(CUR);          // 順便把「目標 N」的編號重排
    drawGantt(CUR);
    PLAN_DIRTY = true; PLAN_HAS_DEL = true;
});
$(document).on('change', '#gView', function () { GVIEW = $(this).val(); drawGantt(CUR); });
$(document).on('change', '#gScale', function () { GSCALE = $(this).val(); drawGantt(CUR); });
$(document).on('click', '#btnSeed', function () {
    if (!planLeaveOk('帶入標準流程')) return;
    if (!confirm('帶入 AS9100 標準流程？\n\n會新增三個階段（前置審查與準備／備料與首件驗證／批量生產與結案）與底下的步驟。\n已經存在的階段不會重複建立，你自己排的內容也不會被覆蓋。')) return;
    api('seed_template', { project_id: CUR.project.project_id }, 'POST').done(function (r) {
        /* 範本是直接寫進資料庫的，重新載入時要用伺服器的新資料，
           不可以再把畫面上那份舊的接回去（使用者已在上面的確認視窗同意放棄未存的變更）。 */
        PLAN_DIRTY = false;
        loadList();
        openProject(num(CUR.project.project_id), function () { pjMsg(r.message, { ok: true }); });
    });
});
$(document).on('click', '#btnGoalAdd', function () {
    var dirty = PLAN_DIRTY;
    planSyncToCur();                       // 先保住畫面上填到一半的內容（不然會被重繪洗掉）
    CUR.goals = (CUR.goals || []).concat([{ goal_id: 0, goal_name: '', dept_name: '' }]);
    drawPlanEditor(CUR);
    PLAN_DIRTY = dirty;                    // drawPlanEditor 會清掉未儲存標記，這裡還原回去
});
/**
 * 把「畫面上填到一半、還沒存進資料庫」的規劃表內容收回 CUR。
 * 任何會重繪規劃表的動作（新增目標、改完模組設定…）都要先呼叫這支，
 * 否則 drawPlanEditor() 會拿伺服器載下來的舊資料重畫，使用者剛打的字就沒了
 * （使用者回報「按新增目標會把還沒儲存的目標與主要任務都清掉」＝這個原因）。
 */
function planSyncToCur() {
    if (!CUR || !$('#planEditBox .sec[data-goal]').length) return false;
    var goals = [], tasks = [], tmpId = 0;
    $('#planEditBox .sec[data-goal]').each(function (gi) {
        var gid = num($(this).data('goal'));
        /* 還沒存過的目標 goal_id 都是 0，直接照抄會讓多個新目標被 groupTasks 併成同一組，
           所以各給一個暫時的負數 id；送存時再還原成 0（見 savePlan）。 */
        if (gid <= 0) gid = --tmpId;
        var $dept = $(this).find('.g-dept'), deptTxt = $.trim($dept.find('option:selected').text());
        goals.push({
            goal_id: gid,
            goal_name: $(this).find('.g-name').val(),
            dept_id: num($dept.val()) || null,
            /* drawPlanEditor 是用「選項文字」比對回選部門的，所以這裡要存文字（「（無）」視同沒選） */
            dept_name: (deptTxt === '（無）' ? '' : deptTxt),
            sort_order: gi
        });
        $(this).find('.t-body tr').each(function (ti) {
            var $r = $(this), per = peopleById(num($r.find('.t-owner').val()));
            tasks.push({
                task_id: num($r.data('task')), goal_id: gid,
                task_name: $r.find('.t-name').val(),
                task_kind: String($r.data('kind') || ''),
                status_code: $r.find('.t-status').val() || '',
                plan_start: $r.find('.t-ps').val(), plan_end: $r.find('.t-pe').val(),
                act_start: $r.find('.t-as').val(), act_end: $r.find('.t-ae').val(),
                owner_id: num($r.find('.t-owner').val()) || null,
                owner_dept_id: num($r.find('.t-odept').val()) || null,
                owner_name: per ? per.user_cname : '',
                progress: num($r.find('.t-pg').val()),
                progress_auto: $r.find('.t-pgauto').is(':checked') ? 1 : 0,
                is_milestone: $r.find('.t-ms').is(':checked') ? 1 : 0,
                sort_order: ti
            });
        });
    });
    CUR.goals = goals;
    CUR.tasks = tasks;
    return true;
}

/** 規劃表上有沒有東西要存（一個有名稱的目標都沒有＝沒東西可存） */
function planHasContent() {
    if (!$('#planEditBox .sec[data-goal]').length) return false;
    var has = false;
    $('#planEditBox .g-name').each(function () { if ($.trim($(this).val())) has = true; });
    return has;
}
/**
 * 存執行規劃表。cb(true) ＝存好了；cb(false) ＝驗證沒過或存檔失敗（已經提示過原因）。
 * 底部「儲存」與工具列「儲存規劃表」共用這一份，兩邊規則不會走鐘。
 */
function savePlan(pid, cb) {
    var goals = [], tasks = [], bad = '';
    $('#planEditBox .sec[data-goal]').each(function (gi) {
        var gkey = 'g' + gi;
        var name = $.trim($(this).find('.g-name').val());
        if (!name) {
            /* 整個目標區塊都沒填（多半是按了「新增目標」又沒用到）＝直接略過，不是錯誤。
               只有「有填任務、卻沒給目標名稱」才擋下來，否則使用者會被一個空白區塊卡住存不了檔。 */
            var used = false;
            $(this).find('.t-body tr').each(function () {
                if ($.trim($(this).find('.t-name').val())) used = true;
            });
            if (used) bad = bad || ('目標 ' + (gi + 1) + ' 沒有名稱（底下已經有主要任務，請補上目標名稱，或把那些任務刪掉）');
            return;
        }
        goals.push({ goal_key: gkey, goal_id: Math.max(0, num($(this).data('goal'))), goal_name: name,
                     dept_id: $(this).find('.g-dept').val() });
        $(this).find('.t-body tr').each(function () {
            var tn = $.trim($(this).find('.t-name').val());
            if (!tn) return;   // 空白列直接略過（不是錯誤）
            var ps = $(this).find('.t-ps').val(), pe = $(this).find('.t-pe').val();
            var as = $(this).find('.t-as').val(), ae = $(this).find('.t-ae').val();
            bad = bad || planRowCheck($(this));   // 畫面上的即時檢查與存檔前的檢查共用同一份規則
            tasks.push({
                goal_key: gkey, task_id: num($(this).data('task')), task_name: tn,
                task_kind: String($(this).data('kind') || ''),
                status_code: $(this).find('.t-status').val() || '',
                plan_start: ps, plan_end: pe, act_start: as, act_end: ae,
                owner_id: $(this).find('.t-owner').val(),
                owner_dept_id: $(this).find('.t-odept').val(),
                progress: $(this).find('.t-pg').val(),
                progress_auto: $(this).find('.t-pgauto').is(':checked') ? 1 : 0,
                is_milestone: $(this).find('.t-ms').is(':checked') ? 1 : 0
            });
        });
    });
    if (bad) {
        /* 第一個出錯的欄位：捲進畫面＋標紅＋聚焦，使用者一眼就知道要改哪裡 */
        var $first = $('#planEditBox .fld-bad').first();
        $('.pj-tab[data-pane="panePlan"]').click();
        pjMsg(bad, { sub: '（請修正後再按儲存；上面標紅的就是要改的欄位）',
                     focus: $first.length ? $first : null });
        if ($first.length) {
            var el = $first[0];
            if (el.scrollIntoView) setTimeout(function () { el.scrollIntoView({ block: 'center' }); }, 260);
        }
        if (cb) cb(false);
        return;
    }
    /* 送出空的目標清單＝把伺服器上的目標與任務全部刪掉。正常刪除是這樣沒錯，
       但萬一畫面因為某種原因沒畫出來就按到儲存，會整份被清空，所以一定要問一次。 */
    if (!goals.length) {
        var had = ((CUR && CUR.goals) || []).length;
        if (had && !confirm('這樣會刪掉這個專案的全部 ' + had + ' 個目標與底下的任務。\n\n確定要清空執行規劃表嗎？')) {
            if (cb) cb(false);
            return;
        }
    }
    api('plan_save', { project_id: num(pid), goals: JSON.stringify(goals), tasks: JSON.stringify(tasks) }, 'POST')
        .done(function (res) { PLAN_DIRTY = false; PLAN_HAS_DEL = false; if (cb) cb(true, res); })
        .fail(function () { if (cb) cb(false); });
}
$(document).on('click', '#btnPlanSave', function () {
    savePlan(num(CUR.project.project_id), function (ok, res) {
        if (!ok) return;
        var m = (res && res.message) || '已儲存執行規劃表';
        loadList();
        openProject(num(CUR.project.project_id), function () { pjMsg(m, { ok: true }); });
    });
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
        CARD_DIRTY = false;
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
        .done(function (r) { CARD_DIRTY = false; alert(r.message); openCard(cid); });
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

    /* 報工紀錄（使用者要求）：廠內每日報工＋委外轉出入，一律唯讀 */
    h += '<div class="sec"><h5>報工紀錄（唯讀，來自生產現場）</h5>';
    var wr = res.work_reports || [];
    if (!wr.length) {
        h += '<div class="pj-hint">這些製令目前還沒有報工紀錄。'
           + '<b>廠內製程</b>的紀錄來自每日製程報工（機台、上機／生產人員、產出數）；'
           + '<b>委外製程</b>沒有廠內報工，實績看的是轉出入紀錄（轉出入日期、數量、損耗）。</div>';
    } else {
        h += '<div style="overflow-x:auto;max-height:340px;overflow-y:auto;"><table class="sub-tbl"><thead><tr>'
          + '<th style="width:88px;">日期</th><th style="width:56px;">類型</th>'
          + '<th style="width:110px;">製令單</th><th style="width:46px;">順序</th><th>製程</th>'
          + '<th style="width:110px;">機台／廠商</th><th style="width:150px;">人員／轉出入</th>'
          + '<th style="width:64px;">數量</th><th style="width:56px;">狀態</th><th>備註</th></tr></thead><tbody>';
        $.each(wr, function (i, r) {
            var isIn = (r.kind === 'in');
            h += '<tr><td>' + dispDate(r.rdate) + '</td>'
              + '<td>' + (isIn ? '<span class="st st-approved">廠內</span>' : '<span class="st st-submitted">委外</span>') + '</td>'
              + '<td>' + esc(r.bom || '') + '</td><td>' + num(r.bom_sn) + '</td>'
              + '<td>' + esc(r.process_name || ('製程' + num(r.process_no))) + '</td>'
              + '<td>' + esc(isIn ? (r.machine_name || '－') : (r.maker_to_name || r.maker_from_name || '－')) + '</td>'
              + '<td>' + esc(isIn
                    ? ((r.setup_user ? '上機 ' + r.setup_user + '　' : '') + (r.prod_user ? '生產 ' + r.prod_user : '') || '－')
                    : ((r.maker_from_name || '?') + ' → ' + (r.maker_to_name || '?'))) + '</td>'
              + '<td>' + num(r.qty) + (num(r.loss_qty) ? '<br><span class="pj-hint">損耗 ' + num(r.loss_qty) + '</span>' : '') + '</td>'
              + '<td>' + (isIn ? (num(r.is_finished) ? '<span class="st st-approved">完工</span>' : '進行中') : '－') + '</td>'
              + '<td>' + esc(r.note || '') + '</td></tr>';
        });
        h += '</tbody></table></div>'
          + '<p class="pj-hint">共 ' + wr.length + ' 筆，依日期由新到舊。這些是生產現場登打的原始紀錄，本頁只顯示不修改。</p>';
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
    var phase = (CUR && CUR.doc_phase) || META.doc_phase || {};
    var passed = !!(CUR && CUR.fai_pass_date);
    var h = '<p class="pj-hint">每個料號都應該有這些技術文件，<b>括號內是版次／編號</b>。'
          + '<b>✗ 可以直接點下去開啟對應頁面、自動開建立跳窗並帶入已有資料</b>。<br>'
          + '欄名標 <b class="chk-before">［首件前］</b> 的（PFMEA／SOP／SIP）要在<b>送首件檢驗之前</b>備妥'
          + '——首件驗證的就是這套製程與這份文件，沒有它們就沒有判定依據（AS9102／AS9145）；'
          + '標 <b class="chk-after">［首件後］</b> 的（型態識別文件管制表）等首件<b>通過之後</b>再建立，'
          + '它記錄的是這批文件的版本組合。'
          + (passed ? '　目前狀態：<b>首件已通過（' + dispDate(CUR.fai_pass_date) + '）</b>。'
                    : '　目前狀態：<b>首件尚未通過</b>，［首件後］的文件先不列入缺件。') + '</p>'
          + '<div class="pj-table-wrap"><table class="pj-table"><thead><tr><th>料號</th><th style="width:110px;">客戶</th>';
    $.each(defs, function (k, d) {
        var ph = phase[k] || 'any';
        h += '<th style="width:150px;">' + esc(d[0])
          + (ph === 'before' ? '<br><span class="chk-before">［首件前］</span>' : '')
          + (ph === 'after' ? '<br><span class="chk-after">［首件後］</span>' : '') + '</th>';
    });
    h += '<th style="width:64px;">缺件</th></tr></thead><tbody>';
    $.each(rows, function (i, r) {
        h += '<tr><td class="l"><b>' + esc(r.part_no) + '</b></td><td>' + esc(r.customer_name || '') + '</td>';
        $.each(defs, function (k, d) {
            var ph = phase[k] || 'any', rev = r[k + '_rev'] || '';
            if (num(r[k])) {
                h += '<td><span class="chk-y">✓ 已建立</span>'
                  + (rev ? '<br><span class="pj-hint">' + esc(rev) + '</span>' : '') + '</td>';
            } else if (ph === 'after' && !passed) {
                h += '<td><span class="pj-hint" title="首件通過後才需要建立">－ 首件通過後</span></td>';
            } else {
                h += '<td><span class="chk-n" data-go="' + esc(d[1]) + '" data-kw="' + esc(r.part_no) + '"'
                  + ' data-doc="' + esc(k) + '" data-ds="' + num(r.ds_pk) + '">✗ 未建立</span>'
                  + (ph === 'before' ? '<br><span class="chk-before">送首件前應備</span>' : '') + '</td>';
            }
        });
        h += '<td>' + (num(r.missing) ? '<span class="pj-miss-badge">' + num(r.missing) + '</span>' : '<span class="pj-ok-badge">齊全</span>') + '</td></tr>';
    });
    h += '</tbody></table></div>';
    $('#paneChk').html(h);
}
$(document).on('click', '.chk-n', function () {
    /* 除了帶料號過去搜尋，另外帶 prj_new=1 與專案資訊：
       目標頁看到這組參數就自動開「建立」跳窗並把已知的資料預填進去（使用者要求）。 */
    var p = CUR ? CUR.project : {};
    var q = '?kw=' + encodeURIComponent($(this).data('kw'))
          + '&prj_new=1'
          + '&ds_pk=' + num($(this).data('ds'))
          + '&doc=' + encodeURIComponent($(this).data('doc') || '')
          + '&project_id=' + num(p.project_id)
          + '&project_no=' + encodeURIComponent(p.project_no || '')
          + '&project_name=' + encodeURIComponent(p.project_name || '')
          + '&customer_id=' + encodeURIComponent(p.customer_id || '')
          + '&fai_date=' + encodeURIComponent((CUR && CUR.fai_pass_date) || '');
    window.open($(this).data('go') + q, '_blank');
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
    if (!planLeaveOk('送簽')) return;
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
    if (!planLeaveOk('核准')) return;
    var h = '<div class="sec"><label>核准日期</label><input type="date" id="apDate" value="' + esc(META.today) + '">'
          + '<label style="margin-top:8px;">備註（非必填）</label><textarea id="apNote" rows="3"></textarea></div>';
    showDialog('核准專案', h, function () {
        doDecide('approve', $('#apDate').val(), $('#apNote').val(), 0);
        return false;
    });
});
$(document).on('click', '#btnReject', function () {
    if (!planLeaveOk('退回')) return;
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
    if (!planLeaveOk('結案')) return;
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

/* ══════════════════════════ 錯誤提示條 ══════════════════════════
   使用者明確要求：錯誤不要再用瀏覽器 alert（「根本不會認真看提示內容」），
   改成跳窗內粉紅底、捲到哪都看得到的提示條，並且自動把出錯的欄位捲進畫面、標紅、聚焦。 */
function pjMsg(msg, opt) {
    opt = opt || {};
    var $m = $('#prjMsg');
    if (!$m.length) { alert(msg); return; }
    $m.removeClass('ok').toggleClass('ok', !!opt.ok)
      .html('<span class="x" title="關閉">✕</span>' + esc(msg)
            + (opt.sub ? '<span class="sub">' + esc(opt.sub) + '</span>' : ''))
      .addClass('show').show();
    /* 捲到最上面才看得到提示條 */
    var $body = $m.closest('.m-body');
    if ($body.length) $body.animate({ scrollTop: 0 }, 150);
    if (opt.focus && $(opt.focus).length) {
        var $f = $(opt.focus).first();
        $f.addClass('fld-bad');
        setTimeout(function () { try { $f[0].focus(); $f[0].select && $f[0].select(); } catch (e) {} }, 200);
    }
    if (opt.ok) setTimeout(function () { pjMsgClear(); }, 4000);
}
function pjMsgClear() { $('#prjMsg').removeClass('show').hide().empty(); }
$(document).on('click', '#prjMsg .x', function () { pjMsgClear(); });
window.pjMsg = pjMsg;

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

        /* 執行規劃表負責人部門（複選；勾了就連子部門一起帶出來）。
           部門是樹狀的，縮排顯示才看得出來勾的是上層還是某一個組。 */
        var tsel = $.map(String(s.task_owner_depts || '').split(','), num);
        var th = '';
        $.each(META.depts || [], function (i, d) {
            var on = $.inArray(num(d.id), tsel) >= 0;
            var lv = Math.max(0, num(d.level) - 1);
            th += '<span class="pj-tag' + (on ? ' on' : '') + '" data-settaskdept="' + d.id + '"'
                + (lv ? ' style="margin-left:' + (lv * 14) + 'px;"' : '') + '>'
                + (lv ? '└ ' : '') + esc(d.name) + '</span>';
        });
        $('#setTaskDeptBox').html(th);
        renderTaskDeptCount();

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
$(document).on('click', '#setTaskDeptBox .pj-tag', function () { $(this).toggleClass('on'); renderTaskDeptCount(); });
function pickedTaskDepts() {
    var out = [];
    $('#setTaskDeptBox .pj-tag.on').each(function () { out.push(num($(this).data('settaskdept'))); });
    return out;
}
/* 勾了幾個、實際會涵蓋哪些部門（含子部門）——只勾上層時很容易以為子部門沒被帶到 */
function renderTaskDeptCount() {
    var picked = pickedTaskDepts();
    if (!picked.length) { $('#setTaskDeptCount').text('目前未勾選任何部門＝不限制，負責人的部門下拉會列出全部部門。'); return; }
    var all = [];
    $.each(picked, function (i, d) {
        $.each(deptSubtreeIds(d), function (k, x) { if ($.inArray(x, all) < 0) all.push(x); });
    });
    var names = $.map(all, function (x) { var d = deptById(x); return d ? d.name : null; });
    $('#setTaskDeptCount').text('已勾選 ' + picked.length + ' 個部門，含子部門實際涵蓋 ' + all.length + ' 個：' + names.join('、'));
}
$(document).on('click', '#btnSetSave', function () {
    var cos = [];
    $('#setCosignBox .pj-tag.on').each(function () { cos.push(num($(this).data('setcos'))); });
    api('setting_save', {
        approver_user_id: $('#setApUser').val(), approver_dept_id: $('#setApDept').val(),
        default_cosign_depts: cos.join(','),
        block_close_on_missing: $('#setBlockClose').is(':checked') ? '1' : '0',
        plan_stamp_tpl_id: $('#setPlanTpl').val() || '0', card_stamp_tpl_id: $('#setCardTpl').val() || '0',
        task_owner_depts: pickedTaskDepts().join(','),
        owner_scope: JSON.stringify($.map(OWN_SCOPE, function (r) { return { d: num(r.d), p: num(r.p) }; }))
    }, 'POST').done(function (r) {
        alert(r.message);
        META.default_cosign_depts = cos.join(',');
        /* 部門設定改完馬上生效：規劃表的負責人部門下拉同步換掉，不必重新整理頁面 */
        META.task_owner_depts = r.task_owner_depts || [];
        if (CUR && num(CUR.project.project_id) && CUR.can_edit) {
            var dirty = PLAN_DIRTY;
            planSyncToCur();               // 同上：不可以把使用者填到一半的規劃表洗掉
            drawPlanEditor(CUR);
            PLAN_DIRTY = dirty;
        }
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
