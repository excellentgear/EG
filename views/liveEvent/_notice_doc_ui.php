<?php
/**
 * 公告/通知/聯絡單：圖文編輯器、草稿、核准流程的前端（2026-10-22）
 * 由 createEvent.php include；變數 $ND_* 由該頁準備。後端判定一律在 src/common/notice_doc_lib.php。
 */
$__ndKinds = ndoc_kinds();
$__ndApprName = $ND_APPR_UID ? ndoc_user_name($NC_PDO, (int)$ND_APPR_UID) : '';
?>
<style>
    #ndDecideModal .modal-dialog, #ndApprModal .modal-dialog { width: 860px; max-width: 96%; }   /* 固定像素，禁用 vw */
    .nd-prev { border: 1px solid #d8c7b0; border-radius: 6px; padding: 12px 16px; max-height: 340px; overflow: auto; background: #fff; line-height: 1.8; }
    .nd-prev table { border-collapse: collapse; } .nd-prev td, .nd-prev th { border: 1px solid #888; padding: 2px 6px; }
    .nd-prev img { max-width: 100%; }
    .nd-row { display: flex; align-items: center; gap: 8px; padding: 6px 0; border-bottom: 1px dashed #eadfcf; flex-wrap: wrap; }
    .nd-row .nd-t { flex: 1; min-width: 200px; font-weight: 600; }
    .nd-tag { background: #F7E0BD; color: #6B471A; border-radius: 10px; padding: 0 8px; font-size: 11.5px; line-height: 18px; }
    .nd-tag.bad { background: #F8DCD5; color: #a33; }
    .nd-sec { font-weight: 700; margin: 8px 0 2px; color: #6B471A; }
    .nd-btn { border: 1px solid #d8c7b0; background: #faf6f0; color: #6B471A; border-radius: 4px; padding: 2px 10px; font-size: 12.5px; cursor: pointer; }
    .nd-btn:hover { background: #f2e6d4; }
    #contentEditor .egrt-doc-scroll { background: #e9e4dc; }
    #nd-dirty { position: fixed; right: 18px; bottom: 16px; z-index: 2000; background: #F8DCD5; color: #8a3a2a; border: 1px solid #e9b8ac; border-radius: 6px; padding: 6px 14px; font-size: 13px; font-weight: 600; box-shadow: 0 2px 8px rgba(0,0,0,.15); display: none; }
    #nd-toast { position: fixed; right: 18px; bottom: 16px; z-index: 2001; background: #F7E0BD; color: #6B471A; border-radius: 6px; padding: 6px 14px; font-size: 13px; display: none; }
    /* 公告編輯區只有半個版面寬，兩張 A4 並排放不下、會一直重排當機 → 不提供一頁／雙頁切換（固定單頁） */
    #contentEditor [data-view] { display: none !important; }
</style>

<div id="nd-dirty"><i class="fa fa-exclamation-circle"></i> 未儲存</div>
<div id="nd-toast"></div>

<!-- 核准設定（超級管理員） -->
<div class="modal fade" id="ndApprModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title"><i class="fa fa-check-square-o"></i> 公告／通知／聯絡單 核准設定</h4></div>
        <div class="modal-body">
            <p style="color:#7a6a58;font-size:13px;">逐種設定送出後要<b>自動簽核蓋准章</b>（直接發布，核准章自動蓋上）或<b>需要核准</b>（送出後先交給核准人員，核准後才發布給對象；核准人可核准或退回，退回要填原因）。三種表單的核准人員相同。</p>
            <table class="table table-condensed" style="max-width:520px;">
                <?php foreach ($__ndKinds as $__k => $__n) : ?>
                    <tr><td style="width:120px;vertical-align:middle;"><b><?= htmlspecialchars($__n) ?></b></td>
                        <td><select class="form-control nd-mode" data-kind="<?= $__k ?>">
                            <option value="auto">自動簽核蓋准章（送出即發布）</option>
                            <option value="required">需要核准</option>
                        </select></td></tr>
                <?php endforeach; ?>
            </table>
            <div class="form-group" style="max-width:620px;">
                <label>核准人員（三種相同）</label>
                <input type="text" id="ndApprKw" class="form-control" placeholder="輸入姓名 / 部門篩選…" style="margin-bottom:4px;">
                <select id="ndApprUser" class="form-control">
                    <option value="">（沿用組織角色綁定的最高核准人員<?= $__ndApprName ? '：' . htmlspecialchars($__ndApprName) : '，目前尚未設定' ?>）</option>
                    <?php foreach ($users as $u) : ?>
                        <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars(trim(($u['department_name'] ?? '') . '　' . ($u['position_name'] ?? '') . '　' . $u['user_cname'])) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="help-block" style="font-size:12px;">核准章與核准日期會印在公告／通知／聯絡單的「核准」欄；日期＝核准人按核准的業務日期（自動簽核＝發布日）。核准人本人送出的單不必等自己，直接發布。</span>
            </div>
            <div id="ndApprMsg" style="color:#c0392b;min-height:20px;"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">關閉</button><button type="button" class="btn btn-primary" id="ndApprSave">儲存設定</button></div>
    </div></div>
</div>

<!-- 核准／退回 -->
<div class="modal fade" id="ndDecideModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title" id="ndDecTitle">核准</h4></div>
        <div class="modal-body">
            <div id="ndDecMeta" style="font-size:13px;color:#6B471A;margin-bottom:6px;"></div>
            <div class="nd-prev" id="ndDecPrev"></div>
            <div id="ndDecAct" style="margin-top:12px;">
                <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;">
                    <div><label style="font-size:12.5px;">核准日期（不可是未來）</label><br><input type="date" id="ndDecDate" class="form-control" style="width:170px;"></div>
                    <div style="flex:1;min-width:260px;"><label style="font-size:12.5px;">退回原因（退回必填）／核准意見（選填）</label><input type="text" id="ndDecNote" class="form-control" maxlength="300"></div>
                </div>
            </div>
            <div id="ndDecMsg" style="color:#c0392b;min-height:20px;margin-top:6px;"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default nd-pv-cur"><i class="fa fa-print"></i> 預覽列印</button>
            <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
            <button type="button" class="btn btn-danger" id="ndDecReject">退回</button>
            <button type="button" class="btn btn-primary" id="ndDecApprove">核准並發布</button>
        </div>
    </div></div>
</div>

<script>
(function () {
    'use strict';
    var API = '../../src/store/NoticeDoc_API.php';
    var ND = {
        csrf: <?= json_encode($ND_CSRF) ?>,
        isAdmin: <?= $ND_ADMIN ? 'true' : 'false' ?>,
        meUid: <?= (int)$id ?>,
        apprUid: <?= (int)$ND_APPR_UID ?>,
        apprName: <?= json_encode($__ndApprName, JSON_UNESCAPED_UNICODE) ?>,
        modes: <?= json_encode(['notice' => $ND_APPR['mode_notice'], 'announce' => $ND_APPR['mode_announce'], 'contact' => $ND_APPR['mode_contact']]) ?>,
        apprUser: <?= json_encode($ND_APPR['approver_user_id']) ?>,
        kinds: <?= json_encode($__ndKinds, JSON_UNESCAPED_UNICODE) ?>,
        html: <?= json_encode($ND_HTML, JSON_UNESCAPED_UNICODE) ?>,
        isEdit: <?= $is_edit ? 'true' : 'false' ?>,
        approveId: <?= (int)($_GET['notice_approve'] ?? 0) ?>
    };
    var ed = null;
    function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
    function fmtD(s) { return window.egFmtDate ? egFmtDate((s || '').substr(0, 10)) : (s || ''); }
    function today() { var d = new Date(); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
    function post(p, ok, fail) {
        p.csrf = ND.csrf;
        $.post(API, p, function (r) { if (r && r.ok) ok(r); else (fail || alert)((r && r.error) || '操作失敗'); }, 'json')
         .fail(function (x) { var m = ''; try { m = JSON.parse(x.responseText).error; } catch (e) {} (fail || alert)(m || '操作失敗（' + x.status + '）'); });
    }

    /* ---------- 圖文編輯器 ---------- */
    function sync() {
        if (!ed) return;
        var txt = (ed.text() || '').replace(/\s+$/, '').replace(/^\s+/, '');
        var html = ed.get();
        $('#content_html').val(html);
        $('#content').val(txt || (html ? '（圖文內容）' : '')).trigger('input');
    }
    function upload(file, done) {
        var fd = new FormData();
        fd.append('action', 'asset_upload'); fd.append('csrf', ND.csrf); fd.append('file', file);
        $.ajax({ url: API, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
         .done(function (r) { if (r && r.ok) done(r.id); else alert((r && r.error) || '圖片上傳失敗'); })
         .fail(function (x) { var m = ''; try { m = JSON.parse(x.responseText).error; } catch (e) {} alert(m || '圖片上傳失敗'); });
    }
    function pickImage(insert) {
        var inp = $('<input type="file" accept="image/jpeg,image/png,image/gif" style="position:fixed;left:-9999px;">').appendTo('body');
        inp.on('change', function () {
            var f = this.files && this.files[0];
            inp.remove();
            if (f) upload(f, function (id) { insert(id, { width: '60%' }); sync(); });
        });
        inp.trigger('click');
    }
    $(function () {
        if (!$('#contentEditor').length || !window.EGRichText) return;
        ed = EGRichText.attach('#contentEditor', {
            profile: 'doc', pageSize: 'A4', orientation: 'portrait', height: 460,
            placeholder: '在這裡輸入內容…（可直接貼上 Word 的表格與格式；圖片請按工具列的圖片鈕或直接貼上截圖）',
            assetUrl: function (id) { return API + '?action=asset&id=' + id; },
            onInsertImage: pickImage,
            onChange: sync
        });
        var init = ND.html;
        if (!init) {   // 舊公告只有純文字：轉成段落帶進編輯器
            var plain = $('#content').val() || '';
            if (plain) init = '<p>' + esc(plain).replace(/\r?\n/g, '</p><p>') + '</p>';
        }
        if (init) ed.set(init);
        sync();
        // 貼上截圖：剪貼簿只有圖片檔（沒有 html）時，上傳後放在游標處
        $('#contentEditor')[0].addEventListener('paste', function (e) {
            var dt = e.clipboardData; if (!dt || !dt.files || !dt.files.length) return;
            if (dt.getData('text/html')) return;   // 從 Word 複製的內容帶 html，交給編輯器原本的清洗
            var f = dt.files[0];
            if (!/^image\/(png|jpe?g|gif)$/.test(f.type)) return;
            e.preventDefault(); e.stopPropagation();
            upload(f, function (id) { ed.insertAsset(id, { width: '60%' }); sync(); });
        }, true);
        $('form').has('#contentEditor').on('submit', sync);
        // 列印用：編輯區內文寬（CSS px，不含 zoom），列印時把表格 px 寬度換算成佔內文寬的比例
        window.ndEditorContentWidth = function () {
            var m = document.querySelector('#contentEditor .egrt-main'); if (!m) return 0;
            var cs = getComputedStyle(m);
            return m.clientWidth - parseFloat(cs.paddingLeft || 0) - parseFloat(cs.paddingRight || 0);
        };

        /* 表格「最後一欄」右邊框往右拖＝整張表加寬（共用編輯器只能在相鄰兩欄之間分配寬度、總寬不變，
           最後一欄右邊沒有鄰欄所以拉不動，使用者回報「無法拉寬表格」）。上限＝紙張內文寬度。
           寬度寫在儲存格與 table 的 style width（白名單本來就放行 px），不動共用檔。 */
        var host = $('#contentEditor')[0];
        function lastEdge(e) {
            var c = e.target && e.target.closest ? e.target.closest('td,th') : null;
            if (!c || !host.contains(c) || !c.closest('.egrt-main') || c.nextElementSibling) return null;
            var r = c.getBoundingClientRect();
            return (r.right - e.clientX <= 5 && r.right - e.clientX >= -2) ? c : null;
        }
        host.addEventListener('mousemove', function (e) {
            if (e.buttons) return;
            var c = lastEdge(e);
            if (c) c.closest('.egrt-main').style.cursor = 'col-resize';   // 在共用編輯器的 mousemove 之後執行，不會被它清掉
        });
        host.addEventListener('mousedown', function (e) {
            if (e.button !== 0) return;
            var cell = lastEdge(e); if (!cell) return;
            var tbl = cell.closest('table'), main = cell.closest('.egrt-main');
            e.preventDefault(); e.stopPropagation();
            var cs = getComputedStyle(main), maxW = main.clientWidth - parseFloat(cs.paddingLeft || 0) - parseFloat(cs.paddingRight || 0);
            // 編輯區會依視窗寬度用 CSS zoom 縮小整頁（fitPages），getBoundingClientRect 與滑鼠座標都是縮放後的值，
            // 寫進 style 的 px 一律要除回 zoom，否則存下來的寬度與列印／檢視對不起來
            var k = parseFloat(getComputedStyle(main.closest('.egrt-pages') || main).zoom) || 1;
            var w0 = tbl.getBoundingClientRect().width / k, wc = cell.getBoundingClientRect().width / k, x0 = e.clientX;
            var lasts = [];
            Array.prototype.slice.call(tbl.rows).forEach(function (row) {
                Array.prototype.slice.call(row.cells).forEach(function (c) {
                    if ((c.colSpan || 1) === 1) c.style.width = Math.round(c.getBoundingClientRect().width / k * 10) / 10 + 'px';   // 先凍結，拖動時不被重新分配
                });
                if (row.cells.length) lasts.push(row.cells[row.cells.length - 1]);
            });
            function mv(ev) {
                var dx = Math.max(-(wc - 30), Math.min(maxW - w0, (ev.clientX - x0) / k));
                lasts.forEach(function (c) { if ((c.colSpan || 1) === 1) c.style.width = (Math.round((wc + dx) * 10) / 10) + 'px'; });
                tbl.style.width = (Math.round((w0 + dx) * 10) / 10) + 'px';
            }
            function up() {
                document.removeEventListener('mousemove', mv); document.removeEventListener('mouseup', up);
                main.dispatchEvent(new Event('input', { bubbles: true }));   // 讓編輯器存入變更
            }
            document.addEventListener('mousemove', mv); document.addEventListener('mouseup', up);
        }, true);
    });

    /* ---------- 種類切換 ---------- */
    function kindUi() {
        var k = $('#kind').val();
        $('#post-until-wrap').toggle(k === 'announce');
        // 回簽只有聯絡單：公告不需回簽、通知只要已閱 → 隱藏「回簽／回覆+回簽」區塊，其中的對象移回「已閱」
        var isC = (k === 'contact');
        $('.eg-tmzone[data-mode="sign"],.eg-tmzone[data-mode="reply"]').toggle(isC);
        $('#reply_deadline').closest('.eg-field').toggle(isC);
        if (!isC) {
            var $mv = $('#zone-sign .eg-tmchip,#zone-reply .eg-tmchip');
            if ($mv.length) { $mv.appendTo('#zone-read'); try { window.egSyncTargetModes(); } catch (e) {} }
        }
        var req = ND.modes[k] === 'required';
        $('#send-label').text(req ? '送出核准' : '送出發布');
        var h = req ? '此種類需經核准人員' + (ND.apprName ? '（' + ND.apprName + '）' : '') + '核准後才會發布給對象。'
                    : '依設定自動簽核蓋准章，送出即發布。';
        if (k === 'contact') h += '列印時右下角印 AS 文件編號。'; else h += '列印時右下角不印文件編號。';
        h += k === 'announce' ? '公告不需回簽。' : (k === 'notice' ? '通知只需已閱。' : '聯絡單可設已閱或回簽（逐對象設定）。');
        $('#kind-hint').text(h);
    }
    $(document).on('change', '#targets', function () { setTimeout(kindUi, 50); });
    $(function () { setTimeout(kindUi, 900); });
    $(document).on('change', '#kind', kindUi);
    $(kindUi);

    /* ---------- 草稿 ---------- */
    /* 未儲存提示（右下角淺紅）：表單有任何更動就亮，存成草稿（或預覽時自動存）後消失；
       正式送出／取消／清除時不再提示離開。初始化期間編輯器會 sync 好幾次，所以延遲開始追蹤。 */
    var dirty = false, track = false;
    function setDirty(v) { dirty = v; $('#nd-dirty').toggle(!!v); }
    function mark() { if (track && !dirty) setDirty(true); }
    $(function () { setTimeout(function () { track = true; }, 1500); });
    $(document).on('input change', '.eg-card-form form :input:not([type=file])', mark);
    $(document).on('dragend click', '#eg-zones', function () { setTimeout(mark, 0); });
    $(document).on('click', '#eg-zones .eg-tmchip', function () { mark(); });
    $(document).on('submit', '.eg-card-form form', function () { setDirty(false); track = false; });
    $(document).on('click', 'button[name="resetEvent"]', function () { setDirty(false); track = false; });
    window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    function toast(m) { $('#nd-toast').text(m).stop(true, true).fadeIn(120).delay(1800).fadeOut(300); }

    function draftPayload() {
        sync();
        try { window.egSyncTargetModes(); } catch (e) {}
        return {
            action: 'draft_save',
            draft_id: $('#draft_id').val() || 0, kind: $('#kind').val(),
            eventdate: $('#eventdate').val(), enddate: $('#enddate').val(), reply_deadline: $('#reply_deadline').val(),
            post_until: $('#post_until').val(), title: ($('#title').val() || '').trim(), content_html: $('#content_html').val(),
            show_status_to_others: $('input[name="show_status_to_others"]').is(':checked') ? 1 : 0,
            targets: $('#targets').val() || [], target_modes: $('#target_modes').val() || '{}',
            co_editors: (function () { try { return window.egCoEditorsJson(); } catch (e) { return ''; } })()
        };
    }
    /* AJAX 存草稿：不重新載入頁面。存好後記下草稿編號（之後再存是更新同一份）、網址換成 ?draft=、重畫面板 */
    function saveDraft(done) {
        var d = draftPayload();
        if (!d.title && !d.content_html) { alert('標題與內容都是空的，沒有東西可存'); return; }
        $('#btnSaveDraft').prop('disabled', true);
        post(d, function (r) {
            $('#btnSaveDraft').prop('disabled', false);
            $('#draft_id').val(r.id);
            try { history.replaceState(null, '', 'createEvent.php?draft=' + r.id); } catch (e) {}
            setDirty(false);
            loadPanel();
            if (done) done(r.id); else toast('草稿已儲存');
        }, function (m) { $('#btnSaveDraft').prop('disabled', false); alert(m); });
    }
    $(document).on('click', '#btnSaveDraft', function () { saveDraft(); });

    /* 預覽列印用「目前表單上的內容」：先靜默存成草稿（同一份草稿重複更新），再預覽。
       預覽讀的是已存的內容，所以改了表格置中／寬度之後一定要先存，這顆鈕會自動幫忙存。 */
    $(document).on('click', '#btnPreviewForm', function () {
        saveDraft(function (id) { previewPrint(id); });
    });

    /* ---------- 草稿／送審／待核准 面板 ---------- */
    function renderPanel(pend) {
        var h = '', any = false;
        var toMe = pend.to_me || [], mine = pend.mine || [];
        if (toMe.length) {
            any = true; h += '<div class="nd-sec">待我核准（' + toMe.length + '）</div>';
            toMe.forEach(function (r) {
                h += '<div class="nd-row"><span class="nd-tag">' + esc(ND.kinds[r.kind] || '') + '</span><span class="nd-t">' + esc(r.title) + '</span>'
                   + '<span style="color:#8a7a68;">送審人 ' + esc(r.submitter || '') + '　' + esc(fmtD(r.submitted_at)) + '</span>'
                   + '<button type="button" class="nd-btn nd-pv" data-id="' + r.id + '"><i class="fa fa-print"></i> 預覽列印</button>'
                   + '<button type="button" class="nd-btn nd-open" data-id="' + r.id + '">檢視／核准</button></div>';
            });
        }
        var drafts = pend.drafts || [];
        if (drafts.length) {
            any = true; h += '<div class="nd-sec">我的草稿（只有自己看得到，不會通知任何人）</div>';
            drafts.forEach(function (r) {
                h += '<div class="nd-row"><span class="nd-tag">' + esc(ND.kinds[r.kind] || '') + '</span><span class="nd-t">' + esc(r.title || '（未填標題）') + '</span>'
                   + '<span style="color:#8a7a68;">' + esc(fmtD(r.updated_at)) + '</span>'
                   + '<button type="button" class="nd-btn nd-pv" data-id="' + r.id + '"><i class="fa fa-print"></i> 預覽列印</button>'
                   + '<a class="nd-btn" href="createEvent.php?draft=' + r.id + '">繼續編輯</a>'
                   + '<button type="button" class="nd-btn nd-del" data-id="' + r.id + '">刪除</button></div>';
            });
        }
        if (mine.length) {
            any = true; h += '<div class="nd-sec">我送出的（核准中／被退回）</div>';
            mine.forEach(function (r) {
                if (r.state === 'pending') {
                    h += '<div class="nd-row"><span class="nd-tag">' + esc(ND.kinds[r.kind] || '') + '</span><span class="nd-t">' + esc(r.title) + '</span><span class="nd-tag">核准中</span>'
                       + '<button type="button" class="nd-btn nd-open" data-id="' + r.id + '" data-view="1">檢視</button>'
                       + '<button type="button" class="nd-btn nd-pv" data-id="' + r.id + '"><i class="fa fa-print"></i> 預覽列印</button>'
                       + '<button type="button" class="nd-btn nd-wd" data-id="' + r.id + '">撤回修改</button></div>';
                } else {
                    h += '<div class="nd-row"><span class="nd-tag">' + esc(ND.kinds[r.kind] || '') + '</span><span class="nd-t">' + esc(r.title) + '</span><span class="nd-tag bad">已退回</span>'
                       + '<span style="color:#a33;flex-basis:100%;">原因：' + esc(r.reject_reason || '') + '</span>'
                       + '<button type="button" class="nd-btn nd-pv" data-id="' + r.id + '"><i class="fa fa-print"></i> 預覽列印</button>'
                       + '<a class="nd-btn" href="createEvent.php?draft=' + r.id + '">修改後重送</a>'
                       + '<button type="button" class="nd-btn nd-del" data-id="' + r.id + '">刪除</button></div>';
                }
            });
        }
        $('#nd-panel-body').html(h);
        $('#nd-panel').toggle(any);
    }
    function loadPanel(cb) {
        $.getJSON(API, { action: 'pending_list' }, function (r) { if (r && r.ok) { renderPanel(r); if (cb) cb(r); } });
    }
    $(function () { loadPanel(function (r) {
        if (ND.approveId) openDecide(ND.approveId, false);
    }); });
    $(document).on('click', '.nd-del', function () {
        if (!confirm('確定刪除這份草稿？')) return;
        var id = +$(this).data('id');
        post({ action: 'draft_delete', draft_id: id }, function () { location.href = 'createEvent.php'; });
    });
    $(document).on('click', '.nd-wd', function () {
        if (!confirm('撤回後這份單會回到草稿，核准人那邊的待簽通知也會關閉。確定撤回？')) return;
        post({ action: 'withdraw', draft_id: +$(this).data('id') }, function () { location.href = 'createEvent.php'; });
    });

    /* ---------- 核准／退回 ---------- */
    var curId = 0;
    function openDecide(id, viewOnly) {
        curId = id;
        $('#ndDecMsg').text(''); $('#ndDecNote').val(''); $('#ndDecDate').val(today()).attr('max', today());
        $('#ndDecPrev').html('<i class="fa fa-spinner fa-spin"></i> 載入中…');
        $.getJSON(API, { action: 'pending_view', id: id }, function (r) {
            if (!r || !r.ok) { $('#ndDecPrev').text((r && r.error) || '讀取失敗'); return; }
            $('#ndDecTitle').text(r.kind + '：' + r.title);
            $('#ndDecMeta').text('送審人：' + r.submitter + '　發布日期：' + fmtD(r.eventdate) + (r.post_until ? '　張貼期限：' + fmtD(r.post_until) : ''));
            $('#ndDecPrev').html(r.html || '<span style="color:#999;">（沒有內容）</span>');
            var can = !viewOnly && r.state === 'pending';
            $('#ndDecAct,#ndDecApprove,#ndDecReject').toggle(can);
            $('#ndDecideModal').modal('show');
        }).fail(function () { $('#ndDecPrev').text('讀取失敗'); $('#ndDecideModal').modal('show'); });
    }
    $(document).on('click', '.nd-open', function () { openDecide(+$(this).data('id'), !!$(this).data('view')); });
    function decide(dec) {
        var note = ($('#ndDecNote').val() || '').trim(), date = $('#ndDecDate').val() || '';
        if (dec === 'reject' && !note) { $('#ndDecMsg').text('退回必須填寫原因'); $('#ndDecNote').focus(); return; }
        if (dec === 'approve') {
            if (!date) { $('#ndDecMsg').text('請選擇核准日期'); return; }
            if (date > today()) { $('#ndDecMsg').text('核准日期不可以是未來日期'); return; }
            if (!confirm('確定核准？核准後會立即發布給所有對象。')) return;
        }
        $('#ndDecApprove,#ndDecReject').prop('disabled', true);
        post({ action: 'decide', draft_id: curId, decision: dec, note: note, date: date },
            function () { location.href = 'createEvent.php'; },
            function (m) { $('#ndDecMsg').text(m); $('#ndDecApprove,#ndDecReject').prop('disabled', false); });
    }
    $(document).on('click', '#ndDecApprove', function () { decide('approve'); });
    $(document).on('click', '#ndDecReject', function () { decide('reject'); });

    function previewPrint(id) {
        // 列印視窗必須在使用者點擊當下開啟，所以先取資料、拿到後立刻開（資料很小，不會被彈出視窗封鎖擋掉時請允許彈出視窗）
        $.getJSON(API, { action: 'pending_view', id: id, print: 1 }, function (r) {
            if (r && r.ok && window.ncPreviewPrint) window.ncPreviewPrint(r.data); else alert((r && r.error) || '預覽失敗');
        }).fail(function () { alert('預覽失敗'); });
    }
    $(document).on('click', '.nd-pv', function () { previewPrint(+$(this).data('id')); });
    $(document).on('click', '.nd-pv-cur', function () { previewPrint(curId); });

    /* ---------- 核准設定（超級管理員） ---------- */
    $(document).on('click', '#nd-appr-btn', function () {
        $('.nd-mode').each(function () { $(this).val(ND.modes[$(this).data('kind')] || 'auto'); });
        $('#ndApprUser').val(ND.apprUser || '');
        $('#ndApprMsg').text('');
        $('#ndApprModal').modal('show');
    });
    $(document).on('input', '#ndApprKw', function () {
        var terms = String($(this).val() || '').toLowerCase().split(/\s+/).filter(Boolean), keep = $('#ndApprUser').val();
        $('#ndApprUser option').each(function () {
            var t = ($(this).text() || '').toLowerCase();
            var hit = !terms.length || terms.every(function (k) { return t.indexOf(k) >= 0; });
            var show = hit || this.value === keep || this.value === '';
            $(this).prop('hidden', !show).toggle(show);
        });
    });
    $(document).on('click', '#ndApprSave', function () {
        var d = { action: 'appr_settings_save', approver_user_id: $('#ndApprUser').val() || '' };
        $('.nd-mode').each(function () { d['mode_' + $(this).data('kind')] = $(this).val(); });
        post(d, function (r) {
            $('#ndApprMsg').css('color', '#2e7d32').text('已儲存');
            setTimeout(function () { location.reload(); }, 600);
        }, function (m) { $('#ndApprMsg').css('color', '#c0392b').text(m); });
    });
})();
</script>
