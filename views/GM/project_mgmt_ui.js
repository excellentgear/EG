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
    /* 篩選用 types_all＝連停用的也列（既有專案還掛在那些性質上，不列就篩不到） */
    var t = $('#fType').empty().append('<option value="">全部性質</option>');
    $.each(META.types_all || META.types || {}, function (k, v) {
        t.append('<option value="' + k + '">' + esc(v + '（' + k + '）') + '</option>');
    });
    var ph = $('#fPhase').empty().append('<option value="">全部階段</option>');
    $.each(META.phases || {}, function (k, v) { ph.append('<option value="' + k + '">' + esc(v) + '</option>'); });
    var ow = $('#fOwner').empty().append('<option value="">全部負責人</option>');
    $.each(META.people || [], function (i, p) { ow.append('<option value="' + p.id + '">' + esc(peopleLabel(p)) + '</option>'); });
    renderTagFilter();
}

/* 人員顯示：部門/職稱/姓名，長期請假要標假別期間（ai-rules/08 第五節） */
/* 人員下拉的顯示字串：一律「主部門 主職稱 姓名（兼 …）」。
   eg_people_list() 一人只回一列、挑的是職級最高那筆，所以兼任職級比主職高的人
   （主職 技術課 工程師、兼任 生管組 組長）原本只印得出兼任身分、主職整個看不到
   ——使用者 2026-09-22 回報的就是這個。後端 eg_people_annotate_posts() 已把主職務
   放進 main_dept_name／main_position_name，其餘職務放在 alt_posts。 */
function peopleLabel(p) {
    var d = p.main_dept_name || p.dept_name || '', j = p.main_position_name || p.position_name || '';
    var s = (d ? d + ' ' : '') + (j ? j + ' ' : '') + (p.user_cname || '');
    var alt = p.alt_posts || [];
    if (alt.length) {
        var t = [];
        $.each(alt, function (i, a) { t.push(((a.dept_name || '') + ' ' + (a.position_name || '')).replace(/\s+/g, ' ').replace(/^ | $/g, '')); });
        s += '（兼 ' + t.join('、') + '）';
    }
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
        var open = $.inArray(num(r.project_id), LIST_OPEN) >= 0;
        h += '<tr class="pj-row" data-pid="' + r.project_id + '">'
          + '<td><b>' + esc(r.project_no) + '</b>'
          + '<span class="pj-exp" data-exp="' + r.project_id + '" title="就地展開／收合進度">'
          + '<i class="fa fa-' + (open ? 'caret-down' : 'caret-right') + '"></i></span></td>'
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
        if (open) {
            h += '<tr class="pj-exp-row" data-exprow="' + r.project_id + '"><td colspan="14" style="padding:8px 10px;background:#FFFCF7;">'
              + '<div class="pj-inline-gantt" data-pid="' + r.project_id + '">'
              + '<span class="pj-hint">載入中…</span></div></td></tr>';
        }
    });
    $('#listBody').html(h);
    /* 展開的列各自把進度畫進去（資料抓過就留在 PLAN_CACHE，換頁再回來不會重打 API） */
    $.each(LIST_OPEN, function (i, pid) { fillInlineGantt(pid); });
}

/* ── 清單就地展開甘特進度（使用者要求：點選就自動在前端展開甘特圖進度）──
   刻意不整包呼叫 get（那支會順路同步 BOM、算文件檢核，重很多），
   只要目標與任務就夠畫圖了。 */
function fillInlineGantt(pid) {
    var $box = $('.pj-inline-gantt[data-pid="' + pid + '"]');
    if (!$box.length) return;
    var draw = function (res) {
        $box.html(ganttHtml(res, { hideDone: HIDE_DONE, scale: 'week', compact: true })
            + '<div class="pj-hint" style="margin-top:4px;">'
            + (res.tasks || []).length + ' 個步驟。點「檢視」開啟專案可以編輯。</div>');
    };
    if (PLAN_CACHE[pid]) { draw(PLAN_CACHE[pid]); return; }
    api('plan_rows', { project_id: pid }).done(function (r) {
        PLAN_CACHE[pid] = { project: r.project || {}, goals: r.goals || [], tasks: r.tasks || [] };
        draw(PLAN_CACHE[pid]);
    });
}

$(document).on('click', '[data-exp]', function (e) {
    e.stopPropagation();
    var pid = num($(this).data('exp'));
    var at = $.inArray(pid, LIST_OPEN);
    if (at >= 0) LIST_OPEN.splice(at, 1); else LIST_OPEN.push(pid);
    renderList();
});

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

/** 標頭上的料號連結（點開圖面檢視跳窗）。料號多時全部列出來，不要只挑一個。 */
function partLinksHtml(res) {
    var parts = res.parts || [];
    if (!parts.length) return '';
    var h = '<span class="prj-parts">';
    $.each(parts, function (i, x) {
        h += '<span class="prj-part" data-viewpart="' + num(x.ds_pk) + '" data-partno="' + esc(x.part_no || '')
          + '" title="開啟圖面檢視"><i class="fa fa-picture-o"></i> ' + esc(x.part_no || '') + '</span>';
    });
    return h + '</span>';
}

/**
 * 把站上**既有的那一頁**嵌進跳窗（唯一實作）。圖面查閱、訂單追蹤、BOM 總表、
 * 出貨紀錄、報工紀錄全部走這一支——不要在專案管理裡另外刻五個看資料的畫面（鐵律4）。
 * 同源所以載入後可以把它自己的側欄／頁首藏掉，看起來就像本頁的跳窗；
 * **刻意不去改那些頁面本身**（只有「用網址帶篩選條件」那一小段是加在對方頁面上的）。
 */
function openPageModal(title, url) {
    $('#pvTitle').text(title);
    $('#pvOpen').attr('href', url);
    $('#pvBody').html('<div class="pj-hint" style="padding:14px;">載入中…</div>'
        + '<iframe id="pvFrame" src="' + url + '" style="width:100%;height:72vh;border:0;display:none;"></iframe>');
    openMask('pvMask');
    $('#pvFrame').on('load', function () {
        $(this).prev('.pj-hint').remove();
        $(this).show();
        try {
            var d = this.contentDocument;
            if (!d) return;                       // 跨來源就算了，照樣看得到內容
            var s = d.createElement('style');
            s.textContent = '.left_col,.top_nav,footer,.nav_menu{display:none!important;}'
                          + '.right_col{margin-left:0!important;padding:8px!important;min-height:0!important;}'
                          + 'body{background:#fff!important;}';
            d.head.appendChild(s);
        } catch (e) { /* 藏不掉就維持原樣，不影響檢視 */ }
    });
}

/* 圖面檢視：views/pm/bom_viewer.php（使用者指定就是要這一頁） */
$(document).on('click', '[data-viewpart]', function () {
    var pk = num($(this).data('viewpart')), pn = String($(this).data('partno') || '');
    openPageModal('圖面檢視：' + pn,
        '/EGsystem/views/pm/bom_viewer.php?pk=' + pk + '&d_id=' + encodeURIComponent(pn));
});
/* 訂單編號 → 訂單追蹤，並自動篩出「這個訂單編號＋這個料號」那一列
   （只帶單號不夠：同一個訂單編號會有好幾列，拆批與同編號多料號都會一起列出來） */
$(document).on('click', '[data-vieworder]', function () {
    var no = String($(this).data('vieworder') || ''), pn = String($(this).data('partno') || '');
    openPageModal('訂單追蹤：' + no,
        '/EGsystem/views/Sales/NewOrder_Track.php?kw=' + encodeURIComponent(no)
        + '&part=' + encodeURIComponent(pn));
});
/* 製令單號 → BOM 總表（它吃 ?b=製令單號） */
$(document).on('click', '[data-viewbom]', function () {
    var b = String($(this).data('viewbom') || '');
    openPageModal('BOM 總表：' + b, '/EGsystem/views/pm/OreadyReply_ForPm_BaseOfTime.php?b=' + encodeURIComponent(b));
});
/* 出貨單號 → 出貨紀錄分析，自動篩出這張單號 */
$(document).on('click', '[data-viewship]', function () {
    var no = String($(this).data('viewship') || ''), pn = String($(this).data('partno') || '');
    openPageModal('出貨紀錄：' + no,
        '/EGsystem/views/Sales/Shipping_Analysis.php?is_no=' + encodeURIComponent(no)
        + '&product=' + encodeURIComponent(pn));
});
/* 報工 → 報工紀錄查詢，自動篩出這張製令／這個料號 */
$(document).on('click', '[data-viewwork]', function () {
    var b = String($(this).data('viewwork') || ''), pn = String($(this).data('partno') || '');
    openPageModal('報工紀錄：' + (b || pn),
        '/EGsystem/views/pm/process_report_query.php?bom=' + encodeURIComponent(b)
        + '&part=' + encodeURIComponent(pn));
});

function newProjectShell() {
    return {
        project: { project_id: 0, project_type: 'C', phase: 'initiating', status: 'draft', progress: 0 },
        goals: [], tasks: [], orders: [], parts: [], processes: [], shipments: [], cards: [], cosigns: [],
        alerts: [], doc_check: [], can_edit: true, can_approve: false
    };
}

function renderDetail(res) {
    pjMsgClear();
    var p = res.project;
    /* 標頭的料號做成可點（使用者要求）：點下去開圖面檢視跳窗。
       料號來自 project_part（訂單帶出的＋手動掛的），**不從專案名稱去猜**——
       專案名稱是自由文字，猜錯就會開到別的料號而且看不出來。 */
    $('#prjTitle').html('<i class="fa fa-folder-open-o"></i> '
        + (p.project_id ? esc(p.project_no + '　' + p.project_name) : '新增專案')
        + partLinksHtml(res));
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
    /* 送簽之後性質鎖住（後端 save 同規則再擋一次） */
    var typeLocked = num(p.project_id) > 0 && $.inArray(String(p.status), ['draft', 'rejected']) < 0;
    var typeOpt = '', ownerOpt = '<option value="">（請選擇）</option>', custOpt = '<option value="">（無）</option>', deptOpt = '<option value="">（無）</option>';
    $.each(META.types || {}, function (k, v) { typeOpt += '<option value="' + k + '"' + (p.project_type === k ? ' selected' : '') + '>' + esc(v + '（' + k + '）') + '</option>'; });
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
      /* 送簽之後不可以再改專案性質（使用者指定）——性質是專案代號的第一碼，號碼一送簽就跟著文件出去了。
         後端 save 同規則再擋一次（鐵律8）。 */
      + '<div id="fldType"><label>專案性質 <span style="color:#DD5138;">*</span>'
      + (typeLocked ? '<span class="pj-hint" style="margin-left:6px;">（已送簽，不可更改）</span>' : '')
      + '</label><select id="eType"' + (typeLocked ? ' disabled' : ro) + '>' + typeOpt + '</select>'
      + '<div class="pj-err"></div></div>'
      /* 目前階段改成系統自動判斷（prj_phase_auto），不再讓人選（使用者指定，並已取消「籌備」） */
      + '<div><label>目前階段 <span class="pj-hint">（系統自動判斷）</span></label>'
      + '<input type="text" class="ro-auto" readonly value="' + esc(p.phase_label || '規劃') + '"></div>'
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
      + '<div style="grid-column:1 / -1;"><label>專案分類標籤</label><div class="pj-tagbar" id="eTagBar"></div></div>'
      + '<div style="grid-column:1 / -1;">' + scopeProcHtml(res) + '</div>'
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

/* ── 專案涵蓋的製程（使用者 2026-09-22 指定）──
   一個都不勾＝整張 BOM 的所有製程（不是「都不算」）——這是使用者定的預設語意，
   所以既有專案不必回頭設定、行為完全不變。候選只列**這個專案的 BOM 上真的有的製程**，
   綁一道 BOM 上根本沒有的製程會永遠偵測不到進度而且不報錯。 */
function scopeProcHtml(res) {
    var p = res.project, cands = res.scope_candidates || [];
    var sel = String(p.scope_process_no || '').split(',').map(num);
    var h = '<label>專案涵蓋的製程 <span class="pj-hint">（不勾＝整張 BOM 的所有製程）</span></label>';
    if (!num(p.project_id)) {
        return h + '<div class="pj-hint">存檔並綁定訂單、BOM 同步進來之後才挑得到製程。</div>';
    }
    if (!cands.length) {
        return h + '<div class="pj-hint">這個專案還沒有 BOM 製程可以挑（到「關聯資料」按「同步 BOM」，或等製令開立）。'
             + '目前等同<b>整張 BOM 所有製程</b>。</div>';
    }
    h += '<div class="pj-tagbar" id="eScopeBar">';
    $.each(cands, function (i, c) {
        var on = $.inArray(num(c.process_no), sel) >= 0;
        h += '<span class="pj-tag' + (on ? ' on' : '') + (res.can_edit ? '' : ' ro') + '" data-scope="' + c.process_no + '">'
          + esc(c.process_name) + '</span>';
    });
    h += '</div><div class="pj-hint" id="eScopeHint">' + scopeHintText(sel, cands) + '</div>';
    return h;
}
function scopeHintText(sel, cands) {
    var on = [];
    $.each(cands || [], function (i, c) { if ($.inArray(num(c.process_no), sel) >= 0) on.push(c.process_name); });
    return on.length
        ? '目前只涵蓋 ' + on.length + ' 道：<b>' + esc(on.join('、')) + '</b>（其餘製程仍看得到，但不列入本專案的進度與統計）'
        : '未指定＝<b>整張 BOM 的所有製程</b>都算本專案。';
}
/* 勾選只改畫面，跟其他欄位一起按「儲存」才寫入（避免點一下就送一次 API） */
/* ══════════════════════════ 專案性質（管理員維護） ══════════════════════════
   每一列自己存檔（不跟著「儲存設定」走）——代號是專案編號的第一碼，
   混在整頁存檔裡按一次就改掉好幾種，風險太高。 */
function loadTypes() {
    if (!$('#typeBody').length) return;
    api('type_list').done(function (r) {
        var rows = r.rows || [], h = '';
        $.each(rows, function (i, x) {
            var ro = PERM.canAdmin ? '' : ' readonly';
            h += '<tr data-tc="' + esc(x.type_code) + '">'
              + '<td class="c"><b>' + esc(x.type_code) + '</b></td>'
              + '<td><input type="text" class="tn" maxlength="20" value="' + esc(x.type_name) + '"' + ro + '></td>'
              + '<td><input type="number" class="ts" value="' + num(x.sort_order) + '"' + ro + '></td>'
              + '<td class="c"><input type="checkbox" class="ta" data-eg-skip="1"' + (num(x.is_active) ? ' checked' : '')
              + (PERM.canAdmin ? '' : ' disabled') + '></td>'
              + '<td class="c">' + (num(x.used) ? '<b>' + num(x.used) + '</b>' : '－') + '</td>'
              + '<td>' + (PERM.canAdmin
                    ? '<span class="pj-op" data-tsave="' + esc(x.type_code) + '">儲存</span>'
                      + '<span class="pj-op" data-tdel="' + esc(x.type_code) + '" style="color:#DD5138;">刪除</span>'
                    : '<span class="pj-hint">－</span>') + '</td></tr>';
        });
        $('#typeBody').html(h || '<tr><td colspan="6" style="padding:10px;color:#8a6d45;">（沒有任何專案性質）</td></tr>');
    });
}
$(document).on('click', '[data-tsave]', function () {
    var $r = $(this).closest('tr');
    api('type_save', { type_code: String($r.data('tc')), type_name: $r.find('.tn').val(),
                       sort_order: $r.find('.ts').val(), is_active: $r.find('.ta').is(':checked') ? 1 : 0,
                       is_new: 0 }, 'POST')
        .done(function (r) { alert(r.message); loadTypes(); reloadTypeOptions(); });
});
$(document).on('click', '#btnTypeAdd', function () {
    var code = $.trim($('#ntCode').val()).toUpperCase(), name = $.trim($('#ntName').val());
    var $e = $('#ntErr');
    if (!/^[A-Z]$/.test(code)) { $e.show().text('代號只能是一個英文字母（A~Z）'); return; }
    if (!name) { $e.show().text('請填性質名稱'); return; }
    $e.hide().text('');
    api('type_save', { type_code: code, type_name: name, sort_order: $('#ntSort').val(), is_active: 1, is_new: 1 }, 'POST')
        .done(function (r) {
            alert(r.message);
            $('#ntCode').val(''); $('#ntName').val('');
            loadTypes(); reloadTypeOptions();
        });
});
/* 刪除：有專案在用時後端會回 need_move，這裡跳出移轉選單讓人挑要移到哪一種（使用者指定的流程） */
$(document).on('click', '[data-tdel]', function () {
    var code = String($(this).data('tdel'));
    var go = function (moveTo) {
        api('type_delete', moveTo ? { type_code: code, move_to: moveTo } : { type_code: code }, 'POST')
            .done(function (r) {
                if (num(r.need_move)) {
                    var opts = $.map(r.others || [], function (o) { return o.code + '＝' + o.name; }).join('\n');
                    var pick = prompt(r.message + '\n\n要把這 ' + r.used + ' 個專案移轉到哪一種？請輸入代號：\n' + opts
                        + '\n\n（移轉不會改動既有的專案代號——那是立案當下就發出去、也印在紙本上的編號）', '');
                    if (pick === null) return;
                    pick = $.trim(pick).toUpperCase();
                    if (!pick) return;
                    go(pick);
                    return;
                }
                alert(r.message);
                loadTypes(); reloadTypeOptions(); loadList();
            });
    };
    if (!confirm('刪除專案性質「' + code + '」？\n\n如果還有專案在用，系統會先要你把它們移轉到其他性質。')) return;
    go('');
});
/* 性質改過之後，畫面上其他地方的下拉也要跟著換，不必重新整理整頁 */
function reloadTypeOptions() {
    api('meta').done(function (res) {
        META.types = res.types || META.types;
        META.types_all = res.types_all || META.types_all;
        renderTypeFilter();
    });
}
function renderTypeFilter() {
    var cur = $('#fType').val();
    fillMeta();                 // 篩選列的下拉一律由 fillMeta() 產生，不要在這裡再寫一份（鐵律4）
    $('#fType').val(cur);
}

/* 設定頁的「加工圖面標籤」勾選（按「儲存設定」才寫入） */
$(document).on('click', '#setDwgCats .pj-tag[data-dwgcat], #setO2pCats .pj-tag[data-o2pcat],'
              + ' #setSopScopes .pj-tag[data-scp], #setSipScopes .pj-tag[data-scp]', function () {
    $(this).toggleClass('on');
});

$(document).on('click', '#eScopeBar .pj-tag[data-scope]', function () {
    if ($(this).hasClass('ro')) return;
    $(this).toggleClass('on');
    var sel = $('#eScopeBar .pj-tag.on').map(function () { return num($(this).data('scope')); }).get();
    $('#eScopeHint').html(scopeHintText(sel, (CUR || {}).scope_candidates || []));
    PLAN_DIRTY = PLAN_DIRTY;   // 基本資料自己有存檔鈕，不借用規劃表的未存旗標
});
function scopeProcValue() {
    if (!$('#eScopeBar').length) return String((CUR && CUR.project && CUR.project.scope_process_no) || '');
    return $('#eScopeBar .pj-tag.on').map(function () { return num($(this).data('scope')); }).get().join(',');
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
        start_date: $('#eStart').val(), end_date: $('#eEnd').val(),
        tag_ids: pickedTags('eTagBar'),
        scope_process_no: scopeProcValue(),
        goal_desc: $('#eGoalDesc').val(), purpose: $('#ePurpose').val()
    };
}

function saveBase(after) {
    var d = collectBase();
    /* 前端先驗一次（同一套規則），錯的欄位當場標紅 */
    var err = {};
    if (!d.project_name) err.project_name = '請填專案名稱';
    if (!d.project_type) err.project_type = '請選擇專案性質';
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
        /* 自動送簽核准（後端 auto_sign 早就寫好了，但一直沒有任何入口，等於沒做）。
           只有專案管理員看得到；補歷史專案或不需要跑簽核流程時用，簽核時間依 ai-rules/21 錯開且不跨日。 */
        if (PERM.canAdmin && p.status !== 'approved' && p.status !== 'closed') {
            h += '<button id="btnAutoSign" title="不跑簽核流程，直接標記為已送簽＋已核准（會留下自動簽核紀錄）">'
               + '<i class="fa fa-bolt"></i> 自動送簽核准</button>';
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
    /* 檢視方式是「這個專案的設定」不是瀏覽器的暫存狀態——列印要跟著它走
       （使用者指定：清單式的專案，列印就不該印甘特圖），所以以專案上存的為準。 */
    GVIEW  = (String(p.plan_view || '') === 'list') ? 'list' : 'gantt';
    /* 刻度也以專案上存的為準（使用者回報「刻度都會一直跳掉，我儲存也沒有用」——
       原本它只是一個 JS 全域變數，重新整理頁面或換一台電腦就回到「週」）。 */
    GSCALE = ($.inArray(String(p.plan_scale || ''), ['day', 'week', 'month']) >= 0) ? String(p.plan_scale) : 'week';
    var h = '<div class="pj-toolbar" style="margin-bottom:8px;">'
      + '<label>檢視</label>'
      + '<select id="gView" title="這個設定會記在專案上，列印版也會跟著換（清單式不印甘特圖）">'
      + '<option value="gantt"' + (GVIEW === 'gantt' ? ' selected' : '') + '>時間軸（甘特）</option>'
      + '<option value="list"' + (GVIEW === 'list' ? ' selected' : '') + '>清單</option></select>'
      + '<label>刻度</label><select id="gScale">'
      + '<option value="day"' + (GSCALE === 'day' ? ' selected' : '') + '>日</option>'
      + '<option value="week"' + (GSCALE === 'week' ? ' selected' : '') + '>週</option>'
      + '<option value="month"' + (GSCALE === 'month' ? ' selected' : '') + '>月</option></select>'
      + '<label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;">'
      + '<input type="checkbox" id="gHideDone" data-eg-skip="1"' + (HIDE_DONE ? ' checked' : '') + '>隱藏已完成的步驟</label>'
      + (res.can_edit ? '<button id="btnSeed" title="帶入 AS9100 標準流程（三個階段與各步驟）"><i class="fa fa-magic"></i> 帶入標準流程</button>'
                      + '<button id="btnGoalAdd"><i class="fa fa-plus"></i> 新增目標</button>'
                      + '<button class="btn-warm" id="btnPlanSave"><i class="fa fa-save"></i> 儲存規劃表</button>' : '')
      + '</div>'
      + autoEvidenceBar(res)
      + '<div id="ganttBox"></div>'
      + '<div id="planEditBox"' + (res.can_edit ? '' : ' style="display:none;"') + '></div>';
    $('#panePlan').html(h);
    drawGantt(res);
    if (res.can_edit) drawPlanEditor(res);
}

/** 執行規劃表上方的提示條：系統偵測到幾個步驟的完成日佐證。
 *  編輯檢視看不到「自動偵測」那一欄，只放在清單檢視的話使用者根本不知道有這個功能
 *  （2026-09-22 使用者問「這些功能有做嗎」就是因為看不到）。 */
function autoEvidenceBar(res) {
    var tasks = res.tasks || [];
    if (!tasks.length || !res.evidence) return '';
    var hit = 0, done = 0;
    $.each(tasks, function (i, t) {
        if (t.act_end) { done++; return; }
        if (autoHintOf(t)) hit++;
    });
    if (!hit) return '';
    return '<div class="pj-auto-bar"><i class="fa fa-magic"></i> '
      + '系統已自動偵測到 <b>' + hit + '</b> 個步驟的完成日佐證'
      + (done ? '（另有 ' + done + ' 個步驟已回報）' : '')
      + '：製令開立日、圖面發行章日期、PFMEA／SOP／SIP 表單日期、客供料回廠日、報工架機與完工日。'
      + '切到<b>「清單」檢視</b>可以看到每一步偵測到什麼，按「回報」逐筆確認後採用。'
      + '<span class="pj-op" id="btnGoList">切到清單檢視</span></div>';
}
$(document).on('click', '#btnGoList', function () { $('#gView').val('list').trigger('change'); });

/* ── 甘特時間軸 ──
   drawGantt() 只是把 ganttHtml() 的結果塞進 #ganttBox；
   清單頁的「點一下就地展開進度」用的是同一支 ganttHtml()，兩邊不會畫出兩種樣子（鐵律4）。 */
function drawGantt(res) {
    if (GVIEW === 'list') { drawGanttList(res); return; }
    $('#ganttBox').html(ganttHtml(res, { hideDone: HIDE_DONE, scale: GSCALE }));
}

/**
 * @param opt.hideDone 隱藏已完成的步驟（使用者要求的勾選項）
 * @param opt.scale    day/week/month；不給就用目前的 GSCALE
 * @param opt.compact  清單頁就地展開用的精簡版（不畫圖例）
 */
function ganttHtml(res, opt) {
    opt = opt || {};
    var GSCALE = opt.scale || 'week';            // 區域變數，刻意遮蔽全域：清單展開時不受詳情頁的刻度影響
    var goals = res.goals || [];
    var tasks = res.tasks || [];
    if (opt.hideDone) {
        tasks = $.grep(tasks, function (t) { return taskState(t, META.today) !== 'done'; });
    }
    var range = ganttRange(res.project, tasks);
    if (!range) {
        return '<div class="pj-hint" style="padding:14px;">'
             + (opt.hideDone && (res.tasks || []).length ? '目前的步驟都已完成（已勾選隱藏已完成）。'
                                                         : '還沒有任何日期，填好任務的預計起迄日後就會畫出時間軸。')
             + '</div>';
    }
    function tickLabel(d) {
        if (GSCALE === 'month') return (d.getMonth() + 1) + '月';
        return (d.getMonth() + 1) + '/' + d.getDate();
    }
    var d0 = new Date(range.start + 'T00:00:00'), d1 = new Date(range.end + 'T00:00:00');
    /* 左右各留 3 天，條子才不會貼著邊 */
    d0.setDate(d0.getDate() - 3); d1.setDate(d1.getDate() + 3);
    /* +1：軸要含「最後那一天」本身。起訖日 9/3~9/4 是**兩天**不是一天，
       不加的話整條軸短一天，每一根長條也都會短一天（使用者回報「線條長度跟我設定的不同」）。 */
    var span = Math.max(1, dayDiff(d0, d1) + 1);
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
            /* 標籤本身就是回報入口（清單檢視另有「回報」欄）；opt.compact＝清單頁就地展開，不給點。
               甘特檢視也要看得到自動偵測（使用者要求）：偵測到就在名稱後面掛一顆 🪄 小籤，
               點下去一樣開回報跳窗——不然切到清單檢視才看得到，等於逼人換檢視。 */
            var a = (opt.compact || t.act_end) ? null : autoHintOf(t);
            h += '<div class="gantt-row"><div class="gantt-lbl" style="padding-left:22px;" title="' + esc(t.task_name) + '">'
               + (opt.compact || !num(t.task_id) ? esc(t.task_name)
                    : '<span class="pj-op" data-report="' + t.task_id + '" style="padding:0;">' + esc(t.task_name) + '</span>')
               + (a ? '<span class="g-auto" data-report="' + t.task_id + '" title="' + esc(a.label)
                      + '：偵測到 ' + a.n + ' 筆，點開可逐筆確認後採用"><i class="fa fa-magic"></i>'
                      + dispDate(a.date) + (a.n > 1 ? '·' + a.n : '') + '</span>' : '')
               + '</div>'
               + '<div class="gantt-own">' + esc(t.owner_name || '－') + '</div>'
               + '<div class="gantt-track"><div class="gantt-grid">' + grid + '</div>' + todayMark
               + barsFor(t, d0, span, today) + '</div></div>';
        });
    });
    h += '</div></div>';
    if (!opt.compact) {
        h += '<div class="gantt-legend">'
          + '<span><em style="background:#F7E0BD;border:1px solid #E0C9A2;"></em> 預計</span>'
          + '<span><em style="background:#C97B2E;"></em> 實際</span>'
          + '<span><em style="background:#DD5138;"></em> 逾期未完成</span>'
          + '<span><em style="background:#8A5A2B;width:10px;height:10px;transform:rotate(45deg);border-radius:2px;"></em> 里程碑</span>'
          + '<span><em style="background:#DD5138;width:2px;height:14px;border-radius:0;"></em> 今天（' + dispDate(META.today) + '）</span>'
          + '</div>';
    }
    return h;
}

function barsFor(t, d0, span, today) {
    var out = '';
    /* 一定要夾在軸的範圍內（0~100%）。
       逾期那一條是從「預計完成日」畫到「今天」，而軸的右界只算到專案/任務的最後一個日期——
       今天遠晚於那個日期時（例：5/21 到期、今天 9/22），沒夾住的話寬度會算成好幾百 %，
       整條紅棒橫跨整張圖、把底下真正的預計長條蓋掉：使用者回報「甘特圖跟我設定的不同」就是這個。 */
    function pos(a, b) {
        var s = dayDiff(d0, a) / span * 100;
        /* 結束日**含當天**：9/3~9/4 要畫滿 9/3 與 9/4 兩格，所以右緣取 (結束日 - 起點 + 1) 天。
           原本沒有 +1，1 天的任務會被算成寬度 0（只剩 0.6% 的一條細線），
           2 天的只畫 1 天——這就是「線條長度跟我設定的不同」。 */
        var e = (dayDiff(d0, b) + 1) / span * 100;
        if (e < s) e = s;
        s = Math.max(0, Math.min(100, s));
        e = Math.max(0, Math.min(100, e));
        var w = Math.max(0.6, e - s);
        if (s + w > 100) w = Math.max(0.6, 100 - s);
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
    var tasksAll = res.tasks || [];
    if (HIDE_DONE) {
        grouped = groupTasks(res.goals || [],
            $.grep(tasksAll, function (t) { return taskState(t, META.today) !== 'done'; }));
    }
    var cnt = (CUR && CUR.attach_counts) || {};
    var h = '<div class="pj-table-wrap"><table class="pj-table"><thead><tr>'
      + '<th style="width:34px;">項次</th><th>目標／主要任務</th><th style="width:84px;">負責人</th>'
      + '<th style="width:170px;">預計</th><th style="width:170px;">實際</th>'
      + '<th style="width:96px;">進度</th><th style="width:82px;">狀態</th>'
      + '<th style="width:150px;" title="系統從製令/圖面/SOP/SIP/報工等資料自動偵測到的完成日">自動偵測</th>'
      + '<th style="width:96px;">回報</th></tr></thead><tbody>';
    if (!grouped.length) h += '<tr><td colspan="9" style="padding:14px;color:#8a6d45;">'
        + (HIDE_DONE && tasksAll.length ? '目前的步驟都已完成（已勾選隱藏已完成）。' : '尚未建立目標與任務') + '</td></tr>';
    $.each(grouped, function (gi, g) {
        h += '<tr style="background:#FBF3E6;font-weight:bold;"><td>' + (gi + 1) + '</td>'
           + '<td class="l">' + esc(g.goal_name) + '</td><td>' + esc(g.dept_name || '') + '</td>'
           + '<td colspan="6"></td></tr>';
        $.each(g.tasks, function (ti, t) {
            var stt = taskState(t, META.today);
            var nAtt = num(cnt[t.task_id]);
            h += '<tr><td>' + (gi + 1) + '.' + (ti + 1) + '</td>'
               + '<td class="l" style="padding-left:22px;">' + esc(t.task_name)
               + (num(t.is_milestone) ? ' <span style="color:#8A5A2B;">◆里程碑</span>' : '') + '</td>'
               + '<td>' + esc(t.owner_name || '－') + '</td>'
               + '<td>' + dispDate(t.plan_start) + ' ~ ' + dispDate(t.plan_end) + '</td>'
               + '<td>' + dispDate(t.act_start) + ' ~ ' + dispDate(t.act_end) + '</td>'
               + '<td>' + barHtml(num(t.progress)) + '</td>'
               + '<td>' + stateBadge(stt) + '</td>'
               + '<td>' + autoHintCell(t) + '</td>'
               + '<td><span class="pj-op" data-report="' + t.task_id + '">回報</span>'
               + (nAtt ? '<span class="pj-hint" title="佐證附件"><i class="fa fa-paperclip"></i>' + nAtt + '</span>' : '')
               + '</td></tr>';
        });
    });
    h += '</tbody></table></div>'
       + '<p class="pj-hint"><b>「自動偵測」欄就是系統自己去別的模組抓到的完成日</b>'
       + '（開立製令→製令編號回推的開立日、製作加工圖面→料號附件的發行章日期、'
       + 'PFMEA／SOP／SIP→各自模組的表單日期與版次日期、客供品點交→BOM 客供料製程的回廠日、'
       + '架機與整批加工→報工紀錄）。<b>系統刻意不自動幫你填進去</b>——同一個專案常有好幾張製令、'
       + '好幾份圖面附件，挑哪一筆是猜的；按「回報」在跳窗裡會把偵測到的每一筆都列出來，按「採用」才寫入。<br>'
       + '沒有電子化的（首件檢驗、最終檢驗）請直接填日期並<b>上傳附件佐證</b>。'
       + '<b>各步驟的負責人本人就可以回報，不必有專案登錄權限。</b></p>';
    $('#ganttBox').html(h);
}

/* ── 這個步驟屬於哪幾種自動佐證 ──
   與後端 prj_auto_kinds_of() 同一套判定，順序也要一致（畫面上顯示「建議日期」，
   真正要寫入時仍由後端 report_get 自己重算一次，前端只是為了不必逐列打 API）。 */
function autoKindsOf(t) {
    var n = String(t.task_name || ''), k = String(t.task_kind || ''), out = [];
    var has = function (s) { return n.indexOf(s) >= 0; };
    if (k === 'fai' || has('首件')) out.push('fai');
    if (has('製令')) out.push('bom_create');
    if (has('圖面')) out.push('part_drawing');
    if (has('PFMEA')) out.push('doc_pfmea');
    if (has('SOP')) out.push('doc_sop');
    if (has('SIP')) out.push('doc_sip');
    if (has('客供') || has('進料')) out.push('incoming_qc');
    if (has('架機') || has('修砂')) out.push('setup');
    if (has('整批') || has('完工')) out.push('mass_done');
    if (has('最終檢驗')) out.push('final_qc');
    return out;
}

/** 這個步驟「偵測不到、但站上有那一頁可以建立」時的入口（沒有就回 null） */
function autoCreateOf(t) {
    var ev = (CUR && CUR.evidence) || {}, hit = null;
    $.each(autoKindsOf(t), function (i, k) {
        var v = ev[k] || {};
        if (!hit && !(v.options || []).length && v.create) hit = v.create;
    });
    return hit;
}
/* ➕ 圖示：開既有的那一頁（同一個跳窗實作，不另外刻） */
$(document).on('click', '[data-mkurl]', function (e) {
    e.stopPropagation();
    openPageModal(String($(this).data('mktitle') || '建立'), String($(this).data('mkurl')));
});

/** 這個步驟系統偵測到的「建議完成日」（沒抓到回 null） */
function autoHintOf(t) {
    var ev = (CUR && CUR.evidence) || {}, labels = (CUR && CUR.auto_kinds) || {};
    var kinds = autoKindsOf(t), best = null;
    $.each(kinds, function (i, k) {
        var o = ((ev[k] || {}).options || [])[0];
        if (o && !best) best = { kind: k, label: labels[k] || k, date: o.date,
                                 n: (ev[k].options || []).length };
    });
    return best;
}

/* ══════════════════════════ 進度回報 ══════════════════════════
   使用者 2026-09-22：「建立完專案後該如何回報進度？正常來說應該是各負責人來回報各個進度」。
   跳窗做三件事：①把系統自動偵測到的佐證列出來讓人按「採用」②填實際起迄與說明
   ③上傳佐證附件（沒電子化的首件／最終檢驗就是靠這個）。
   **自動偵測只建議不代填**——同一個專案常有好幾張製令、好幾份圖面附件，挑哪一筆是猜的。 */
var RPT_TASK = null;

function openReport(taskId) {
    if (!CUR || !num(CUR.project.project_id)) return;
    $('#rptBody').html('<div class="pj-hint" style="padding:14px;">載入中…</div>');
    $('#rptFoot').html('');
    openMask('rptMask');
    api('report_get', { project_id: CUR.project.project_id, task_id: taskId }).done(function (r) {
        RPT_TASK = r;
        renderReport(r);
    });
}

function renderReport(r) {
    var t = r.task || {}, ro = r.can_report && r.act_open ? '' : ' disabled';
    $('#rptTitle').text('回報進度：' + (t.task_name || ''));

    var h = '<div class="sec"><h5>這個步驟</h5><div class="grid3">'
      + '<div><label>步驟</label><input type="text" class="ro-auto" readonly value="' + esc(t.task_name || '') + '"></div>'
      + '<div><label>負責人</label><input type="text" class="ro-auto" readonly value="' + esc(t.owner_name || '（未指派）') + '"></div>'
      + '<div><label>預計</label><input type="text" class="ro-auto" readonly value="'
      + esc(dispDate(t.plan_start) + ' ~ ' + dispDate(t.plan_end)) + '"></div>'
      + '</div>'
      + (t.reported_at ? '<div class="pj-hint">上次回報：' + esc(t.reported_by_name || '') + '　' + esc(String(t.reported_at)) + '</div>' : '')
      + (function () {
            var used = parseEvidence(t.evidence_json);
            if (!used.length) return '';
            var s = [];
            $.each(used, function (i, e) { s.push(dispDate(e.date) + '　' + (e.label || '')); });
            return '<div class="pj-hint">已採用的佐證（' + used.length + ' 筆）：<br>' + esc(s.join('\n')).replace(/\n/g, '<br>') + '</div>';
        })()
      + '</div>';

    /* ① 自動佐證：**可以多選**（使用者要求）——一個步驟常常靠好幾份文件才算完成
       （例：加工圖面有本圖與 BOSS 圖兩份），只能挑一筆就記不清楚到底憑什麼判定完成。
       已採用過的（evidence_json）重開跳窗時要自動勾回來。 */
    var picked = {};
    $.each(parseEvidence(t.evidence_json), function (i, e) { picked[e.kind + '|' + e.date + '|' + (e.ref || '')] = 1; });
    var kinds = r.kinds || {}, hasKind = false;
    var kh = '';
    $.each(kinds, function (k, v) {
        hasKind = true;
        kh += '<div style="margin-bottom:10px;"><b>' + esc(v.label) + '</b>';
        if ((v.options || []).length) {
            kh += '<table class="sub-tbl" style="margin-top:4px;"><thead><tr>'
                + '<th style="width:30px;">' + (ro ? '' : '<input type="checkbox" class="ev-all" data-eg-skip="1">') + '</th>'
                + '<th style="width:92px;">日期</th><th>佐證</th></tr></thead><tbody>';
            $.each(v.options, function (i, o) {
                var sig = k + '|' + o.date + '|' + (o.ref || '');
                kh += '<tr><td>' + (ro ? '－'
                        : '<input type="checkbox" class="ev-ck" data-eg-skip="1"'
                          + ' data-kind="' + esc(k) + '" data-date="' + esc(o.date) + '" data-ref="' + esc(o.ref || '') + '"'
                          + ' data-label="' + esc(String(o.label).replace(/<[^>]*>/g, '')) + '"'
                          + (picked[sig] ? ' checked' : '') + '></td>')
                    + '<td>' + dispDate(o.date) + '</td><td>' + o.label + '</td></tr>';
            });
            kh += '</tbody></table>';
        }
        if (v.note) kh += '<div class="pj-hint">' + esc(v.note) + '</div>';
        /* 查不到資料、但站上有那一頁可以建立的，直接給入口（使用者要求） */
        if (!(v.options || []).length && v.create) {
            kh += '<div style="margin-top:4px;"><span class="pj-op pj-mk" data-mkurl="' + esc(v.create.url)
                + '" data-mktitle="' + esc(v.create.label) + '"><i class="fa fa-plus-circle"></i> '
                + esc(v.create.label) + '</span>'
                + '<span class="pj-hint">（本系統已經有這一頁，建好之後回來重開這個跳窗就會自動偵測到）</span></div>';
        }
        kh += '</div>';
    });
    h += '<div class="sec"><h5>系統自動偵測到的佐證</h5>'
       + (hasKind ? kh : '<div class="pj-hint">這個步驟沒有可以自動偵測的來源（步驟名稱不屬於標準流程的那幾項），請直接填寫下面的日期並上傳佐證附件。</div>')
       + (hasKind && !ro
            ? '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">'
              + '<button id="btnUseEv" style="height:28px;padding:0 12px;border:1px solid #d98a33;border-radius:4px;background:#F0A24B;color:#fff;cursor:pointer;">'
              + '採用勾選的佐證</button>'
              + '<span class="pj-hint" id="evPickHint"></span></div>'
              + '<div class="pj-hint">可以<b>勾好幾筆</b>（一個步驟常常靠好幾份文件才算完成）。'
              + '按下之後<b>實際完成日會取其中最晚的那一天</b>——最後一份佐證到齊才算做完；'
              + '勾選的內容會一起存下來，之後看得出這個日期是憑什麼填的。還是要按「儲存回報」才寫入。</div>'
            : '')
       + '</div>';

    /* ② 回報內容 */
    h += '<div class="sec"><h5>回報內容</h5><div class="grid3">'
      + '<div><label>實際開始</label><input type="date" id="rAs" value="' + esc(t.act_start || '') + '"' + ro + '></div>'
      + '<div><label>實際完成</label><input type="date" id="rAe" value="' + esc(t.act_end || '') + '"' + ro + '></div>'
      + '<div><label>進度％</label><input type="number" id="rPg" min="0" max="100" value="' + num(t.progress) + '"' + ro + '></div>'
      + '</div>'
      /* 狀態：進度 100%（或填了實際完成日）一律自動判定「已完成」並鎖住，
         只有未完成時才讓人自己挑（使用者指定）。後端 report_save 同規則再擋一次（鐵律8）。
         清單裡刻意不放「已完成」——那一個是系統判的，不是人選的。 */
      + '<label>狀態 <span class="pj-hint" id="rStHint"></span></label>'
      + '<select id="rSt"' + ro + '><option value="">未開始</option>';
    $.each(META.task_status || {}, function (k, v) {
        if (k === '' || k === 'done') return;
        h += '<option value="' + esc(k) + '"' + (t.status_code === k ? ' selected' : '') + '>' + esc(v) + '</option>';
    });
    h += '</select>'
      + '<label style="margin-top:6px;">回報說明</label>'
      + '<textarea id="rNote" rows="3"' + ro + ' data-eg-hint="例：首件檢驗判定通過，附檢驗報告">' + esc(t.report_note || '') + '</textarea>'
      + '<div class="pj-hint">填了<b>實際完成日</b>就代表這一步完成了，進度會自動變成 100%、狀態自動變「已完成」。'
      + '<b>只有進度還沒到 100% 時才需要自己選狀態</b>（進行中／待檢驗／異常）。</div></div>';

    /* ③ 佐證附件 */
    h += '<div class="sec"><h5>佐證附件</h5>';
    var at = r.attaches || [];
    if (at.length) {
        h += '<table class="sub-tbl"><thead><tr><th>檔名</th><th style="width:80px;">大小</th>'
          + '<th style="width:150px;">上傳</th><th style="width:100px;"></th></tr></thead><tbody>';
        $.each(at, function (i, a) {
            h += '<tr><td>' + esc(a.orig_name) + (a.note ? '<br><span class="pj-hint">' + esc(a.note) + '</span>' : '') + '</td>'
              + '<td>' + Math.round(num(a.file_size) / 1024) + ' KB</td>'
              + '<td>' + esc(a.uploaded_by_name || '') + '<br><span class="pj-hint">' + esc(String(a.uploaded_at || '').substring(0, 16)) + '</span></td>'
              + '<td><span class="pj-op" data-dlatt="' + a.id + '">下載</span>'
              + (ro ? '' : '<span class="pj-op" data-delatt="' + a.id + '" style="color:#DD5138;">刪除</span>') + '</td></tr>';
        });
        h += '</tbody></table>';
    } else {
        h += '<div class="pj-hint">還沒有附件。</div>';
    }
    if (!ro) {
        /* 原生可見的 file input（記憶 file_upload_change_event：change 事件在這台環境會被吞掉，
           一律用可見的 input＋常駐的送出鈕，送出時直接讀 input.files） */
        h += '<div style="margin-top:8px;display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">'
          + '<div><label>選擇檔案</label><input type="file" id="rFile" data-eg-skip="1"></div>'
          + '<div style="flex:1;min-width:160px;"><label>附件說明（選填）</label><input type="text" id="rFileNote" data-eg-skip="1"></div>'
          + '<button id="btnRptUp" style="height:30px;padding:0 12px;border:1px solid #d98a33;border-radius:4px;background:#F0A24B;color:#fff;cursor:pointer;">上傳</button>'
          + '</div><div class="pj-hint">單檔 20MB 以內；可執行檔與腳本檔（.php/.exe/.bat…）不接受。</div>';
    }
    h += '</div>';

    if (!r.act_open) {
        h = '<div class="pj-warn" style="margin-bottom:8px;">專案還沒核准立案，實際日期要等立案核准後才能填。</div>' + h;
    } else if (!r.can_report) {
        h = '<div class="pj-warn" style="margin-bottom:8px;">你不是這個步驟的負責人，也沒有專案登錄權限，只能檢視。</div>' + h;
    }
    $('#rptBody').html(h);
    evPickHint();                      // 重開跳窗時把「已勾選 N 筆」帶回來
    rptStatusSync();                   // 狀態欄要不要鎖住，依目前的進度／完成日決定
    $('#rptFoot').html('<button onclick="closeMask(\'rptMask\')">關閉</button>'
        + (ro ? '' : '<button class="b-ok" id="btnRptSave"><i class="fa fa-save"></i> 儲存回報</button>'));
}

/** 狀態欄與進度連動：100%（或有實際完成日）＝自動判定「已完成」並鎖住，其餘才給人選。
 *  使用者指定「狀態應該要自動依照進度判定是否結束，只有進度非 100% 才可手動選擇狀態」。 */
function rptStatusSync() {
    var $s = $('#rSt');
    if (!$s.length) return;
    var done = num($('#rPg').val()) >= 100 || !!$.trim($('#rAe').val() || '');
    if (done) {
        $s.prop('disabled', true).addClass('ro-auto');
        $('#rStHint').text('（進度 100%，系統自動判定為「已完成」）');
    } else {
        /* 唯讀檢視時本來就該維持停用，不可以被這裡打開 */
        if (!(RPT_TASK && RPT_TASK.can_report && RPT_TASK.act_open)) return;
        $s.prop('disabled', false).removeClass('ro-auto');
        $('#rStHint').text('（進度未滿 100%，可自行選擇）');
    }
}
$(document).on('change input', '#rPg, #rAe', rptStatusSync);

/** 已採用的佐證（存在 project_task.evidence_json） */
function parseEvidence(raw) {
    if (!raw) return [];
    try { var a = JSON.parse(raw); return $.isArray(a) ? a : []; } catch (e) { return []; }
}
/** 目前勾選的佐證 */
function pickedEvidence() {
    return $('#rptBody .ev-ck:checked').map(function () {
        return { kind: String($(this).data('kind')), date: String($(this).data('date')),
                 ref: String($(this).data('ref') || ''), label: String($(this).data('label') || '') };
    }).get();
}
function evPickHint() {
    var n = pickedEvidence().length;
    $('#evPickHint').text(n ? '已勾選 ' + n + ' 筆' : '尚未勾選');
}
$(document).on('change', '#rptBody .ev-ck', evPickHint);
$(document).on('change', '#rptBody .ev-all', function () {
    $(this).closest('table').find('.ev-ck').prop('checked', $(this).is(':checked'));
    evPickHint();
});
$(document).on('click', '#btnUseEv', function () {
    var p = pickedEvidence();
    if (!p.length) { alert('請先勾選要採用的佐證'); return; }
    /* 多筆時取**最晚**那一天：最後一份佐證到齊才算做完 */
    var last = p[0].date;
    $.each(p, function (i, x) { if (x.date > last) last = x.date; });
    $('#rAe').val(last);
    if (!$('#rAs').val()) $('#rAs').val(last);
    $('#rPg').val(100);
    rptStatusSync();                   // 帶入完成日之後狀態要跟著鎖成「已完成」
    pjMsgLite('已採用 ' + p.length + ' 筆佐證，實際完成日帶入 ' + dispDate(last) + '（記得按「儲存回報」）');
});
/* 跳窗內的小提示：用原生 alert 會卡住流程，改成就地顯示一行 */
function pjMsgLite(msg) {
    var $b = $('#evPickHint');
    if (!$b.length) return;
    $b.html('<b style="color:#8A5A2B;">' + esc(msg) + '</b>');
}
$(document).on('click', '#btnRptSave', function () {
    var d = { project_id: CUR.project.project_id, task_id: RPT_TASK.task.task_id,
              act_start: $('#rAs').val(), act_end: $('#rAe').val(),
              progress: $('#rPg').val(), status_code: $('#rSt').val(), report_note: $('#rNote').val(),
              evidence: JSON.stringify(pickedEvidence()) };
    if (d.act_start && d.act_end && d.act_end < d.act_start) { alert('實際完成日不可早於實際開始日'); return; }
    api('report_save', d, 'POST').done(function () {
        closeMask('rptMask');
        openProject(num(CUR.project.project_id));   /* 會自動回到原本看的分頁 */
    });
});
$(document).on('click', '#btnRptUp', function () {
    var el = document.getElementById('rFile');
    if (!el || !el.files || !el.files.length) { alert('請先選擇檔案'); return; }
    var fd = new FormData();
    fd.append('action', 'report_upload');
    fd.append('project_id', CUR.project.project_id);
    fd.append('task_id', RPT_TASK.task.task_id);
    fd.append('note', $('#rFileNote').val() || '');
    fd.append('file', el.files[0]);   /* action 走 FormData：後端 $_GET['action'] ?? $_POST['action'] 兩種都收 */
    $(this).prop('disabled', true).text('上傳中…');
    $.ajax({ url: API, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
        .done(function (r) {
            if (!r || !r.ok) { alert((r && r.error) || '上傳失敗'); }
            else { RPT_TASK.attaches = r.attaches || []; renderReport(RPT_TASK); }
        })
        .fail(function (x) { alert((x.responseJSON && x.responseJSON.error) || '上傳失敗'); })
        .always(function () { $('#btnRptUp').prop('disabled', false).text('上傳'); });
});
$(document).on('click', '[data-dlatt]', function () {
    window.open(API + '?action=report_attach_dl&project_id=' + num(CUR.project.project_id)
        + '&attach_id=' + num($(this).data('dlatt')), '_blank');
});
$(document).on('click', '[data-delatt]', function () {
    if (!confirm('刪除這個佐證附件？')) return;
    api('report_attach_del', { project_id: CUR.project.project_id, attach_id: num($(this).data('delatt')) }, 'POST')
        .done(function (r) { RPT_TASK.attaches = r.attaches || []; renderReport(RPT_TASK); });
});
$(document).on('click', '[data-report]', function () { openReport(num($(this).data('report'))); });

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
    var min = p.start_date || '', max = p.end_date || '', overdue = false;
    var today = META.today || '';
    $.each(tasks, function (i, t) {
        $.each(['plan_start', 'plan_end', 'act_start', 'act_end'], function (j, k) {
            var v = t[k] || '';
            if (!v || v === '0000-00-00') return;
            if (!min || v < min) min = v;
            if (!max || v > max) max = v;
        });
        if (t.plan_end && !t.act_end && today && t.plan_end < today) overdue = true;
    });
    /* 有逾期未完成的任務時把軸拉到今天為止——不然「逾期到今天」那一段畫不進來，
       使用者只會看到一條被夾在右邊界的紅棒，看不出到底逾期多久。 */
    if (overdue && today && max && today > max) max = today;
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

    /* 首件前應備文件的檢查：缺就在這裡直接講，不用等使用者自己去翻文件檢核。
       一定要逐料號印出「實際缺的是哪幾份」——原本固定寫死「沒有 PFMEA／SOP／SIP」，
       實際只缺 SOP／SIP 時會被讀成連 PFMEA 都沒建立（使用者 2026-09-21 回報）。 */
    var lackBefore = 0, lackParts = [],
        cdefs = META.doc_checks || {}, cphase = (CUR && CUR.doc_phase) || META.doc_phase || {};
    $.each(res.doc_check || [], function (i, r) {
        if (!num(r.missing_before)) return;
        var names = [];
        $.each(cdefs, function (k, d) {
            if ((cphase[k] || 'any') === 'before' && !num(r[k])) names.push(d[0]);
        });
        lackBefore += num(r.missing_before);
        lackParts.push('<b>' + esc(r.part_no) + '</b>'
                     + (names.length ? '（缺 ' + esc(names.join('、')) + '）' : ''));
    });
    if (lackBefore) {
        h += '<div style="border:2px solid #DD5138;background:#FCE4E4;color:#A32E1A;border-radius:6px;'
          + 'padding:8px 12px;margin-bottom:10px;font-size:13px;">'
          + '<b>送首件之前，這些料號還缺 ' + lackBefore + ' 份應備文件：</b>' + lackParts.join('　')
          + '<br><span style="font-weight:normal;">首件檢驗驗證的就是「這套製程＋這份文件」，'
          + '缺了上面這幾份就沒有判定依據（AS9102／AS9145）。請到「文件檢核」分頁補齊。</span></div>';
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
var PLAN_ST_OPEN  = false;   // 任務狀態現在可不可以改（＝專案是否已送簽；送簽前一律「未開始」）
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
    PLAN_ST_OPEN  = !!res.status_open;
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
      + '<b>上一列的預計完成日會自動變成下一列的預計開始日</b>——你自己改過的開始日不會被蓋掉。<br>'
      /* 使用者 2026-09-22 直接問「進度% 跟里程碑的勾選是甚麼？」——原本只寫在 th 的 title 裡，
         滑鼠移過去才看得到，等於沒寫。 */
      + '<b>進度%</b>＝這一步完成到幾成，底下的「<b>自動</b>」勾起來時<b>不用自己填</b>：'
      + '填了實際完成日就是 100%、還沒完成就是 0%（你手動改過數字，「自動」就會自己取消勾選，之後都以你填的為準）。'
      + '專案整體進度＝所有步驟進度的平均。<br>'
      + '<b>里程碑</b>＝這一步是專案的關鍵查核點（例如首件檢驗通過）。勾起來後：'
      + '時間軸上不畫長條、改畫一個<b>◆</b>菱形；列印的執行規劃表也會在該列標 ◆。'
      + '<b>純粹是標記，不影響進度計算與任何判定</b>，只是讓人一眼看出哪幾步是關鍵。</p>'
      + (PLAN_ACT_OPEN ? ''
          : '<p class="pj-hint" style="color:#C4442D;">「實際開始／實際完成」兩欄要等<b>立案核准</b>之後才會出現'
            + '（目前狀態：' + esc(STATUS_LABEL[res.project.status] || res.project.status) + '），此階段只排預計日程。'
            + (PLAN_ST_OPEN ? '' : '任務「狀態」在<b>送簽</b>之前一律是「未開始」，所以也先不顯示。') + '</p>');
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
          + (PLAN_ST_OPEN ? '<th style="width:96px;" title="1~2 天完工的製程用狀態管理，不記時分秒">狀態</th>' : '')
          + '<th style="width:180px;">負責人（先選部門）</th>'
          + '<th style="width:76px;" title="勾「自動」時：填了實際完成日就是 100%，否則 0%；自己改過就不再自動">進度%</th>'
          + '<th style="width:44px;" title="關鍵查核點：時間軸上畫成 ◆ 菱形、列印也標 ◆；純標記，不影響進度計算">里程碑</th>'
          + '<th style="width:30px;"></th></tr></thead>'
          + '<tbody class="t-body" data-eg-row-add="planRowAdd" data-eg-row-del="planRowDel">';
        var list = g.tasks.length ? g.tasks : [{}];
        $.each(list, function (ti, t) { h += planRowHtml(t, ti); });
        h += '</tbody></table></div>';
    });
    h += '</div>';
    $('#planEditBox').html(h);
    $('#planEditBox .t-body tr').each(function () { planRowCheck($(this)); planRowProgress($(this)); });
    planSeedFirstStart();
    PLAN_DIRTY = false;
    /* 目標的主辦單位下拉用 .val() 設，避免字串比對出錯（負責人兩個下拉已在建 option 時標好 selected） */
    $('#planEditBox .sec[data-goal]').each(function (gi) {
        var g = grouped[gi];
        if (!g) return;
        var $s = $(this).find('.g-dept');
        $s.find('option').each(function () { if ($(this).text() === g.dept_name) $s.val($(this).val()); });
    });
}

/**
 * 第一個目標的第一列「預計開始」沒填時，自動帶入專案開始日（使用者要求 2026-08-27）。
 * 只在空白時帶，不覆蓋已經排好的日期；後面的列本來就會由
 * 「上一列預計完成 → 下一列預計開始」自動串下去，所以整條日程會一起長出來。
 */
function planSeedFirstStart() {
    var $tr = $('#planEditBox .sec[data-goal]').first().find('.t-body tr').first();
    if (!$tr.length || $.trim($tr.find('.t-ps').val())) return;
    var sd = $.trim((CUR && CUR.project ? CUR.project.start_date : '') || '');
    if (!sd) return;
    $tr.find('.t-ps').val(sd);
    planRowRecalc($tr, 'ps');
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
      + (PLAN_ST_OPEN ? '' : '<input type="hidden" class="t-status" value="">')
      + '</td>'
      + '<td><input type="date" class="t-ps"' + planMinAttr() + ' value="' + esc(t.plan_start || '') + '"></td>'
      + '<td><input type="number" class="t-days" min="1" max="999" value="' + (days > 0 ? days : '') + '"></td>'
      + '<td><input type="date" class="t-pe" value="' + esc(t.plan_end || '') + '"></td>'
      + (PLAN_ACT_OPEN
            ? '<td><input type="date" class="t-as" value="' + esc(t.act_start || '') + '"></td>'
              + '<td><input type="date" class="t-ae" value="' + esc(t.act_end || '') + '"></td>' : '')
      + (PLAN_ST_OPEN ? '<td>' + taskStatusSelect(t) + '</td>' : '')
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
$(document).on('change', '#gView', function () {
    GVIEW = $(this).val();
    drawGantt(CUR);
    /* 記在專案上（列印版要跟著走）。只是個顯示偏好，存不進去也不擋畫面。 */
    if (CUR && num(CUR.project.project_id) && CUR.can_edit) {
        CUR.project.plan_view = GVIEW;
        api('plan_view_save', { project_id: CUR.project.project_id, plan_view: GVIEW }, 'POST');
        $.each(LIST, function (i, r) { if (num(r.project_id) === num(CUR.project.project_id)) r.plan_view = GVIEW; });
    }
});
$(document).on('change', '#gScale', function () {
    GSCALE = $(this).val();
    drawGantt(CUR);
    /* 記在專案上，不然重新整理就跳回「週」。只是顯示偏好，存不進去也不擋畫面。 */
    if (CUR && num(CUR.project.project_id) && CUR.can_edit) {
        CUR.project.plan_scale = GSCALE;
        api('plan_view_save', { project_id: CUR.project.project_id, plan_scale: GSCALE }, 'POST');
    }
});
/* 「隱藏已完成的步驟」詳情頁與清單就地展開共用同一個開關，勾一次兩邊一起變 */
$(document).on('change', '#gHideDone, #listHideDone', function () {
    HIDE_DONE = $(this).is(':checked');
    $('#gHideDone, #listHideDone').prop('checked', HIDE_DONE);
    if (CUR && $('#ganttBox').length) drawGantt(CUR);
    $('.pj-inline-gantt').each(function () {
        var pid = num($(this).data('pid'));
        if (PLAN_CACHE[pid]) $(this).html(ganttHtml(PLAN_CACHE[pid], { hideDone: HIDE_DONE, scale: 'week', compact: true }));
    });
});
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

/* ── 管理卡表身：階段（可展開收合）＋ 底下的核心作業項目 ──
   欄位依使用者指定：項次／專案階段與核心作業項目／主辦·承辦人／預計完成日／實際完成日／
   交付成果·單號／狀態·簽核。
   **已完成的階段預設展開、未完成的預設收合**（使用者指定）——檢討會要看的是已經做完的那幾段。
   交付成果取回報時採用的佐證（evidence_json），那本來就是「這一步憑什麼算完成」的單號。 */
function cardItemsHtml(res, ro) {
    var tasks = res.tasks || [], items = (res.card || {}).items || [];
    var stMap = res.task_status || META.task_status || {};
    var h = '<div style="overflow-x:auto;"><table class="sub-tbl" id="cardItems"><thead><tr>'
      + '<th style="width:46px;">項次</th><th>專案階段與核心作業項目</th>'
      + '<th style="width:130px;">主辦／承辦人</th><th style="width:96px;">預計完成日</th>'
      + '<th style="width:96px;">實際完成日</th><th style="width:210px;">交付成果／單號</th>'
      + '<th style="width:110px;">狀態／簽核</th></tr></thead><tbody>';
    if (!items.length) h += '<tr><td colspan="7" style="padding:12px;color:#8a6d45;">（這張管理卡沒有項次）</td></tr>';

    $.each(items, function (i, it) {
        var gid = num(it.goal_id);
        var gt = $.grep(tasks, function (t) { return num(t.goal_id) === gid; });
        var done = gt.length ? $.grep(gt, function (t) { return taskState(t, META.today) === 'done'; }).length : 0;
        var allDone = gt.length > 0 && done >= gt.length;
        var open = allDone;                       // 已完成的展開、未完成的收合
        var pct = gt.length ? Math.round(done * 100 / gt.length) : 0;

        h += '<tr class="cd-goal" data-cg="' + gid + '"><td class="c">' + (i + 1) + '</td>'
          + '<td class="l"><span class="cd-tog" data-cgtog="' + gid + '">'
          + '<i class="fa fa-caret-' + (open ? 'down' : 'right') + '"></i></span> <b>' + esc(it.goal_name || '') + '</b>'
          + '<span class="pj-hint">　' + done + '/' + gt.length + ' 項完成（' + pct + '%）</span></td>'
          + '<td>' + esc(it.dept_name || '') + (it.owner_name ? '<br><span class="pj-hint">' + esc(it.owner_name) + '</span>' : '') + '</td>'
          + '<td class="c">' + dispDate(cardGoalDate(gt, 'plan_end', true)) + '</td>'
          + '<td class="c">' + (allDone ? dispDate(cardGoalDate(gt, 'act_end', true)) : '－') + '</td>'
          + '<td class="l"><span class="pj-hint">' + (allDone ? '本階段已完成' : '尚在進行') + '</span></td>'
          + '<td class="c">' + (allDone ? '<span class="st st-approved">已完成</span>'
                                        : '<span class="st st-submitted">進行中</span>') + '</td></tr>';

        /* 階段底下的作業項目 */
        $.each(gt, function (ti, t) {
            var ev = parseEvidence(t.evidence_json);
            var deliver = $.map(ev, function (e) { return String(e.label || '').replace(/<[^>]*>/g, ''); });
            var stt = taskState(t, META.today);
            h += '<tr class="cd-task cd-of-' + gid + '"' + (open ? '' : ' style="display:none;"') + '>'
              + '<td class="c">' + (i + 1) + '.' + (ti + 1) + '</td>'
              + '<td class="l" style="padding-left:22px;">' + esc(t.task_name)
              + (num(t.is_milestone) ? ' <span style="color:#8A5A2B;">◆</span>' : '') + '</td>'
              + '<td>' + esc(t.owner_dept_name || '') + '<br><span class="pj-hint">'
              + esc(t.owner_name || '排班人員') + '</span></td>'
              + '<td class="c">' + dispDate(t.plan_end) + '</td>'
              + '<td class="c">' + dispDate(t.act_end) + '</td>'
              + '<td class="l">' + (deliver.length
                    ? esc(deliver.join('；'))
                    : '<span class="pj-hint">' + (t.act_end ? '（未登錄佐證）' : '－') + '</span>') + '</td>'
              + '<td class="c">' + stateBadge(stt)
              + (t.reported_by_name ? '<br><span class="pj-hint">' + esc(t.reported_by_name) + '</span>' : '')
              + '</td></tr>';
        });

        /* 這個階段的管理卡填寫欄（問題／後續辦法／備註）跟著階段一起收合 */
        h += '<tr class="cd-edit cd-of-' + gid + '"' + (open ? '' : ' style="display:none;"')
          + ' data-item="' + it.item_id + '"><td></td><td colspan="6">'
          + '<div class="grid3">'
          + '<div><label>目前應達成基準</label><textarea class="i-base" rows="2"' + ro + '>' + esc(it.baseline || '') + '</textarea>'
          + '<label style="font-size:11px;display:block;margin-top:2px;">'
          + '<input type="checkbox" class="i-auto" data-eg-skip="1"' + (num(it.baseline_auto) ? ' checked' : '')
          + (res.can_edit ? '' : ' disabled') + '> 跟著日程自動更新</label></div>'
          + '<div><label>現階段問題</label><textarea class="i-issue" rows="2"' + ro + '>' + esc(it.issue_text || '') + '</textarea></div>'
          + '<div><label>後續辦理方法</label><textarea class="i-follow" rows="2"' + ro + '>' + esc(it.follow_text || '') + '</textarea></div>'
          + '</div>'
          + '<div style="display:flex;gap:10px;align-items:flex-end;margin-top:4px;">'
          + '<div style="flex:1;"><label>備註</label><input type="text" class="i-note" value="' + esc(it.note || '') + '"' + ro + '></div>'
          + '<label style="white-space:nowrap;"><input type="checkbox" class="i-ontrack" data-eg-skip="1"'
          + (num(it.on_track) ? ' checked' : '') + (res.can_edit ? '' : ' disabled') + '> 依計畫進行</label>'
          /* 存檔仍以這幾欄為準，目標名稱／單位／承辦人維持可改（紙本上是手寫欄） */
          + '</div>'
          + '<input type="hidden" class="i-goal" value="' + esc(it.goal_name || '') + '">'
          + '<input type="hidden" class="i-dept" value="' + esc(it.dept_name || '') + '">'
          + '<input type="hidden" class="i-owner" value="' + esc(it.owner_name || '') + '">'
          + '</td></tr>';
    });
    return h + '</tbody></table></div>';
}
/** 一個階段的預計／實際完成日＝底下任務的最晚那一天（全部做完才算這個階段完成） */
function cardGoalDate(tasks, field, last) {
    var v = '';
    $.each(tasks, function (i, t) {
        var d = t[field] || '';
        if (!d) return;
        if (!v || (last ? d > v : d < v)) v = d;
    });
    return v;
}
$(document).on('click', '[data-cgtog]', function () {
    var gid = num($(this).data('cgtog'));
    var $rows = $('.cd-of-' + gid);
    var show = !$rows.first().is(':visible');
    $rows.toggle(show);
    $(this).find('i').attr('class', 'fa fa-caret-' + (show ? 'down' : 'right'));
});

function openCard(cardId) {
    api('card_get', { card_id: cardId }).done(function (res) {
        var c = res.card, ro = res.can_edit ? '' : ' readonly';
        /* 表頭寫客戶與料號，**不寫專案名稱**（使用者指定）——管理卡是對這個件的檢討，
           看的人要知道是哪一家的哪一個料號，專案名稱在管理卡編號裡已經隱含了。 */
        var pj = res.project || {};
        var pns = $.map(res.parts || [], function (x) { return x.part_no || ''; }).join('、');
        var h = '<div class="sec" data-card="' + c.card_id + '"><h5>管理卡 ' + esc(c.card_no || '') + '</h5>'
          + '<div class="grid3" style="margin-bottom:8px;">'
          + '<div><label>客戶</label><input type="text" class="ro-auto" readonly value="' + esc(pj.customer_name || '－') + '"></div>'
          + '<div><label>料號</label><input type="text" class="ro-auto" readonly value="' + esc(pns || '－') + '"></div>'
          + '<div><label>檢討日期</label><input type="date" id="ciDate" value="' + esc(c.review_date) + '"'
          + (res.can_edit ? '' : ' disabled') + '></div>'
          + '<div><label>狀態</label><input type="text" class="ro-auto" readonly value="'
          + esc(STATUS_LABEL[c.status] || c.status) + '"></div>'
          + '<div><label>製表（專案負責人）</label><input type="text" class="ro-auto" readonly value="' + esc(c.created_by_name || '') + '"></div>'
          + '</div>'
          + cardItemsHtml(res, ro);
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
    /* 只收「管理卡填寫列」（.cd-edit）——表身現在還有階段列與作業項目列，
       全部掃進來會送出一堆沒有 item_id 的空白列，把原本填好的內容洗掉。 */
    $('#cardItems tbody tr.cd-edit').each(function () {
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
            /* 訂單編號可點：開訂單追蹤並自動篩出這個單號＋這個料號（使用者要求） */
            h += '<tr><td><span class="pj-op" data-vieworder="' + esc(o.Order_oo)
              + '" data-partno="' + esc(o.master_part_no || o.part_no || '') + '" title="開啟訂單追蹤">'
              + esc(o.Order_oo) + '</span></td><td>' + esc(o.C_order || '') + '</td>'
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
        var lastBom = '', outCnt = 0;
        $.each(res.processes, function (i, x) {
            var show = (x.bom !== lastBom); lastBom = x.bom;
            var out = !num(x.in_scope);            // 專案有綁定製程、而這一道不在範圍內
            if (out) outCnt++;
            h += '<tr data-proc="' + x.id + '"' + (out ? ' style="opacity:.55;"' : '') + '>'
              /* 製令單號可點：開 BOM 總表（使用者要求） */
              + '<td>' + (show ? '<span class="pj-op" data-viewbom="' + esc(x.bom) + '" title="開啟 BOM 總表"><b>'
                    + esc(x.bom) + '</b></span>' : '') + '</td>'
              + '<td>' + esc(x.part_no || '') + '</td><td>' + num(x.bom_sn) + '</td>'
              + '<td>' + esc(x.process_name || ('製程' + num(x.process_no)))
              + (out ? ' <span class="pj-hint">（不在本專案範圍）</span>' : '') + '</td>'
              + '<td>' + esc(x.maker_name || '－') + '</td><td>' + num(x.sqty) + '</td>'
              + '<td>' + dispDate(x.outsource_date) + '</td><td>' + dispDate(x.return_date) + '</td>'
              + '<td>' + qcLabel(x.qc_check) + '</td>'
              + '<td><input type="checkbox" class="p-ms" data-eg-skip="1"' + (num(x.is_milestone) ? ' checked' : '')
              + (res.can_edit ? '' : ' disabled') + '></td>'
              + '<td><input type="text" class="p-note" value="' + esc(x.note || '') + '"' + (res.can_edit ? '' : ' readonly') + '></td>'
              + '</tr>';
        });
        h += '</tbody></table></div>'
          + '<p class="pj-hint">本頁只讀 BOM，不會改動 BOM 任何資料；你在這裡加的註記與里程碑同步時不會被覆蓋。'
          + (outCnt ? '<br><b>本專案已綁定特定製程</b>，上面有 ' + outCnt + ' 道不在範圍內（淡字那幾列）：'
                    + '整張 BOM 的製程鏈仍然看得到，但進度與統計只認範圍內的。範圍在「專案基本資料 → 專案涵蓋的製程」設定。' : '')
          + '</p>';
    }
    h += '</div>';

    /* 出貨紀錄（使用者要求）：此料號在「本專案該料號最早接單日」之後的所有出貨。
       預設只顯示 5 筆、超過要點開；綁定只是為了方便確認資料，不動 is_list 任何欄位。 */
    h += renderShipSec(res);

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
              /* 製令單號可點：開報工紀錄查詢（使用者要求） */
              + '<td>' + (r.bom ? '<span class="pj-op" data-viewwork="' + esc(r.bom) + '" title="開啟報工紀錄查詢">' + esc(r.bom) + '</span>' : '')
              + '</td><td>' + num(r.bom_sn) + '</td>'
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

/* ── 出貨紀錄 ──
   SHIP_ALL：是不是已經按過「顯示全部」。預設 false＝只顯示前 5 筆（使用者指定）。
   截斷一律放在顯示層，後端回的是完整清單，否則「共 N 筆」會跟著被截掉而失真。 */
var SHIP_ALL = false, SHIP_LIMIT = 5;

function renderShipSec(res) {
    var rows = res.shipments || [], canEdit = !!res.can_edit;
    var h = '<div class="sec"><h5>出貨紀錄（本專案料號，訂單接單日之後）'
      + (canEdit ? ' <button id="btnShipFind" style="float:right;height:26px;padding:0 10px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;font-size:12px;">綁定其他出貨單</button>' : '')
      + '</h5>';
    if (!rows.length) {
        h += '<div class="pj-hint">這些料號在本專案訂單接單日之後還沒有出貨紀錄。'
           + '（歸戶用的是<b>料號主檔 id</b>不是料號文字——同一個料號文字常分屬好幾家客戶，比文字會把別家的出貨也算進來。）</div>';
        return h + (canEdit ? '<div id="shipFound"></div>' : '') + '</div>';
    }
    var show = SHIP_ALL ? rows : rows.slice(0, SHIP_LIMIT);
    var sumQty = 0, sumAmt = 0;
    $.each(rows, function (i, r) { sumQty += num(r.Qty); sumAmt += (parseFloat(r.amount) || 0); });

    h += '<div style="overflow-x:auto;"><table class="sub-tbl"><thead><tr>'
      + '<th style="width:88px;">出貨日</th><th style="width:120px;">出貨單號</th><th>料號</th>'
      + '<th style="width:100px;">客戶</th><th style="width:60px;">數量</th><th style="width:78px;">單價</th>'
      + '<th style="width:88px;">金額</th><th style="width:110px;">對應訂單</th>'
      + (canEdit ? '<th style="width:56px;">綁定</th>' : '') + '</tr></thead><tbody>';
    $.each(show, function (i, r) {
        h += '<tr><td>' + dispDate(r.ship_date) + '</td>'
          /* 出貨單號可點：開出貨紀錄分析並篩出這張單（使用者要求） */
          + '<td><span class="pj-op" data-viewship="' + esc(r.IS_number || '') + '" data-partno="' + esc(r.part_no || '') + '" title="開啟出貨紀錄">' + esc(r.IS_number || '') + '</span></td>'
          + '<td>' + esc(r.part_no || '') + '</td>'
          + '<td>' + esc(r.Client_name || '') + '</td>'
          + '<td>' + num(r.Qty) + '</td>'
          + '<td>' + (r.Unit_price === null ? '－' : esc(String(r.Unit_price))) + '</td>'
          + '<td>' + (r.amount ? esc(String(r.amount)) : '－') + '</td>'
          + '<td>' + (r.Order_oo
                ? esc(r.Order_oo) + (num(r.in_project) ? ' <span class="st st-approved">本專案</span>' : '')
                : '<span class="pj-hint">未綁訂單</span>') + '</td>'
          + (canEdit ? '<td>' + (num(r.is_bound)
                ? '<span class="pj-op" data-shipunbind="' + r.IS_id + '" style="color:#DD5138;">解除</span>'
                : '<span class="pj-op" data-shipbind="' + r.IS_id + '">綁定</span>') + '</td>' : '')
          + '</tr>';
    });
    h += '</tbody></table></div>';
    if (rows.length > SHIP_LIMIT) {
        h += '<div style="margin-top:6px;"><button id="btnShipMore" style="height:26px;padding:0 12px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;font-size:12px;">'
          + (SHIP_ALL ? '只顯示前 ' + SHIP_LIMIT + ' 筆' : '顯示全部 ' + rows.length + ' 筆') + '</button></div>';
    }
    h += '<p class="pj-hint">共 ' + rows.length + ' 筆、合計 ' + sumQty + ' 件'
       + (sumAmt ? '／金額 ' + Math.round(sumAmt * 100) / 100 : '')
       + '。起算日＝本專案訂單中<b>該料號</b>最早的接單日（每個料號各自起算）。'
       + '綁定只是把這張出貨單標記成本專案的資料，<b>不會改動出貨單本身</b>，也不影響對帳與毛利。</p>';
    h += (canEdit ? '<div id="shipFound"></div>' : '');
    return h + '</div>';
}

$(document).on('click', '#btnShipMore', function () { SHIP_ALL = !SHIP_ALL; renderRel(CUR); });

$(document).on('click', '[data-shipbind]', function () {
    api('ship_bind', { project_id: CUR.project.project_id, is_id: num($(this).data('shipbind')) }, 'POST')
        .done(function () { openProject(num(CUR.project.project_id)); });   /* 會自動回到原本看的分頁 */
});
$(document).on('click', '[data-shipunbind]', function () {
    api('ship_unbind', { project_id: CUR.project.project_id, is_id: num($(this).data('shipunbind')) }, 'POST')
        .done(function () { openProject(num(CUR.project.project_id)); });
});

/* 綁定其他出貨單：自動清單只涵蓋「本專案料號＋接單日之後」，
   舊資料常常沒帶料號主檔 id（is_list.d_setting_id 是空的），那種就得用單號找出來綁。 */
$(document).on('click', '#btnShipFind', function () {
    var h = '<div style="margin-top:8px;padding:8px;border:1px dashed #D8BE93;border-radius:6px;">'
      + '<label>輸入出貨單號或料號後按 Enter</label>'
      + '<input type="text" id="shipKw" data-eg-hint="例：IS1150609018" style="max-width:320px;">'
      + '<div id="shipHits" style="margin-top:6px;"></div></div>';
    $('#shipFound').html(h);
    $('#shipKw').focus();
});
$(document).on('keydown', '#shipKw', function (e) {
    if (e.which !== 13) return;
    e.preventDefault();
    var kw = $.trim($(this).val());
    if (!kw) return;
    $('#shipHits').html('<span class="pj-hint">搜尋中…</span>');
    api('ship_search', { project_id: CUR.project.project_id, kw: kw }).done(function (r) {
        var rows = r.rows || [];
        if (!rows.length) { $('#shipHits').html('<span class="pj-hint">找不到符合的出貨明細。</span>'); return; }
        var h = '<table class="sub-tbl"><thead><tr><th style="width:88px;">出貨日</th><th style="width:120px;">出貨單號</th>'
          + '<th>料號</th><th style="width:100px;">客戶</th><th style="width:60px;">數量</th><th style="width:56px;"></th></tr></thead><tbody>';
        $.each(rows, function (i, x) {
            h += '<tr><td>' + dispDate(x.ship_date) + '</td><td>' + esc(x.IS_number || '') + '</td>'
              + '<td>' + esc(x.part_no || '') + '</td><td>' + esc(x.Client_name || '') + '</td>'
              + '<td>' + num(x.Qty) + '</td>'
              + '<td>' + (num(x.is_bound) ? '<span class="pj-hint">已綁</span>'
                    : '<span class="pj-op" data-shipbind="' + x.IS_id + '">綁定</span>') + '</td></tr>';
        });
        $('#shipHits').html(h + '</tbody></table>');
    });
});

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
          + '<b>✗ 可以直接點下去開啟對應頁面、自動開建立跳窗並帶入已有資料；✓ 點下去是開啟該文件所在的頁面</b>。<br>'
          + 'SOP／SIP 看的是<b>作業標準書 SOP／標準檢驗指導書 SIP</b> 那一頁（點下去會自動開在對應的分頁上）：'
          + 'SIP 是<b>綁到這個料號</b>的文件；SOP 因為製造製程說明書本來就跟著製程走、跨料號共用，'
          + '所以「綁這個料號」或「這個料號用到的製程都有製程 SOP」都算；只涵蓋一部分時會標出 n/m 與缺哪幾個製程。'
          + '舊資料若是掃描檔掛在料號附件（標籤勾了 SOP／SIP）一樣算數。<br>'
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
                /* 已建立的也可以點——點下去開對應頁面並帶料號過去查（SOP／SIP 會自動開在該分頁上） */
                h += '<td><span class="chk-y chk-go" data-go="' + esc(d[1]) + '" data-kw="' + esc(r.part_no) + '"'
                  + ' title="開啟' + esc(d[0]) + '">✓ 已建立</span>'
                  + (rev ? '<br><span class="pj-hint">' + esc(rev) + '</span>' : '') + '</td>';
            } else if (ph === 'after' && !passed) {
                h += '<td><span class="pj-hint" title="首件通過後才需要建立">－ 首件通過後</span></td>';
            } else {
                h += '<td><span class="chk-n" data-go="' + esc(d[1]) + '" data-kw="' + esc(r.part_no) + '"'
                  + ' data-doc="' + esc(k) + '" data-ds="' + num(r.ds_pk) + '">✗ 未建立</span>'
                  /* 缺件也要講得出差在哪裡：製程 SOP 只涵蓋一部分時後端會把 n/m 與缺的製程帶回來 */
                  + (rev ? '<br><span class="pj-hint">' + esc(rev) + '</span>' : '')
                  + (ph === 'before' ? '<br><span class="chk-before">送首件前應備</span>' : '') + '</td>';
            }
        });
        h += '<td>' + (num(r.missing) ? '<span class="pj-miss-badge">' + num(r.missing) + '</span>' : '<span class="pj-ok-badge">齊全</span>') + '</td></tr>';
    });
    h += '</tbody></table></div>';
    $('#paneChk').html(h);
}
/** 目標頁的路徑本身可能已經帶參數（SOP／SIP 是 sop_sip.php?tab=sop），接參數前要先看有沒有 ? */
function chkUrl(go, qs) {
    go = String(go || '');
    return go + (go.indexOf('?') >= 0 ? '&' : '?') + qs;
}
/** 已建立：只帶料號過去查，不開建立跳窗 */
$(document).on('click', '.chk-go', function () {
    window.open(chkUrl($(this).data('go'), 'kw=' + encodeURIComponent($(this).data('kw'))), '_blank');
});
$(document).on('click', '.chk-n', function () {
    /* 除了帶料號過去搜尋，另外帶 prj_new=1 與專案資訊：
       目標頁看到這組參數就自動開「建立」跳窗並把已知的資料預填進去（使用者要求）。 */
    var p = CUR ? CUR.project : {};
    var q = 'kw=' + encodeURIComponent($(this).data('kw'))
          + '&prj_new=1'
          + '&ds_pk=' + num($(this).data('ds'))
          + '&doc=' + encodeURIComponent($(this).data('doc') || '')
          + '&project_id=' + num(p.project_id)
          + '&project_no=' + encodeURIComponent(p.project_no || '')
          + '&project_name=' + encodeURIComponent(p.project_name || '')
          + '&customer_id=' + encodeURIComponent(p.customer_id || '')
          + '&fai_date=' + encodeURIComponent((CUR && CUR.fai_pass_date) || '');
    window.open(chkUrl($(this).data('go'), q), '_blank');
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
/* 自動送簽核准。簽核時間由後端依 ai-rules/21 隨機錯開、不跨日。
   日期一律用月曆挑（UI 規則：日期欄位不可只讓人打字），而且有上下界——
   原本用 prompt() 既沒有月曆、也沒辦法把允許範圍講清楚。 */
$(document).on('click', '#btnAutoSign', function () {
    if (!planLeaveOk('自動送簽')) return;
    var g = (CUR && CUR.auto_sign_range) || {};
    $('#asDate').val(g['default'] || META.today || '')
        .attr('min', g.min || '').attr('max', g.max || '');
    $('#asRangeHint').html('可填範圍：<b>' + esc(dispDate(g.min)) + '</b> ~ <b>' + esc(dispDate(g.max)) + '</b>'
        + (g.note ? '<br>' + esc(g.note) : ''));
    $('#asErr').hide().text('');
    openMask('asMask');
});
/* 即時驗證：超出範圍當場紅字講原因，不要等按下去才被後端擋（ai-rules/08 第二之二節） */
function asDateCheck() {
    var g = (CUR && CUR.auto_sign_range) || {}, v = $('#asDate').val();
    var msg = '';
    if (!v) msg = '請選擇核准日期';
    else if (g.min && v < g.min) msg = '不可以早於專案起日（' + dispDate(g.min) + '）';
    else if (g.max && v > g.max) {
        msg = g.bom_date
            ? '必須早於最早的製令開立日（' + dispDate(g.bom_date) + '），最晚只能填 ' + dispDate(g.max)
            : '不可以填未來日期，最晚只能填 ' + dispDate(g.max);
    }
    $('#asErr').toggle(!!msg).text(msg);
    $('#asDate').toggleClass('fld-bad', !!msg);
    return !msg;
}
$(document).on('change input', '#asDate', asDateCheck);
$(document).on('click', '#btnAutoSignGo', function () {
    if (!asDateCheck()) return;
    api('auto_sign', { project_ids: String(CUR.project.project_id), biz_date: $('#asDate').val() }, 'POST')
        .done(function (r) {
            closeMask('asMask');
            loadList();
            /* 訊息要在重繪之後才顯示——renderDetail() 一開頭會 pjMsgClear()，先顯示會被清掉 */
            openProject(num(CUR.project.project_id), function () {
                pjMsg(r.message + '：現在可以回報進度了（實際開始／實際完成已開放）', { ok: true });
            });
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
    /* 預設選第一個啟用中的性質，**不要寫死 'C'**——專案性質已經可以由管理員改名或刪除 */
    var typeOpt = '', i0 = 0;
    $.each(META.types || {}, function (k, v) { typeOpt += '<option value="' + k + '"' + (i0++ === 0 ? ' selected' : '') + '>' + esc(v + '（' + k + '）') + '</option>'; });
    $('#o2pType').html(typeOpt);
    /* 專案負責人只列合格的人（模組設定 → 專案負責人資格）；沒設定時 owner_people＝全體 */
    var ownerOpt = '';
    $.each(ownerPeople(), function (i, x) {
        ownerOpt += '<option value="' + x.id + '"' + (num(x.id) === num(PERM.uid) ? ' selected' : '') + '>' + esc(peopleLabel(x)) + '</option>';
    });
    $('#o2pOwner').html(ownerOpt || '<option value="">（沒有符合資格的人員，請洽管理員設定）</option>');
    $('#oCust').val('');   /* 客戶改為模糊輸入（客戶ID或名稱），不再提供下拉 */
    renderTagPick('o2pTagBar', 'project', [], true);
    $('#oBody').html('<tr><td colspan="10" style="padding:12px;color:#8a6d45;">請先按「查詢」</td></tr>');
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
    $('#oBody').html('<tr><td colspan="10" style="padding:12px;color:#8a6d45;">查詢中…</td></tr>');
    api('order_candidates', {
        kw: $('#oKw').val(), cust: $('#oCust').val(), from: $('#oFrom').val(), to: $('#oTo').val(),
        include_closed: $('#oClosed').is(':checked') ? 1 : 0,
        first_only: $('#oFirst').is(':checked') ? 1 : 0
    }).done(function (res) {
        var rows = res.rows || [], items = res.ready_items || {};
        $('#oCount').text('找到 ' + rows.length + ' 張未綁定的訂單'
            + ($('#oFirst').is(':checked') ? '（只列第一次下訂）' : '')
            + (rows.length >= 500 ? '（僅顯示前 500 張，請縮小條件）' : ''));
        if (!rows.length) { $('#oBody').html('<tr><td colspan="10" style="padding:12px;color:#8a6d45;">沒有符合條件的訂單</td></tr>'); return; }
        var h = '';
        $.each(rows, function (i, o) {
            h += '<tr><td><input type="checkbox" class="o-ck" value="' + o.Order_id + '" data-eg-skip="1"></td>'
              + '<td>' + esc(o.Order_oo) + '</td><td>' + esc(o.C_order || '') + '</td>'
              + '<td>' + esc(o.Client_name || '') + '</td><td>' + esc(o.part_no) + '</td>'
              + '<td>' + num(o.Qty) + '</td><td>' + dispDate(o.Order_date) + '</td>'
              + '<td>' + dispDate(o.Delivery_date) + '</td>'
              + '<td title="' + esc(o.first_why || '') + '">' + firstBadge(o.is_first) + '</td>'
              + '<td>' + readyCells(o, items) + '</td></tr>';
        });
        $('#oBody').html(h);
    });
});

function firstBadge(v) {
    if (v === null || v === undefined) return '<span class="pj-hint">？</span>';
    return num(v) ? '<span class="st st-approved">首次</span>' : '<span class="pj-hint">－</span>';
}

/* 資料完整度：每一項一顆小籤，點下去開新分頁核對那份資料（使用者要求「可以點擊開啟資料確認」）。
   檢驗表尚未電子化＝灰籤「未電子化」，不列入分母。 */
function readyCells(o, items) {
    var r = o.ready || {}, pk = num(o.ds_pk), pn = o.part_no || '';
    var url = {
        order : '/EGsystem/src/store/_cleanNewOrder_Track.php',
        bom   : '/EGsystem/views/pm/bom_viewer.php?pk=' + pk + '&d_id=' + encodeURIComponent(pn),
        ship  : '/EGsystem/views/Sales/Shipping_Analysis_new.php?kw=' + encodeURIComponent(pn),
        work  : '/EGsystem/views/pm/bom_viewer.php?pk=' + pk + '&d_id=' + encodeURIComponent(pn),
        attach: '/EGsystem/views/pm/part_viewer.php?pk=' + pk + '&d_id=' + encodeURIComponent(pn)
    };
    var h = '<div style="display:flex;flex-wrap:nowrap;gap:2px;align-items:center;">';
    $.each(items, function (k, pair) {
        var full = pair[0] || pair, shortLbl = pair[1] || pair[0] || pair;
        var v = r[k];
        if (v === null || v === undefined) {
            h += '<span class="rdy rdy-na" title="' + esc(full) + '目前還沒有電子化，無法自動確認">' + esc(shortLbl) + '</span>';
            return;
        }
        var cls = num(v) ? 'rdy-ok' : 'rdy-no';
        var u = url[k] || '';
        var tip = full + '：' + (num(v) ? '有資料' : '查不到資料') + (u ? '（點開核對）' : '');
        h += u
            ? '<a class="rdy ' + cls + '" href="' + u + '" target="_blank" rel="noopener" title="' + esc(tip) + '">'
              + (num(v) ? '✓' : '✗') + esc(shortLbl) + '</a>'
            : '<span class="rdy ' + cls + '" title="' + esc(tip) + '">' + (num(v) ? '✓' : '✗') + esc(shortLbl) + '</span>';
    });
    h += '<b style="margin-left:4px;color:' + (num(o.ready_pct) === 100 ? '#2E7D32' : '#8a6d45') + ';">'
       + num(o.ready_pct) + '%</b></div>';
    return h;
}
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

/* ── 執行規劃表標準流程範本（工具列「帶入標準流程」帶的就是這一份） ──
   資料形狀與後端 prj_seed_template() 完全一樣：
     [{goal:'階段名稱', dept_id:主辦單位, tasks:[{name:'步驟', kind:'', dept_id:預設負責部門, owner_id:預設負責人}]}]
   ※ 每次改動都先把畫面收回 SEED_TPL 再整段重畫，畫面與資料只有一份、不會走鐘。 */
var SEED_TPL = [], SEED_CUSTOM = false;

function renderSeedTpl() {
    var deptOpt = '<option value="0">（未指定）</option>';
    $.each(META.depts || [], function (i, x) { deptOpt += '<option value="' + x.id + '">' + esc(x.name) + '</option>'; });
    var h = '';
    if (!SEED_TPL.length) h = '<div class="pj-hint">目前是空的，按「＋ 新增階段」開始編排，或按「還原內建預設」拿回系統內建那一份。</div>';
    $.each(SEED_TPL, function (gi, g) {
        h += '<div class="sec" data-sg="' + gi + '" style="background:#fff;">'
          + '<div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:6px;">'
          + '<span class="seed-h" data-sdrag="goal" draggable="true" title="拖曳調整階段順序" style="align-self:center;padding:6px 2px;">⠿</span>'
          + '<div style="flex:1;min-width:220px;"><label>階段 ' + (gi + 1) + ' 名稱 <span style="color:#DD5138;">*</span></label>'
          + '<input type="text" class="sg-name" value="' + esc(g.goal || '') + '"></div>'
          + '<div style="min-width:170px;"><label>主辦單位</label><select class="sg-dept"'
          + filterAttr(deptOpt, '輸入部門名稱篩選…') + '>' + deptOpt + '</select></div>'
          + '<button class="sg-del" style="height:30px;padding:0 12px;border:1px solid #C4442D;border-radius:4px;background:#DD5138;color:#fff;cursor:pointer;">刪除階段</button>'
          + '</div>'
          + '<table class="sub-tbl"><thead><tr><th style="width:34px;">#</th><th>步驟</th>'
          + '<th style="width:170px;">預設負責部門</th><th style="width:190px;">預設負責人</th>'
          + '<th style="width:56px;">操作</th></tr></thead><tbody>';
        $.each(g.tasks || [], function (ti, t) {
            var did = num(t.dept_id), dOpt = taskDeptOptions(did), pOpt = taskOwnerOptions(did, num(t.owner_id), true);
            var sys = String(t.kind || '');
            h += '<tr data-st="' + ti + '">'
              + '<td><span class="seed-h" data-sdrag="task" draggable="true" title="拖曳調整順序，也可以拖到別的階段">⠿</span>'
              + '<div style="font-size:11px;color:#8a6d45;">' + (ti + 1) + '</div></td>'
              + '<td><input type="text" class="st-name" value="' + esc(t.name || '') + '">'
              + (sys ? '<span class="pj-hint" style="margin-left:6px;">🔒 '
                       + (sys === 'fai' ? '首件' : (sys === 'rca' ? 'RCA' : '差異首件')) + '（系統固定環節）</span>' : '')
              + '</td>'
              + '<td><select class="st-dept"' + filterAttr(dOpt, '輸入部門名稱篩選…') + '>' + dOpt + '</select></td>'
              + '<td><select class="st-owner"' + filterAttr(pOpt, '輸入姓名篩選…') + '>' + pOpt + '</select></td>'
              + '<td>' + (sys ? '<span style="color:#b59b74;" title="系統固定環節，不可刪除">🔒</span>'
                              : '<span class="pj-op st-del" title="刪除這一列">✕</span>') + '</td></tr>';
        });
        h += '</tbody></table>'
          + '<button class="st-add" style="margin-top:6px;height:28px;padding:0 12px;border:1px solid #D8BE93;border-radius:4px;background:#fff;color:#5b3a1e;cursor:pointer;">＋ 新增步驟</button>'
          + '</div>';
    });
    $('#setSeedBox').html(h);
    /* 下拉一律用 .val() 設回去（選項是共用函式產的，不保證帶 selected） */
    $('#setSeedBox .sec[data-sg]').each(function () {
        var g = SEED_TPL[num($(this).data('sg'))] || {};
        $(this).find('.sg-dept').val(String(num(g.dept_id) || 0));
    });
    $('#setSeedState').text(SEED_CUSTOM ? '（目前使用自訂範本）' : '（目前使用系統內建預設）');
}

/** 把畫面上填的收回 SEED_TPL；任何會重畫的動作之前都要先呼叫，否則剛打的字會被洗掉 */
function seedSyncFromDom() {
    if (!$('#setSeedBox .sec[data-sg]').length) return;
    var out = [];
    $('#setSeedBox .sec[data-sg]').each(function () {
        var old = SEED_TPL[num($(this).data('sg'))] || { tasks: [] };
        var tasks = [];
        $(this).find('tbody tr').each(function () {
            var ot = (old.tasks || [])[num($(this).data('st'))] || {};
            tasks.push({ name: $.trim($(this).find('.st-name').val()), kind: String(ot.kind || ''),
                         dept_id: num($(this).find('.st-dept').val()),
                         owner_id: num($(this).find('.st-owner').val()) });
        });
        out.push({ goal: $.trim($(this).find('.sg-name').val()),
                   dept_id: num($(this).find('.sg-dept').val()), tasks: tasks });
    });
    SEED_TPL = out;
}
/** 送給後端的內容（空白的階段／步驟直接濾掉；後端 normalize 會用同一套規則再驗一次） */
function collectSeedTpl() {
    seedSyncFromDom();
    var out = [];
    $.each(SEED_TPL, function (i, g) {
        if (!g.goal) return;
        var ts = $.grep(g.tasks || [], function (t) { return !!t.name; });
        if (ts.length) out.push({ goal: g.goal, dept_id: num(g.dept_id), tasks: ts });
    });
    return out;
}
/* 負責部門一換，負責人清單跟著換（與規劃表同一套：先選部門、再選人） */
$(document).on('change', '#setSeedBox .st-dept', function () {
    var pOpt = taskOwnerOptions(num($(this).val()), 0, true);
    $(this).closest('tr').find('.st-owner')
        .replaceWith('<select class="st-owner"' + filterAttr(pOpt, '輸入姓名篩選…') + '>' + pOpt + '</select>');
});
$(document).on('click', '#btnSeedGoalAdd', function () {
    seedSyncFromDom();
    SEED_TPL.push({ goal: '', dept_id: 0, tasks: [{ name: '', kind: '', dept_id: 0, owner_id: 0 }] });
    renderSeedTpl();
});
$(document).on('click', '#setSeedBox .sg-del', function () {
    if (!confirm('刪除這個階段？底下的步驟會一起移除（要按「儲存設定」才會真的寫入）。')) return;
    var gi = num($(this).closest('.sec[data-sg]').data('sg'));
    seedSyncFromDom();
    SEED_TPL.splice(gi, 1);
    renderSeedTpl();
});
$(document).on('click', '#setSeedBox .st-add', function () {
    var gi = num($(this).closest('.sec[data-sg]').data('sg'));
    seedSyncFromDom();
    SEED_TPL[gi].tasks = (SEED_TPL[gi].tasks || []).concat([{ name: '', kind: '', dept_id: 0, owner_id: 0 }]);
    renderSeedTpl();
});
$(document).on('click', '#setSeedBox .st-del', function () {
    var gi = num($(this).closest('.sec[data-sg]').data('sg')), ti = num($(this).closest('tr').data('st'));
    seedSyncFromDom();
    SEED_TPL[gi].tasks.splice(ti, 1);
    if (!SEED_TPL[gi].tasks.length) SEED_TPL[gi].tasks = [{ name: '', kind: '', dept_id: 0, owner_id: 0 }];
    renderSeedTpl();
});
/* ── 拖曳排序 ─────────────────────────────────────────────────────────
   使用者要求：首件檢驗是固定環節、又總是排在最後一列，沒有拖曳就無法在它之前插入新程序。
   ※ 只把「把手」設成 draggable，不要整列 draggable——整列可拖時，欄位裡的文字會變成選不起來、
     一按就開始拖整列（Chrome 的既有行為）。拖曳圖示再用 setDragImage 指回整列，看起來仍是拖整列。 */
var SEED_DRAG = null;   // {type:'task'|'goal', gi, ti}

function seedDragClear() {
    $('#setSeedBox .seed-dz-before, #setSeedBox .seed-dz-after').removeClass('seed-dz-before seed-dz-after');
}
/** 滑鼠在這個元素的上半部還是下半部（決定插在它前面還是後面） */
function seedDropAfter(el, ev) {
    var r = el.getBoundingClientRect();
    return (ev.clientY - r.top) > r.height / 2;
}
$(document).on('dragstart', '#setSeedBox .seed-h', function (e) {
    var $h = $(this), isGoal = String($h.data('sdrag')) === 'goal';
    var gi = num($h.closest('.sec[data-sg]').data('sg'));
    SEED_DRAG = isGoal ? { type: 'goal', gi: gi }
                       : { type: 'task', gi: gi, ti: num($h.closest('tr').data('st')) };
    var el = (isGoal ? $h.closest('.sec[data-sg]') : $h.closest('tr'))[0];
    var dt = e.originalEvent.dataTransfer;
    if (dt) {
        dt.effectAllowed = 'move';
        dt.setData('text/plain', 'seed');           // Firefox 沒設資料就不會觸發 drop
        if (dt.setDragImage) dt.setDragImage(el, 12, 12);
    }
    $(el).addClass('seed-dragging');
});
$(document).on('dragend', '#setSeedBox .seed-h', function () {
    SEED_DRAG = null;
    $('#setSeedBox .seed-dragging').removeClass('seed-dragging');
    seedDragClear();
});
/* 拖步驟：目標是某一列，或某個階段的空白處（＝放到那一階段的最後） */
$(document).on('dragover', '#setSeedBox tbody tr', function (e) {
    if (!SEED_DRAG || SEED_DRAG.type !== 'task') return;
    e.preventDefault(); e.stopPropagation();
    seedDragClear();
    $(this).addClass(seedDropAfter(this, e.originalEvent) ? 'seed-dz-after' : 'seed-dz-before');
});
$(document).on('drop', '#setSeedBox tbody tr', function (e) {
    if (!SEED_DRAG || SEED_DRAG.type !== 'task') return;
    e.preventDefault(); e.stopPropagation();
    var gi = num($(this).closest('.sec[data-sg]').data('sg'));
    var ti = num($(this).data('st')) + (seedDropAfter(this, e.originalEvent) ? 1 : 0);
    seedMoveTask(SEED_DRAG, gi, ti);
});
$(document).on('dragover', '#setSeedBox .sec[data-sg]', function (e) {
    if (!SEED_DRAG) return;
    e.preventDefault();
    if (SEED_DRAG.type !== 'goal') return;           // 拖步驟時交給上面那條處理，不畫階段的插入線
    seedDragClear();
    $(this).addClass(seedDropAfter(this, e.originalEvent) ? 'seed-dz-after' : 'seed-dz-before');
});
$(document).on('drop', '#setSeedBox .sec[data-sg]', function (e) {
    if (!SEED_DRAG) return;
    e.preventDefault();
    var gi = num($(this).data('sg'));
    if (SEED_DRAG.type === 'task') {                 // 放在階段的空白處＝排到該階段最後
        seedSyncFromDom();
        seedMoveTask(SEED_DRAG, gi, (SEED_TPL[gi].tasks || []).length);
        return;
    }
    var to = gi + (seedDropAfter(this, e.originalEvent) ? 1 : 0);
    seedSyncFromDom();
    if (to === SEED_DRAG.gi || to === SEED_DRAG.gi + 1) { seedDragClear(); return; }
    var g = SEED_TPL.splice(SEED_DRAG.gi, 1)[0];
    SEED_TPL.splice(to > SEED_DRAG.gi ? to - 1 : to, 0, g);
    SEED_DRAG = null;
    renderSeedTpl();
});
/** 把 from 那一列搬到 gi 階段的第 ti 個位置（同一階段往後搬時要扣掉自己抽走的那一格） */
function seedMoveTask(from, gi, ti) {
    seedSyncFromDom();
    var t = (SEED_TPL[from.gi].tasks || []).splice(from.ti, 1)[0];
    if (!t) { SEED_DRAG = null; renderSeedTpl(); return; }
    if (gi === from.gi && ti > from.ti) ti--;
    SEED_TPL[gi].tasks = (SEED_TPL[gi].tasks || []);
    SEED_TPL[gi].tasks.splice(Math.max(0, Math.min(ti, SEED_TPL[gi].tasks.length)), 0, t);
    /* 來源階段被搬空時留一列空白，才有地方可以繼續打字（存檔時空白列本來就會濾掉） */
    if (!SEED_TPL[from.gi].tasks.length) SEED_TPL[from.gi].tasks = [{ name: '', kind: '', dept_id: 0, owner_id: 0 }];
    SEED_DRAG = null;
    renderSeedTpl();
}

$(document).on('click', '#btnSeedReset', function () {
    if (!confirm('把自訂的標準流程範本清掉、還原成系統內建的 AS9100 版本？\n\n（要按「儲存設定」才會真的寫入）')) return;
    api('seed_default').done(function (r) { SEED_TPL = r.rows || []; SEED_CUSTOM = false; renderSeedTpl(); });
});

function openSetting() {
    loadTypes();                 // 專案性質清單（每一列自己存檔，不跟著「儲存設定」走）
    api('setting_get').done(function (res) {
        var s = res.setting || {};
        var uOpt = '<option value="0">（不指定）</option>';
        $.each(META.people || [], function (i, x) { uOpt += '<option value="' + x.id + '">' + esc(peopleLabel(x)) + '</option>'; });
        $('#setApUser').html(uOpt).val(s.approver_user_id || '0');
        var dOpt = '<option value="0">（不指定）</option>';
        $.each(META.depts || [], function (i, x) { dOpt += '<option value="' + x.id + '">' + esc(x.name) + '</option>'; });
        $('#setApDept').html(dOpt).val(s.approver_dept_id || '0');
        $('#setBlockClose').prop('checked', String(s.block_close_on_missing) === '1');

        SEED_TPL = res.seed_template || [];
        SEED_CUSTOM = !!num(res.seed_is_custom);
        renderSeedTpl();

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

        /* 進度佐證：哪些附件標籤算「加工圖面」（標籤清單即時查主檔，不寫死名稱＝鐵律4） */
        var on = String(s.drawing_attach_cats || '').split(',').map(num);
        var ch = '';
        $.each(res.attach_cats || [], function (i, c) {
            ch += '<span class="pj-tag' + ($.inArray(num(c.id), on) >= 0 ? ' on' : '') + '" data-dwgcat="' + c.id + '">'
                + esc(c.category_name) + '</span>';
        });
        $('#setDwgCats').html(ch || '<span class="pj-hint">目前沒有啟用中的附件標籤。</span>');

        var on2 = String(s.o2p_attach_cats || '').split(',').map(num);
        var ch2 = '';
        $.each(res.attach_cats || [], function (i, c) {
            ch2 += '<span class="pj-tag' + ($.inArray(num(c.id), on2) >= 0 ? ' on' : '') + '" data-o2pcat="' + c.id + '">'
                 + esc(c.category_name) + '</span>';
        });
        $('#setO2pCats').html(ch2 || '<span class="pj-hint">目前沒有啟用中的附件標籤。</span>');

        /* 文件檢核：SOP／SIP 認列來源（可複選；勾選的一起算） */
        var SCOPES = { part: '綁料號', process: '製程', general: '通用' };
        $.each({ setSopScopes: 'doc_sop_scopes', setSipScopes: 'doc_sip_scopes' }, function (boxId, key) {
            var on = String(s[key] || 'part,process').split(',');
            var hh = '';
            $.each(SCOPES, function (k, v) {
                hh += '<span class="pj-tag' + ($.inArray(k, on) >= 0 ? ' on' : '') + '" data-scp="' + k + '">'
                    + esc(v) + '</span>';
            });
            $('#' + boxId).html(hh);
        });

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
    var names = $.map(ps, function (x) { return peopleLabel(x); });
    return '<br>目前符合資格（依<b>已儲存</b>的設定）共 ' + ps.length + ' 人：' + esc(names.join('、'))
         + '<br><span style="color:#8a6d45;">※ 名單上印的是<b>主要部門職稱</b>，兼任的職務列在括號裡，所以有人是靠兼任那個職務命中你設的部門（例：兼任技術部課長的董事長，設技術部後也會出現）。</span>';
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
        drawing_attach_cats: $('#setDwgCats .pj-tag.on').map(function () { return num($(this).data('dwgcat')); }).get().join(','),
        o2p_attach_cats: $('#setO2pCats .pj-tag.on').map(function () { return num($(this).data('o2pcat')); }).get().join(','),
        doc_sop_scopes: $('#setSopScopes .pj-tag.on').map(function () { return String($(this).data('scp')); }).get().join(','),
        doc_sip_scopes: $('#setSipScopes .pj-tag.on').map(function () { return String($(this).data('scp')); }).get().join(','),
        task_owner_depts: pickedTaskDepts().join(','),
        seed_template: JSON.stringify(collectSeedTpl()),
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
        SEED_TPL = r.seed_template || SEED_TPL;
        SEED_CUSTOM = !!num(r.seed_is_custom);
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
   ・列印紀錄依 ai-rules/23：三種列印都留一筆（來源代碼 project_mgmt）
   ・紙張方向：執行規劃表與專案管理卡皆 A4 橫式（2026-09-22 使用者回報）。
     紙本 2-GM-02-02 的 pageSetup 雖然是 portrait（27 個窄欄手寫），但網頁版的「周期」
     欄數是依專案期間動態切出來的，直式一律擠成一條看不出長條圖。使用者原話
     「A4橫式或是A3橫式才正確」——兩種都用得到，所以規劃表是：**周期欄 >10 欄**（橫向放不下）
     或 **內容超過一張 A4 橫式**（縱向放不下，printBootstrap 在 onload 實測）時自動升 A3 橫式。
     甘特格狀表被切成兩頁就看不出長短，寧可換大一張紙也要印在同一面。
   ・四邊留白依第四之二之二節：@page 14mm ＋ body padding 5mm 兩段式。
     **不可以再用原本的 10/8/12/8mm**——margin box 是貼著頁邊排的，實測
     12mm 底邊時 AS 編號只離紙張底緣 4.9mm，正好落在雷射印表機的不可列印區，
     畫面上（列印預覽）看得到、印出來卻不見了＝使用者回報「沒有照 AS 編號規則列印」的真因。
     margin box 另加 vertical-align:middle 讓它落在留白帶中央（約 7mm），不要貼邊。
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

/* 四邊留白（ai-rules/16 第四之二之二）：@page 14mm ＋ body padding 5mm 兩段式。
   body 那 5mm 是保險——列印視窗的「邊界」被選成「無／最小」時 Chrome 會直接蓋掉 @page 的 margin。 */
var PRINT_MG = 14, PRINT_PAD = 5;

/** 紙張短邊/長邊（mm），用來換算「內容有沒有超過一頁」＝要不要印頁碼 */
function printPageMm(paper, landscape) {
    var s = (paper === 'A3') ? 297 : 210, l = (paper === 'A3') ? 420 : 297;
    return landscape ? s : l;
}

/* 列印共用 CSS：頁碼與 AS 編號都交給 @page 的 margin box，不用 JS 量高度自算分頁（列印分頁鐵則）。
   頁碼那一條不寫在這裡——「多頁才印」要等內容排好才量得出來，由 printBootstrap() 在 onload 注入。 */
function printBaseCss(opt) {
    opt = opt || {};
    var paper = opt.paper || 'A4', land = !!opt.landscape;
    var css = '@page { size: ' + paper + ' ' + (land ? 'landscape' : 'portrait') + '; margin: ' + PRINT_MG + 'mm; }\n';
    /* AS 文件編號用**內文寫法**印在內容尾端靠右，不要用 @page 的 @bottom-right，也不要用 position:fixed。
       使用者 2026-09-22 回報「綁定的 AS 文件編號沒有顯示上去」，兩種頁角寫法都實測過：
         · `@page{@bottom-right}` 的 margin box：用 printToPDF 量得到，但**列印對話框的「邊界」
           被選成「無／最小」時 Chrome 會直接蓋掉 @page 的 margin，連帶整個 margin box 都不見**
           （ai-rules/16 第四之二之二節記過同一件事）。
         · `position:fixed; right:0; bottom:0`：實測在 Chrome 列印裡**根本沒畫出來**
           （`bottom:0` 的元素整個消失、`top:0` 的還跑到左邊），不可用。
       內文寫法唯一的缺點是內容短時離頁面實際右下角有距離，但**一定印得出來**，
       而本表單已設計成剛好一張 A3，內容本來就填滿整頁。 */
    if (opt.docNo) {
        css += '.as-doc-no { text-align:right; font-size:9pt; color:#333; margin-top:3mm; }\n';
    }
    /* 字型一律用全站同一套堆疊（ai-rules/16 第四之四），不要各頁自己選 */
    css += 'body { font-family:"Microsoft JhengHei","微軟正黑體",sans-serif; color:#000; margin:0;'
        +  ' padding:' + PRINT_PAD + 'mm; }\n'
        +  '* { box-sizing:border-box; }\n'
        +  '.p-co { text-align:center; font-size:16pt; font-weight:bold; letter-spacing:2px; }\n'
        +  '.p-en { text-align:center; font-size:9pt; letter-spacing:1px; margin-bottom:2mm; }\n'
        +  '.p-tt { text-align:center; font-size:14pt; font-weight:bold; margin-bottom:3mm; }\n'
        /* table-layout:fixed＋colgroup：欄一多時中文才不會被壓成直排一長條（第四之三節） */
        +  'table { border-collapse:collapse; width:100%; max-width:100%; table-layout:fixed; font-size:9pt; }\n'
        +  'th, td { border:1px solid #000; padding:1mm 1.5mm; vertical-align:top;'
        +  ' word-wrap:break-word; overflow-wrap:break-word; }\n'
        +  'th { background:#f2f2f2; text-align:center; font-weight:bold; }\n'
        +  '.c { text-align:center; }\n'
        +  '.nb { border:none; }\n'
        +  'thead { display:table-header-group; }\n'   /* 跨頁時表頭自然重複 */
        +  'tr { page-break-inside:avoid; }\n'
        /* 階段之間的粗分隔線（使用者指定）：整張表都是細線時分不出階段在哪裡斷開。
           規劃表與管理卡都用得到，所以放在共用的 base 裡不要各寫一份。 */
        +  'tr.gsep > td { border-top:0.8mm solid #000; }\n';
    return css;
}

/** 列印視窗的 onload：量內容有沒有超過一頁，超過才補左下角頁碼（ai-rules/16 第二節）。
 *  「多頁才印頁碼」一律用量的，不可以用筆數猜——換紙張方向、換欄數，筆數的門檻就不準了。
 *  後補的 @page 規則會和原本那條合併（同一份文件的 @page 會疊加），已用 printToPDF 實測。
 *
 *  **不要再做「放不下就自動換成 A3」**（2026-09-22 試過又拿掉）：
 *  CSS 說 A3、印表機紙匣裡是 A4 時，Chrome 會把 A3 的版面套到 A4 紙上，
 *  使用者看到的就是「內容只印在左半邊、右邊被裁掉、還變成 2 張紙」。
 *  紙張尺寸是實體設備決定的，網頁單方面宣告沒有用——一律 A4 橫式，
 *  放不下就讓瀏覽器自然分頁（表頭會跨頁重複、資料列不會被切成兩半、左下角有頁碼）。 */
function printBootstrap(opt) {
    opt = opt || {};
    var lim = Math.round((printPageMm(opt.paper || 'A4', !!opt.landscape) - PRINT_MG * 2 - PRINT_PAD * 2) * 96 / 25.4);
    var js = 'window.onload=function(){try{'
        + 'if(document.body.scrollHeight > ' + lim + '*0.98){'
        + 'var s=document.createElement("style");'
        + 's.textContent=\'@page{@bottom-left{content:"第 " counter(page) " 頁／共 " counter(pages) " 頁";'
        + 'font-size:9pt;color:#333;vertical-align:middle;}}\';'
        + 'document.head.appendChild(s);}'
        + '}catch(e){}setTimeout(function(){window.print();},350);};';
    return '<scr' + 'ipt>' + js + '</scr' + 'ipt>';
}

/** AS 文件編號（內文寫法，印在內容尾端靠右；理由見 printBaseCss 的註解） */
function docNoFixedHtml(docNo) {
    return docNo ? '<div class="as-doc-no">' + esc(docNo) + '</div>' : '';
}

/** 列印紀錄（ai-rules/23）：按下列印就留一筆，寫不寫得進去都不影響列印 */
function printLog(docName, refId, note) {
    try {
        if (window.EGPrintLog) {
            EGPrintLog.record({ source: 'project_mgmt', doc_name: docName, doc_kind: 'form',
                                ref_table: 'project', ref_id: refId || 0, note: note || '' });
        }
    } catch (e) { /* 靜默 */ }
}

function egPrintWindow(html) {
    var w = window.open('', '_blank');
    if (!w) { alert('瀏覽器擋掉了新視窗，請允許本站開啟彈出視窗後再試'); return; }
    w.document.write(html);
    w.document.close();
}

/* ── 2-GM-02-02 專案執行規劃表（A4 橫式；周期欄多時升 A3 橫式）── */
function printPlan(res) {
    var p = res.project;
    /* AS 編號的版次依業務日期回推（ai-rules/16 第三之四節）。
       表頭的「日期」已改成專案建立日期，版次基準也跟著用它，兩者才不會各講各的。 */
    api('print_meta', { module: 'project_plan',
                        biz_date: String(p.created_at || '').substring(0, 10) || p.start_date || META.today,
                        signer_ids: num(p.owner_id) })
    .done(function (m) {
        /* 掃描實體章是非同步載入的，沒等它有實體章的人會印成預設 SVG 章（eg_stamp.js 記過的坑） */
        var go = function () {
            printLog((m.meta.doc_name || '專案執行規劃表') + ' ' + (p.project_no || ''), num(p.project_id));
            egPrintWindow(buildPlanHtml(res, m));
        };
        if (window.EGStamp && EGStamp.whenReady) EGStamp.whenReady(go); else go();
    });
}

/** 負責人欄：使用者指定「顯示部門與職稱、人名就好，不需要簽章」，
 *  而且「部門 職稱 換行後顯示人名」。部門職稱一律取**主職務**
 *  （兼任職級較高的人用職級最高那筆會印成兼任身分，使用者已回報過這個問題）。 */
function ownerBlock(m, userId, name) {
    var s = (m.signers || {})[userId] || {};
    var d = s.main_dept || s.dept || '', j = s.main_post || s.post || '';
    var top = ((d ? d + ' ' : '') + j).replace(/\s+$/, '');
    return (top ? '<div style="font-size:9pt;">' + esc(top) + '</div>' : '')
         + '<div style="font-size:11pt;">' + esc(name || '') + '</div>';
}

function buildPlanHtml(res, m) {
    var p = res.project;
    var grouped = groupTasks(res.goals || [], res.tasks || []);
    /* 專案設定成「清單」檢視時列印就不畫甘特（使用者指定）：
       清單式的人要的是日期與進度，硬印一排空白周期格只是浪費半張紙。 */
    var isList  = (String(p.plan_view || 'gantt') === 'list');
    var periods = isList ? [] : planPeriods(res);

    /* 甘特式一律 **A3 橫式**（使用者指定「專案一樣要在一張 A3 橫式內」）：
       周期欄是依專案期間切出來的，A4 橫式塞不下就會分頁，甘特圖被切成兩頁就看不出長短。
       **列印時記得在對話框把紙張選成 A3**——CSS 只能宣告，紙匣裡放什麼是設備決定的，
       選成 A4 的話 Chrome 會把 A3 版面套到 A4 紙上、右邊被裁掉（2026-09-22 踩過）。
       清單式沒有周期欄，維持 A4 橫式就夠。 */
    var paper = isList ? 'A4' : 'A3';
    var css = printBaseCss({ landscape: true, paper: paper, docNo: m.meta.doc_no })
      + '.hdr td { border:1px solid #000; font-size:10pt; }\n'
      + '.ms { font-size:10pt; text-align:center; }\n'
      + (isList ? '' :
          /* 周期格：預計與實際**畫在同一格**，靠線型區分（使用者指定）。
             上下錯開一點點，兩條重疊時才不會被實線蓋掉虛線。 */
          '.pd { width:' + (periods.length ? (40 / periods.length) : 40) + '%; }\n'
        + '.pcell { padding:0; height:7mm; position:relative; }\n'
        + '.pbar { display:block; height:0; }\n'
        + '.pbar.plan { border-top:0.7mm solid #000; margin-top:2mm; }\n'
        + '.pbar.act  { border-top:0.7mm dashed #000; margin-top:1.6mm; }\n'
        + '.gl { margin-top:2mm; font-size:9pt; }\n'
        + '.gl i { display:inline-block; width:14mm; vertical-align:middle; margin:0 2mm 0 6mm; }\n'
        + '.gl i.p { border-top:0.7mm solid #000; }\n'
        + '.gl i.a { border-top:0.7mm dashed #000; }\n'
        + '.pdh { font-size:7.5pt; padding:0.5mm 0; }\n');

    var h = '<div class="p-co">' + esc(m.meta.company || '') + '</div>'
      + '<div class="p-en">EXCELLENT GEAR TECHNOLOGY CO.,LTD</div>'
      + '<div class="p-tt">' + esc(m.meta.doc_name || '專案執行規劃表') + '</div>';

    /* 表頭：專案名稱／專案負責人／專案目標／日期（比照紙本 B4/U4/B6/U6） */
    /* 專案料號（使用者要求「專案要顯示專案料號」）：取自專案料號清單，多個就全部列出來 */
    var partNos = $.map(res.parts || [], function (x) { return x.part_no || ''; });
    h += '<table class="hdr"><colgroup><col style="width:16%"><col style="width:44%"><col style="width:16%"><col style="width:24%"></colgroup>'
      + '<tr><td>專案名稱</td><td>' + esc(p.project_name) + '　<span style="font-size:9pt;">（專案代號 '
      + esc(p.project_no) + '）</span></td>'
      + '<td>專案負責人</td><td class="c">' + ownerBlock(m, p.owner_id, p.owner_name) + '</td></tr>'
      /* 日期＝**專案建立日期**（使用者 2026-09-22 指定），不是規劃表填寫日或專案起日 */
      + '<tr><td>專案料號</td><td>' + esc(partNos.length ? partNos.join('、') : '－') + '</td>'
      + '<td>日期</td><td class="c">' + dispDate(String(p.created_at || '').substring(0, 10)) + '</td></tr>'
      + '<tr><td>專案目標</td><td colspan="3">' + esc(p.goal_desc || '').replace(/\n/g, '<br>') + '</td></tr>'
      + '</table><div style="height:2mm;"></div>';

    h += isList ? planListTable(grouped) : planGanttTable(grouped, periods);
    h += docNoFixedHtml(m.meta.doc_no);

    return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'
        + esc(m.meta.doc_name || '專案執行規劃表') + '</title><style>' + css + '</style></head><body>' + h
        + printBootstrap({ landscape: true, paper: paper }) + '</body></html>';
}

/* 甘特（格狀周期表）版：**一個任務一列**，預計與實際畫在同一格、靠線型區分（使用者指定）。
   原本一個任務佔兩列（預計一列、實際一列），13 個任務就是 26 列，A3 也放不下；
   併成一列之後列數直接減半，而且同一格上下比對才看得出「實際有沒有落後預計」。 */
function planGanttTable(grouped, periods) {
    var groups = periodGroups(periods);
    /* table-layout:fixed 之後欄寬以 colgroup 為準，各欄加總要剛好 100%（超過會被整體壓縮） */
    var h = '<table><colgroup><col style="width:14%"><col style="width:20%"><col style="width:8%"><col style="width:8%">';
    $.each(periods, function () { h += '<col class="pd">'; });
    h += '<col style="width:10%"></colgroup><thead>'
      /* 三層表頭：周期 → 月份 → 日。使用者指定「列印上要顯示月份內的日」，
         只印日不印月的話，跨月時 1、2、3 會看不出是哪個月的 1、2、3。 */
      + '<tr><th rowspan="3">目標</th><th rowspan="3">主要任務</th>'
      + '<th colspan="2">專案完成日期</th>'
      + '<th colspan="' + Math.max(1, periods.length) + '">周期</th>'
      + '<th rowspan="3">負責人</th></tr>'
      + '<tr><th rowspan="2">預計</th><th rowspan="2">實際</th>';
    if (groups.length) { $.each(groups, function (i, g) { h += '<th colspan="' + g.span + '">' + esc(g.label) + '</th>'; }); }
    else h += '<th></th>';
    h += '</tr><tr>';
    if (periods.length) { $.each(periods, function (i, pr) { h += '<th class="pdh">' + esc(pr.label) + '</th>'; }); }
    else h += '<th></th>';
    h += '</tr></thead><tbody>';

    if (!grouped.length) {
        h += '<tr><td colspan="' + (5 + Math.max(1, periods.length)) + '" class="c">（尚未建立目標與任務）</td></tr>';
    }
    $.each(grouped, function (gi, g) {
        var list = g.tasks.length ? g.tasks : [{ task_name: '', owner_name: '' }];
        $.each(list, function (ti, t) {
            /* 階段（目標）之間用粗線分隔（使用者指定）——一整張表全是細線時分不出階段在哪裡斷開 */
            h += '<tr' + (ti === 0 && gi > 0 ? ' class="gsep"' : '') + '>';
            if (ti === 0) h += '<td rowspan="' + list.length + '">' + esc(g.goal_name) + '</td>';
            h += '<td>' + esc(t.task_name) + (num(t.is_milestone) ? '<span class="ms"> ◆</span>' : '') + '</td>'
               + '<td class="c">' + dispDate(t.plan_end) + '</td>'
               + '<td class="c">' + dispDate(t.act_end) + '</td>'
               + periodCellsBoth(t, periods)
               + '<td class="c">' + taskOwnerCell(t) + '</td></tr>';
        });
    });
    /* 線型圖例：紙上沒有顏色可用，不寫圖例就看不懂哪條是預計哪條是實際 */
    return h + '</tbody></table>'
         + '<div class="gl">線型說明：<i class="p"></i>預計<i class="a"></i>實際'
         + '　　◆ 里程碑</div>';
}

/* 清單版：沒有周期格，改印完整的預計／實際起迄與進度（一個任務一列，不分成兩列） */
function planListTable(grouped) {
    var h = '<table><colgroup><col style="width:16%"><col style="width:24%">'
      + '<col style="width:9%"><col style="width:9%"><col style="width:9%"><col style="width:9%">'
      + '<col style="width:7%"><col style="width:7%"><col style="width:10%"></colgroup><thead><tr>'
      + '<th rowspan="2">目標</th><th rowspan="2">主要任務</th>'
      + '<th colspan="2">預計</th><th colspan="2">實際</th>'
      + '<th rowspan="2">進度</th><th rowspan="2">狀態</th><th rowspan="2">負責人</th></tr>'
      + '<tr><th>開始</th><th>完成</th><th>開始</th><th>完成</th></tr></thead><tbody>';
    if (!grouped.length) h += '<tr><td colspan="9" class="c">（尚未建立目標與任務）</td></tr>';
    $.each(grouped, function (gi, g) {
        var list = g.tasks.length ? g.tasks : [{ task_name: '', owner_name: '' }];
        $.each(list, function (ti, t) {
            h += '<tr>';
            if (ti === 0) h += '<td rowspan="' + list.length + '">' + esc(g.goal_name) + '</td>';
            h += '<td>' + esc(t.task_name) + (num(t.is_milestone) ? '<span class="ms"> ◆</span>' : '') + '</td>'
               + '<td class="c">' + dispDate(t.plan_start) + '</td><td class="c">' + dispDate(t.plan_end) + '</td>'
               + '<td class="c">' + dispDate(t.act_start) + '</td><td class="c">' + dispDate(t.act_end) + '</td>'
               + '<td class="c">' + (t.task_name ? num(t.progress) + '%' : '') + '</td>'
               + '<td class="c">' + esc(t.task_name ? taskStateLabel(t) : '') + '</td>'
               + '<td class="c">' + taskOwnerCell(t) + '</td></tr>';
        });
    });
    return h + '</tbody></table>';
}

/* 清單檢視的「自動偵測」欄：直接把建議完成日印出來，讓人一眼看得到系統有沒有抓到。
   已經有實際完成日的就不再勸你改，只標「已回報」。 */
function autoHintCell(t) {
    if (t.act_end) return '<span class="pj-hint">已回報</span>';
    var a = autoHintOf(t);
    if (!a) {
        /* 偵測不到、但站上有那一頁可以建立的，給一個 ➕ 圖示直接開過去（使用者要求） */
        var c = autoCreateOf(t);
        return (autoKindsOf(t).length
                ? '<span class="pj-hint" title="這一類有自動偵測，但目前查不到資料">查無資料</span>'
                : '<span class="pj-hint" title="這個步驟不屬於可自動偵測的標準項目，請人工回報">－</span>')
             + (c ? ' <span class="pj-op pj-mk" data-mkurl="' + esc(c.url) + '" data-mktitle="' + esc(c.label)
                    + '" title="' + esc(c.label) + '（本系統已經有這一頁，點一下直接開過去建立）">'
                    + '<i class="fa fa-plus-circle"></i></span>' : '');
    }
    return '<span class="pj-op" data-report="' + t.task_id + '" title="' + esc(a.label)
         + '：偵測到 ' + a.n + ' 筆，點開可逐筆確認後採用">'
         + '<i class="fa fa-magic"></i> ' + dispDate(a.date)
         + (a.n > 1 ? '<span class="pj-hint">（' + a.n + ' 筆）</span>' : '') + '</span>';
}

/** 列印的「負責人」欄：部門在上、姓名在下（使用者指定要顯示部門）。
 *  還沒指定負責人的印「排班人員」——紙本上留白看不出是漏填還是本來就由排班決定。
 *  部門取 project_task.owner_dept_id 對應的部門名（那是「以哪個部門的身分被指派」，
 *  不是這個人現在的主職部門，兼任者才不會印錯邊）。 */
function taskOwnerCell(t) {
    var nm = $.trim(String(t.owner_name || ''));
    if (!nm) return '<span style="font-size:8.5pt;">排班人員</span>';
    var dp = $.trim(String(t.owner_dept_name || ''));
    return (dp ? '<div style="font-size:8pt;">' + esc(dp) + '</div>' : '') + esc(nm);
}

/* 列印用的狀態文字：畫面上是彩色小籤，紙上只能印字 */
function taskStateLabel(t) {
    var m = META.task_status || {};
    if (t.status_code && m[t.status_code]) return m[t.status_code];
    if (t.act_end) return '已完成';
    if (t.act_start) return '進行中';
    return '未開始';
}

/* 周期欄位（列印用）：刻度**依專案實際長度自動決定**，並帶出上一層的月份分組。
   使用者回報「前端看到的甘特圖跟列印不同」「列印上要顯示月份內的日，避免看不出是哪個日期」——
   原本不管專案多短一律切成「月」，三週的專案就只剩一個「9月」欄、所有長條擠在同一格，
   跟畫面上逐日的時間軸完全對不起來。
   軸的範圍與畫面共用同一支 ganttRange()，兩邊才會是同一段期間。 */
function planPeriods(res) {
    var r = ganttRange(res.project, res.tasks || []);
    if (!r) return [];
    var d0 = new Date(r.start + 'T00:00:00'), d1 = new Date(r.end + 'T00:00:00');
    var days = Math.round((d1 - d0) / 86400000) + 1;
    var out = [];
    if (days <= 45) {                       // 短專案：逐日，這樣才看得出是哪一天
        var c = new Date(d0);
        while (c <= d1 && out.length < 60) {
            out.push({ start: fmtYmd(c), end: fmtYmd(c),
                       label: String(c.getDate()), group: (c.getMonth() + 1) + '月' });
            c.setDate(c.getDate() + 1);
        }
    } else if (days <= 200) {               // 中等：逐週（標週一那天的月/日）
        var w = new Date(d0);
        var guardW = 0;
        while (w.getDay() !== 1 && guardW++ < 7) w.setDate(w.getDate() - 1);
        while (w <= d1 && out.length < 40) {
            var we = new Date(w); we.setDate(we.getDate() + 6);
            out.push({ start: fmtYmd(w), end: fmtYmd(we),
                       label: (w.getMonth() + 1) + '/' + w.getDate(), group: (w.getMonth() + 1) + '月' });
            w.setDate(w.getDate() + 7);
        }
    } else {                                // 長專案：逐月
        var cur = new Date(d0.getFullYear(), d0.getMonth(), 1);
        var months = (d1.getFullYear() - d0.getFullYear()) * 12 + (d1.getMonth() - d0.getMonth()) + 1;
        for (var i = 0; i < Math.min(months, 36); i++) {
            var ms = new Date(cur.getFullYear(), cur.getMonth(), 1);
            var me = new Date(cur.getFullYear(), cur.getMonth() + 1, 0);
            out.push({ start: fmtYmd(ms), end: fmtYmd(me),
                       label: (ms.getMonth() + 1) + '月', group: ms.getFullYear() + '年' });
            cur.setMonth(cur.getMonth() + 1);
        }
    }
    return out;
}

/** 把周期欄依 group 併成上一層表頭（月份／年份）——「日」要有月份罩著才知道是哪個月的日 */
function periodGroups(periods) {
    var g = [];
    $.each(periods, function (i, p) {
        if (g.length && g[g.length - 1].label === p.group) { g[g.length - 1].span++; return; }
        g.push({ label: p.group, span: 1 });
    });
    return g;
}
function fmtYmd(d) {
    return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
}

/** 同一格裡同時畫預計與實際（線型不同、上下略錯開，重疊時才不會被實線蓋掉虛線） */
function periodCellsBoth(t, periods) {
    if (!periods.length) return '<td></td>';
    var hit = function (kind, pr) {
        var s = kind === 'plan' ? (t.plan_start || t.plan_end) : (t.act_start || t.act_end);
        var e = kind === 'plan' ? (t.plan_end || t.plan_start) : (t.act_end || t.act_start);
        return (s && e && s <= pr.end && e >= pr.start);
    };
    var out = '';
    $.each(periods, function (i, pr) {
        out += '<td class="pcell">'
            + (hit('plan', pr) ? '<span class="pbar plan"></span>' : '')
            + (hit('act', pr) ? '<span class="pbar act"></span>' : '')
            + '</td>';
    });
    return out;
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
        var go = function () {
            printLog((m.meta.doc_name || '專案管理卡') + ' ' + (c.card_no || p.project_no || ''), num(p.project_id));
            egPrintWindow(buildCardHtml(res, m));
        };
        if (window.EGStamp && EGStamp.whenReady) EGStamp.whenReady(go); else go();
    });
}

function buildCardHtml(res, m) {
    var c = res.card, p = res.project;
    var items = c.items || [];
    var css = printBaseCss({ landscape: true, docNo: m.meta.doc_no })
      + '.sign td { border:1px solid #000; height:22mm; vertical-align:middle; text-align:center; }\n'
      + '.sign .lb { width:8%; background:#f2f2f2; font-weight:bold; }\n'
      + '.meta { border:none; margin-bottom:2mm; font-size:10pt; }\n'
      + '.meta td { border:none; padding:0 2mm 1mm 0; }\n';

    var h = '<div class="p-co">' + esc(m.meta.company || '') + '</div>'
      + '<div class="p-en">EXCELLENT GEAR TECHNOLOGY CO.,LTD</div>'
      + '<div class="p-tt">' + esc(m.meta.doc_name || '專案管理卡') + '</div>'
      /* 表頭寫**客戶與料號**、**不寫專案名稱**（使用者指定）：
         管理卡是對「這一家的這個料號」的檢討，看的人要的是客戶與料號。 */
      + '<table class="meta"><tr>'
      + '<td><b>客戶：</b>' + esc(p.customer_name || '－') + '</td>'
      + '<td><b>料號：</b>' + esc($.map(res.parts || [], function (x) { return x.part_no || ''; }).join('、') || '－') + '</td>'
      + '<td><b>專案代號：</b>' + esc(p.project_no) + '</td>'
      + '<td><b>管理卡編號：</b>' + esc(c.card_no || '') + '</td>'
      + '<td style="text-align:right;"><b>檢討日期：</b>' + dispDate(c.review_date) + '</td>'
      + '</tr></table>';

    /* 表身依使用者指定的欄位：項次／專案階段與核心作業項目／主辦·承辦人／預計完成日／
       實際完成日／交付成果·單號／狀態·簽核。階段一列，底下接它的作業項目。 */
    var tasks = res.tasks || [];
    h += '<table><colgroup><col style="width:5%"><col style="width:28%"><col style="width:12%">'
      + '<col style="width:9%"><col style="width:9%"><col style="width:22%"><col style="width:15%"></colgroup>'
      + '<thead><tr><th>項次</th><th>專案階段與核心作業項目</th><th>主辦／承辦人</th>'
      + '<th>預計完成日</th><th>實際完成日</th><th>交付成果／單號</th><th>狀態／簽核</th></tr></thead><tbody>';
    if (!items.length) h += '<tr><td colspan="7" class="c">（無項次）</td></tr>';
    $.each(items, function (i, it) {
        var gid = num(it.goal_id);
        var gt = $.grep(tasks, function (t) { return num(t.goal_id) === gid; });
        var doneN = $.grep(gt, function (t) { return taskState(t, META.today) === 'done'; }).length;
        var allDone = gt.length > 0 && doneN >= gt.length;
        h += '<tr class="gsep"><td class="c">' + (i + 1) + '</td>'
          + '<td><b>' + esc(it.goal_name || '') + '</b></td>'
          + '<td class="c">' + esc(it.dept_name || '') + (it.owner_name ? '<br>' + esc(it.owner_name) : '') + '</td>'
          + '<td class="c">' + dispDate(cardGoalDate(gt, 'plan_end', true)) + '</td>'
          + '<td class="c">' + (allDone ? dispDate(cardGoalDate(gt, 'act_end', true)) : '') + '</td>'
          + '<td>' + (num(it.on_track) && !$.trim(it.issue_text || '')
                      ? '依計畫進行' : esc(it.issue_text || '').replace(/\n/g, '<br>')) + '</td>'
          + '<td class="c">' + (allDone ? '已完成' : doneN + '/' + gt.length) + '</td></tr>';
        $.each(gt, function (ti, t) {
            var deliver = $.map(parseEvidence(t.evidence_json), function (e) {
                return String(e.label || '').replace(/<[^>]*>/g, '');
            });
            h += '<tr><td class="c">' + (i + 1) + '.' + (ti + 1) + '</td>'
              + '<td style="padding-left:4mm;">' + esc(t.task_name)
              + (num(t.is_milestone) ? ' ◆' : '') + '</td>'
              + '<td class="c">' + esc(t.owner_dept_name || '')
              + '<br>' + esc(t.owner_name || '排班人員') + '</td>'
              + '<td class="c">' + dispDate(t.plan_end) + '</td>'
              + '<td class="c">' + dispDate(t.act_end) + '</td>'
              + '<td>' + esc(deliver.join('；')) + '</td>'
              + '<td class="c">' + esc(taskStateLabel(t))
              + (t.reported_by_name ? '<br>' + esc(t.reported_by_name) : '') + '</td></tr>';
        });
        /* 後續辦理方法／備註有填才印一列，沒填不要浪費紙面 */
        var extra = $.trim(String(it.follow_text || '')) + ($.trim(String(it.note || '')) ? '　【備註】' + it.note : '');
        if ($.trim(extra)) {
            h += '<tr><td></td><td colspan="6" style="padding-left:4mm;"><b>後續辦理：</b>'
              + esc(extra).replace(/\n/g, '<br>') + '</td></tr>';
        }
    });
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
      + '</tr></table>'
      + docNoFixedHtml(m.meta.doc_no);

    return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'
        + esc(m.meta.doc_name || '專案管理卡') + '</title><style>' + css + '</style></head><body>' + h
        + printBootstrap({ landscape: true }) + '</body></html>';
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
    var css = printBaseCss({ landscape: true, docNo: '' });
    printLog('專案執行狀況總覽（內部管理用，' + rows.length + ' 筆）', 0, '非 AS 表單');
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
        + css + '</style></head><body>' + h + printBootstrap({ landscape: true }) + '</body></html>');
});
