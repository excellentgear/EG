<?php
// 手機 / 平板專用：公告通知一頁式頁面（RWD）
// 功能：瀏覽與我相關的公告、開啟看完整內容＋附件、回簽 / 回覆（可上傳附件）。
// 進入方式：點手機推播通知（url 帶 ?event=公告ID 會自動開啟該筆）。
session_start();
if (!isset($_SESSION['id'])) {
    // 未登入：導去登入頁，登入後自動返回本頁（保留 event 參數）
    $ev = isset($_GET['event']) ? ('?event=' . (int)$_GET['event']) : '';
    $_SESSION['lastpage'] = '/EGsystem/views/liveEvent/mobile.php' . $ev;
    header('Location: /EGsystem/index.php');
    exit();
}
$myName = $_SESSION['user_cname'] ?? '';
$openEvent = isset($_GET['event']) ? (int)$_GET['event'] : 0;
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#2A3F54">
    <title>超正齒輪 公告 / 通知</title>
    <link rel="manifest" href="../../manifest.json">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <style>
        :root{ --accent:#1ABB9C; --accent-d:#169a80; --dark:#2A3F54; --line:#e6ecf1; --text:#34495e; --muted:#8a9bab; --bg:#f4f7f9; }
        *{ box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
        html,body{ margin:0; padding:0; background:var(--bg); color:var(--text); font-family:"Microsoft JhengHei","Helvetica Neue",Arial,sans-serif; font-size:15px; }
        a{ color:var(--accent-d); }
        /* 頂列 */
        .m-top{ position:sticky; top:0; z-index:20; background:var(--dark); color:#fff; display:flex; align-items:center; gap:10px; padding:calc(env(safe-area-inset-top) + 12px) 14px 12px; }
        .m-top h1{ font-size:17px; margin:0; font-weight:700; flex:1; display:flex; align-items:center; gap:8px; }
        .m-top .who{ font-size:12px; opacity:.85; font-weight:400; }
        .m-top button{ border:none; background:rgba(255,255,255,.15); color:#fff; border-radius:20px; padding:7px 12px; font-size:13px; font-weight:600; }
        .m-back{ border:none; background:transparent; color:#fff; font-size:20px; padding:4px 6px; }
        /* 清單 */
        .m-list{ padding:12px; }
        .m-card{ background:#fff; border:1px solid var(--line); border-radius:12px; padding:13px 14px; margin-bottom:11px; box-shadow:0 1px 4px rgba(42,63,84,.06); }
        .m-card:active{ background:#fafcfd; }
        .m-card .row1{ display:flex; align-items:center; gap:8px; margin-bottom:6px; flex-wrap:wrap; }
        .m-badge{ font-size:11px; font-weight:700; padding:2px 9px; border-radius:20px; white-space:nowrap; }
        .b-todo{ background:#fdecea; color:#e74c3c; }
        .b-done{ background:rgba(26,187,156,.13); color:var(--accent-d); }
        .b-mode-sign{ background:#fff3df; color:#c77c1a; }
        .b-mode-reply{ background:#f0eafc; color:#7a4fc0; }
        .b-mode-read{ background:#eef2f5; color:#5a6b7b; }
        .m-src{ font-size:11px; color:#5a6b7b; background:#f0f4f7; border-radius:5px; padding:1px 7px; }
        .m-title{ font-size:15.5px; font-weight:700; color:var(--dark); margin:2px 0 4px; }
        .m-snippet{ font-size:13px; color:var(--muted); line-height:1.5; display:-webkit-box; -webkit-line-clamp:2; line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
        .m-meta{ font-size:11.5px; color:var(--muted); margin-top:7px; }
        .m-more{ display:block; width:100%; border:1px solid var(--line); background:#fff; color:var(--text); border-radius:10px; padding:11px; font-size:14px; font-weight:600; margin-top:4px; }
        .m-empty,.m-loading{ text-align:center; color:var(--muted); padding:50px 20px; }
        .m-empty .fa,.m-loading .fa{ font-size:34px; display:block; margin-bottom:10px; color:#cdd8e0; }
        /* 詳情 */
        #detail{ display:none; }
        .m-detail{ padding:14px; }
        .d-title{ font-size:19px; font-weight:800; color:var(--dark); margin:2px 0 8px; line-height:1.35; }
        .d-meta{ font-size:12.5px; color:var(--muted); line-height:1.8; margin-bottom:12px; }
        .d-sec{ background:#fff; border:1px solid var(--line); border-radius:12px; padding:14px; margin-bottom:12px; }
        .d-sec h3{ font-size:13px; color:var(--muted); margin:0 0 9px; font-weight:700; display:flex; align-items:center; gap:6px; }
        .d-content{ font-size:15px; line-height:1.75; white-space:pre-line; word-break:break-word; color:var(--text); }
        .d-att{ display:flex; flex-direction:column; gap:9px; }
        .d-att img{ max-width:100%; border-radius:8px; border:1px solid var(--line); display:block; }
        .d-file{ display:flex; align-items:center; gap:8px; text-decoration:none; background:#f0f4f7; border:1px solid var(--line); border-radius:8px; padding:10px 12px; font-size:14px; color:var(--accent-d); }
        .d-file .fa{ font-size:16px; }
        .modebadge{ display:inline-block; font-size:12.5px; font-weight:700; padding:4px 12px; border-radius:20px; margin-bottom:10px; }
        .mstatus{ font-size:13px; color:var(--text); line-height:1.9; margin-bottom:10px; }
        .mstatus .fa{ width:16px; color:var(--accent-d); }
        .todo{ color:#e74c3c; font-weight:600; }
        .doneok{ color:var(--accent-d); font-weight:700; font-size:15px; }
        .expired{ color:#e67e22; font-weight:600; margin-bottom:8px; }
        .lbl{ font-size:13px; font-weight:600; display:block; margin:10px 0 6px; }
        textarea.m-ta{ width:100%; min-height:100px; border:1px solid var(--line); border-radius:10px; padding:11px; font-size:15px; font-family:inherit; }
        textarea.m-ta:focus{ border-color:var(--accent); outline:none; }
        input[type=file].m-file{ width:100%; font-size:13px; padding:8px 0; }
        .m-btn{ display:block; width:100%; border:none; border-radius:11px; padding:14px; font-size:16px; font-weight:700; margin-top:12px; }
        .m-btn-primary{ background:var(--accent); color:#fff; }
        .m-btn-primary:disabled{ opacity:.6; }
        .replyshow{ background:#f8fbfa; border:1px solid var(--line); border-radius:10px; padding:11px; font-size:14px; line-height:1.7; white-space:pre-line; }
        .others{ margin-top:6px; }
        .other{ border-top:1px dashed var(--line); padding:8px 0; font-size:13px; }
        .other .nm{ font-weight:600; color:var(--dark); }
        .other .tg{ float:right; font-size:11.5px; color:var(--muted); }
        .toast{ position:fixed; left:50%; bottom:30px; transform:translateX(-50%); background:rgba(0,0,0,.85); color:#fff; padding:11px 20px; border-radius:24px; font-size:14px; z-index:100; display:none; }
    </style>
</head>
<body>
    <div class="m-top">
        <button class="m-back" id="btn-back" style="display:none;"><i class="fa fa-chevron-left"></i></button>
        <h1><i class="fa fa-bullhorn"></i> <span id="top-title">超正齒輪 公告 / 通知</span></h1>
        <span class="who"><?= htmlspecialchars($myName) ?></span>
        <button id="btn-push" title="開啟推播通知"><i class="fa fa-bell-o"></i></button>
    </div>

    <!-- 清單 -->
    <div id="list">
        <div class="m-list" id="list-body"></div>
        <div class="m-loading" id="list-msg"><i class="fa fa-spinner fa-spin"></i></div>
    </div>

    <!-- 詳情 -->
    <div id="detail"><div class="m-detail" id="detail-body"></div></div>

    <div class="toast" id="toast"></div>

    <script src="../../resource/js/jquery.min.js"></script>
    <script>window.EG_PUSH_BASE = '../../';</script>
    <script src="../../resource/js/push-client.js"></script>
    <script>
    $(function () {
        var API_LIST = '../../src/store/_myNotices.php';
        var API_DETAIL = '../../src/store/_eventDetail.php';
        var API_RESPOND = '../../src/store/_eventRespond.php';
        function fileUrl(t, id, dl){ return '../../src/store/_eventFile.php?t=' + t + '&id=' + id + (dl ? '&dl=1' : ''); }
        var esc = function (s) { return $('<i>').text(s == null ? '' : s).html(); };
        var modeName = { read:'確認已閱', sign:'回簽', reply:'回覆 + 回簽' };
        var modeBadgeCls = { read:'b-mode-read', sign:'b-mode-sign', reply:'b-mode-reply' };
        var st = { page:1, size:20, pages:1, curId:0 };

        // 關閉 GET 快取：iOS 加入主畫面的 App(WKWebView) 會快取 GET，
        // 導致回覆/回簽後重新抓詳情仍拿到舊狀態（需重開 App 才更新）。加時間戳避免。
        $.ajaxSetup({ cache: false });

        function toast(msg){ var $t=$('#toast').text(msg).fadeIn(120); setTimeout(function(){ $t.fadeOut(300); }, 2200); }

        /* ---------- 清單 ---------- */
        function loadList(page){
            st.page = page || 1;
            if (st.page === 1) $('#list-body').empty();
            $('#list-msg').html('<i class="fa fa-spinner fa-spin"></i>').show();
            $.get(API_LIST, { page: st.page, size: st.size }, function (res) {
                if (!res || !res.ok) { $('#list-msg').text(res && res.msg ? res.msg : '載入失敗'); return; }
                st.pages = res.pages;
                if (res.total === 0) { $('#list-msg').html('<i class="fa fa-inbox"></i> 目前沒有與您相關的公告'); return; }
                $('#list-msg').hide();
                var h = '';
                res.rows.forEach(function (r) {
                    var stateBadge = r.done
                        ? '<span class="m-badge b-done"><i class="fa fa-check"></i> 已完成</span>'
                        : '<span class="m-badge b-todo">待處理</span>';
                    var modeBadge = '<span class="m-badge ' + modeBadgeCls[r.mode] + '">' + modeName[r.mode] + '</span>';
                    h += '<div class="m-card" data-id="' + r.id + '" data-reftype="' + esc(r.ref_type || '') + '" data-refid="' + (r.ref_id || 0) + '">'
                       + '<div class="row1">' + stateBadge + modeBadge + (r.source ? '<span class="m-src">' + esc(r.source) + '</span>' : '') + '</div>'
                       + '<div class="m-title">' + esc(r.title) + '</div>'
                       + '<div class="m-snippet">' + esc(r.snippet) + '</div>'
                       + '<div class="m-meta">' + esc(r.eventdate) + (r.creator ? '　' + esc(r.creator) : '')
                       + (r.reply_deadline ? '　回覆期限：' + esc(r.reply_deadline) + (r.expired ? '（已過期）' : '') : '') + '</div>'
                       + '</div>';
                });
                $('#list-body').append(h);
                if (st.page < st.pages) $('#list-msg').html('<button class="m-more" id="btn-more">載入更多（' + st.page + ' / ' + st.pages + '）</button>').show();
                else $('#list-msg').hide();
            }, 'json').fail(function () { $('#list-msg').text('連線失敗'); });
        }
        $(document).on('click', '#btn-more', function(){ loadList(st.page + 1); });
        $(document).on('click', '.m-card', function(){
            var rt = $(this).data('reftype');
            // 品質異常單通知 → 開異常單檢視頁（含異常單資訊與回覆回簽）
            if (rt === 'QA'){ location.href = '../QA/qa_abnormal_view.php?event=' + $(this).data('id'); return; }
            // 異常矯正處理單 → 開單據頁並自動彈出該單填寫（當事人無檢閱權限也可開，open_id=ref_id=car_id）
            if (rt === 'CAR'){ location.href = '../QA/correction_order.php?open_id=' + $(this).data('refid'); return; }
            if (rt === 'DWG'){ location.href = '../QC/drawing_change_log.php?ack=' + $(this).data('refid'); return; }
            // 處置判定/最終裁決 → 決策畫面（判定完成前不標已讀，任一決策者判定後全員自動消失）
            if (rt === 'QA_DECIDE' || rt === 'QA_DECIDE_F'){ location.href = '../QC/inspection_combined_prototype.php?decide_abnormal=' + $(this).data('refid'); return; }
            // 異常單修改請求（主管）→ 核准畫面；已開放修改（請求者）→ 修改畫面
            if (rt === 'QA_EDIT_REQ'){ $.post('../../src/store/_markEventRead.php', { eventid: $(this).data('id') }); location.href = '../QC/inspection_combined_prototype.php?edit_request=' + $(this).data('refid'); return; }
            if (rt === 'QA_EDIT_OK'){ $.post('../../src/store/_markEventRead.php', { eventid: $(this).data('id') }); location.href = '../QC/inspection_combined_prototype.php?edit_abnormal=' + $(this).data('refid'); return; }
            // 報價單待簽核／簽核結果 → 專屬審核頁（左右分欄RWD，手機自動改上下堆疊）
            if (rt === 'QUOTATION_APPROVAL' || rt === 'QUOTATION_APPROVAL_RESULT'){ location.href = '../Sales/quotation_approval_view.php?event=' + $(this).data('id'); return; }
            // 報價單補件待審／補件結果 → 補件專屬審核頁
            // 年度教育訓練計畫表待審核／結果 → 專屬審核頁（可直接核准/退回，見 ai-rules/17）
            if (rt === 'TRAINING_PLAN_APPROVAL' || rt === 'TRAINING_PLAN_RESULT'){ location.href = '../ADM/training_plan_approval_view.php?event=' + $(this).data('id'); return; }
            // AS 文件結構總覽（文件管制總覽表）待核准／結果 → 專屬審核頁
            if (rt === 'AS_TREE_APPROVAL' || rt === 'AS_TREE_RESULT'){ location.href = '../ADM/as_tree_approval_view.php?event=' + $(this).data('id'); return; }
            if (rt === 'QUOTATION_SUPP' || rt === 'QUOTATION_SUPP_RESULT'){ location.href = '../Sales/quotation_supplement_view.php?event=' + $(this).data('id'); return; }
            openDetail($(this).data('id'));
        });

        /* ---------- 詳情 ---------- */
        function showList(){ $('#detail').hide(); $('#list').show(); $('#btn-back').hide(); $('#top-title').text('超正齒輪 公告 / 通知'); if (history.state && history.state.d) history.back(); }
        function openDetail(id){
            st.curId = id;
            $('#list').hide(); $('#detail').show(); $('#btn-back').show();
            $('#top-title').text('超正齒輪 公告內容');
            $('#detail-body').html('<div class="m-loading"><i class="fa fa-spinner fa-spin"></i></div>');
            window.scrollTo(0,0);
            try { history.pushState({ d: id }, ''); } catch(e){}
            $.get(API_DETAIL, { eventid: id }, renderDetail, 'json').fail(function(){ $('#detail-body').html('<div class="m-empty">載入失敗</div>'); });
        }
        $('#btn-back').on('click', showList);
        window.addEventListener('popstate', function(){ if ($('#detail').is(':visible')) { $('#detail').hide(); $('#list').show(); $('#btn-back').hide(); $('#top-title').text('公告 / 通知'); } });

        function renderDetail(res){
            if (!res || !res.ok){ $('#detail-body').html('<div class="m-empty">' + (res && res.msg ? esc(res.msg) : '載入失敗') + '</div>'); return; }
            var e = res.event, h = '';
            // 品質異常單通知（如舊推播連到本頁）→ 導向異常單檢視頁；修改請求/開放修改 → 品管合併檢驗頁
            if (e.ref_type === 'QA'){ location.href = '../QA/qa_abnormal_view.php?event=' + e.id; return; }
            if (e.ref_type === 'CAR'){ location.href = '../QA/correction_order.php?open_id=' + (e.ref_id || 0); return; }
            if (e.ref_type === 'DWG'){ location.href = '../QC/drawing_change_log.php?ack=' + (e.ref_id || 0); return; }
            if (e.ref_type === 'QA_DECIDE' || e.ref_type === 'QA_DECIDE_F'){ location.href = '../QC/inspection_combined_prototype.php?decide_abnormal=' + (e.ref_id || 0); return; }
            if (e.ref_type === 'QA_EDIT_REQ'){ location.href = '../QC/inspection_combined_prototype.php?edit_request=' + (e.ref_id || 0); return; }
            if (e.ref_type === 'QA_EDIT_OK'){ location.href = '../QC/inspection_combined_prototype.php?edit_abnormal=' + (e.ref_id || 0); return; }
            if (e.ref_type === 'QUOTATION_APPROVAL' || e.ref_type === 'QUOTATION_APPROVAL_RESULT'){ location.href = '../Sales/quotation_approval_view.php?event=' + e.id; return; }
            if (e.ref_type === 'TRAINING_PLAN_APPROVAL' || e.ref_type === 'TRAINING_PLAN_RESULT'){ location.href = '../ADM/training_plan_approval_view.php?event=' + e.id; return; }
            if (e.ref_type === 'AS_TREE_APPROVAL' || e.ref_type === 'AS_TREE_RESULT'){ location.href = '../ADM/as_tree_approval_view.php?event=' + e.id; return; }
            if (e.ref_type === 'QUOTATION_SUPP' || e.ref_type === 'QUOTATION_SUPP_RESULT'){ location.href = '../Sales/quotation_supplement_view.php?event=' + e.id; return; }
            h += '<div class="d-title">' + esc(e.title) + '</div>';
            var meta = [];
            if (e.source) meta.push('來源：' + esc(e.source));
            if (e.creator) meta.push('公告者：' + esc(e.creator));
            meta.push('發布：' + esc(e.eventdate) + (e.enddate ? ' ~ ' + esc(e.enddate) : ''));
            if (e.reply_deadline) meta.push('回覆期限：' + esc(e.reply_deadline));
            h += '<div class="d-meta">' + meta.join('<br>') + '</div>';

            // 內容
            h += '<div class="d-sec"><h3><i class="fa fa-file-text-o"></i> 內容</h3><div class="d-content">' + esc(e.content) + '</div></div>';

            // 附件：檢視一律用「角落標註快取版」(t=p，標籤與備注已印在檔上；無快取版時後端自動退回原檔)
            // 並在清單顯示 標籤＋備注摘要（規格 四之四 4G 檢視版面）
            if (res.attachments && res.attachments.length){
                var af = '';
                res.attachments.forEach(function(f){
                    var ext = (f.ext || '').toLowerCase();
                    var meta = '';
                    if (f.tag) meta += '<span style="background:#e2eefe;color:#1e508c;border:1px solid #a0c3eb;border-radius:9px;padding:0 8px;font-size:11px;margin-right:6px;white-space:nowrap;">' + esc(f.tag) + '</span>';
                    if (f.description) meta += '<span style="color:var(--muted);font-size:12px;">' + esc(String(f.description).substring(0, 40)) + '</span>';
                    if (['jpg','jpeg','png','gif','webp','bmp'].indexOf(ext) !== -1){
                        af += '<div style="margin-bottom:8px;">' + (meta ? '<div style="margin:2px 0 4px;">' + meta + '</div>' : '')
                            + '<a href="' + fileUrl('p', f.id) + '" target="_blank"><img src="' + fileUrl('p', f.id) + '" alt="' + esc(f.name) + '"></a></div>';
                    } else if (f.previewable){
                        af += '<div style="margin-bottom:6px;"><a class="d-file" href="' + fileUrl('p', f.id) + '" target="_blank"><i class="fa fa-file-pdf-o"></i> ' + esc(f.name) + '</a>'
                            + (meta ? '<div style="margin:3px 0 0 4px;">' + meta + '</div>' : '') + '</div>';
                    } else {
                        af += '<div style="margin-bottom:6px;"><a class="d-file" href="' + fileUrl('e', f.id, 1) + '"><i class="fa fa-paperclip"></i> ' + esc(f.name) + '</a>'
                            + (meta ? '<div style="margin:3px 0 0 4px;">' + meta + '</div>' : '') + '</div>';
                    }
                });
                h += '<div class="d-sec"><h3><i class="fa fa-paperclip"></i> 公告附件</h3><div class="d-att">' + af + '</div></div>';
            }

            // 我的處理 / 動作
            h += '<div class="d-sec">' + buildAction(res) + '</div>';

            // 其他對象（可互看時）
            if (res.show_status && res.others && res.others.length){
                var oh = '';
                res.others.forEach(function(o){
                    var tag = o.replied_at ? '已回覆' : (o.signed_at ? '已回簽' : (o.read_at ? '已閱' : '—'));
                    oh += '<div class="other"><span class="tg">' + tag + '</span><span class="nm">' + esc(o.name) + '</span>';
                    if (o.reply_content) oh += '<div style="color:var(--muted);margin-top:3px;">' + esc(o.reply_content) + '</div>';
                    // 他人回覆附件：只有最高管理者可刪
                    if (o.files && o.files.length) o.files.forEach(function(f){
                        oh += '<span style="display:inline-flex;align-items:center;margin-top:5px;"><a class="d-file" href="' + fileUrl('r', f.id, 1) + '"><i class="fa fa-paperclip"></i> ' + esc(f.file_name) + '</a>'
                            + (res.is_admin ? '<button type="button" class="rf-del" data-id="' + f.id + '" title="刪除附件（管理者）" style="background:none;border:none;color:#e74c3c;cursor:pointer;padding:2px 6px;font-size:14px;"><i class="fa fa-trash-o"></i></button>' : '')
                            + '</span>';
                    });
                    oh += '</div>';
                });
                h += '<div class="d-sec"><h3><i class="fa fa-users"></i> 其他對象狀態</h3><div class="others">' + oh + '</div></div>';
            }

            $('#detail-body').html(h);
        }

        function buildAction(res){
            var mode = res.my_mode, s = res.my_status, h = '';
            var mbcls = { read:'b-mode-read', sign:'b-mode-sign', reply:'b-mode-reply' };
            h += '<span class="modebadge ' + mbcls[mode] + '">需求：' + modeName[mode] + '</span>';
            // 目前狀態
            var line = '';
            if (s){
                if (s.read_at)    line += '<div><i class="fa fa-eye"></i> 已閱：' + esc(s.read_at) + '</div>';
                if (s.signed_at)  line += '<div><i class="fa fa-pencil-square-o"></i> 已回簽：' + esc(s.signed_at) + '</div>';
                if (s.replied_at) line += '<div><i class="fa fa-comments-o"></i> 已回覆：' + esc(s.replied_at) + '</div>';
            }
            if (!line) line = '<span class="todo">尚未處理</span>';
            h += '<div class="mstatus">' + line + '</div>';

            var done = s && ((mode==='read' && s.read_at) || (mode==='sign' && s.signed_at) || (mode==='reply' && s.replied_at));

            if (res.deadline_passed && (mode==='sign' || mode==='reply')){
                h += '<div class="expired"><i class="fa fa-clock-o"></i> 已超過回覆 / 回簽期限</div>';
                if (!(s && s.read_at)) h += '<button class="m-btn m-btn-primary act" data-act="read">確認已閱</button>';
            } else if (mode === 'read'){
                if (!done) h += '<button class="m-btn m-btn-primary act" data-act="read"><i class="fa fa-check"></i> 確認已閱</button>';
                else h += '<div class="doneok"><i class="fa fa-check-circle"></i> 已完成</div>';
            } else if (mode === 'sign'){
                if (!done) h += '<button class="m-btn m-btn-primary act" data-act="sign"><i class="fa fa-pencil-square-o"></i> 回簽</button>';
                else h += '<div class="doneok"><i class="fa fa-check-circle"></i> 已回簽</div>';
            } else { // reply
                if (done){
                    h += '<div class="doneok"><i class="fa fa-check-circle"></i> 已回覆</div>';
                    if (s.reply_content) h += '<div class="lbl">我的回覆：</div><div class="replyshow">' + esc(s.reply_content) + '</div>';
                    // 我的回覆附件：本人可刪（期限內；管理者不受限，由後端把關）
                    if (s.files && s.files.length) s.files.forEach(function(f){
                        h += '<span style="display:inline-flex;align-items:center;margin-top:6px;"><a class="d-file" href="' + fileUrl('r', f.id, 1) + '"><i class="fa fa-paperclip"></i> ' + esc(f.name) + '</a>'
                           + (res.can_del_my ? '<button type="button" class="rf-del" data-id="' + f.id + '" title="刪除此附件" style="background:none;border:none;color:#e74c3c;cursor:pointer;padding:2px 6px;font-size:14px;"><i class="fa fa-trash-o"></i></button>' : '')
                           + '</span>';
                    });
                    // 期限內可修改回覆內容 / 補傳附件（原附件保留、新附件為追加；後端本就支援覆寫）
                    h += '<button class="m-btn" type="button" id="btn-edit-reply" style="margin-top:10px;"><i class="fa fa-pencil"></i> 修改回覆 / 補傳附件</button>';
                    h += '<div id="reply-edit-wrap" style="display:none;margin-top:8px;">';
                    h += '<label class="lbl">回覆內容 <span style="color:#e74c3c;">*</span>（將更新原回覆）</label>';
                    h += '<textarea id="reply-text" class="m-ta">' + esc(s.reply_content || '') + '</textarea>';
                    h += '<label class="lbl">補傳附件 <span style="color:var(--muted);font-weight:400;">可多檔；原附件保留，新附件為追加</span></label>';
                    h += '<input type="file" id="reply-files" class="m-file" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.7z">';
                    h += '<button class="m-btn m-btn-primary act" data-act="reply"><i class="fa fa-paper-plane"></i> 更新回覆</button>';
                    h += '</div>';
                } else {
                    h += '<label class="lbl">回覆內容 <span style="color:#e74c3c;">*</span></label>';
                    h += '<textarea id="reply-text" class="m-ta" placeholder="請輸入回覆…"></textarea>';
                    h += '<label class="lbl">附件 <span style="color:var(--muted);font-weight:400;">可多檔，單檔≤50MB</span></label>';
                    h += '<input type="file" id="reply-files" class="m-file" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.7z">';
                    h += '<button class="m-btn m-btn-primary act" data-act="reply"><i class="fa fa-paper-plane"></i> 送出回覆</button>';
                }
            }
            return h;
        }

        // 修改回覆 / 補傳附件：展開編輯表單
        $(document).on('click', '#btn-edit-reply', function(){
            $('#reply-edit-wrap').slideToggle(120);
        });

        // 刪除回覆附件（本人或管理者；權限由後端 _respFileDelete.php 把關）
        $(document).on('click', '.rf-del', function(){
            var $b = $(this), id = $b.data('id');
            var name = ($b.prev('a.d-file').text() || '').trim();
            if (!confirm('確定刪除附件「' + name + '」？刪除後無法復原。')) return;
            $b.prop('disabled', true);
            $.post('../../src/store/_respFileDelete.php', { id: id }, function(res){
                if (res && res.ok){ toast('附件已刪除'); $.get(API_DETAIL, { eventid: st.curId }, renderDetail, 'json'); }
                else { $b.prop('disabled', false); toast(res && res.msg ? res.msg : '刪除失敗'); }
            }, 'json').fail(function(){ $b.prop('disabled', false); toast('連線失敗'); });
        });

        // 送出回應
        $(document).on('click', '.act', function(){
            var act = $(this).data('act'), $btn = $(this);
            var fd = new FormData();
            fd.append('eventid', st.curId);
            fd.append('action', act);
            if (act === 'reply'){
                var txt = ($('#reply-text').val() || '').trim();
                if (!txt){ toast('請輸入回覆內容'); return; }
                fd.append('reply_content', txt);
                var fi = $('#reply-files')[0];
                if (fi && fi.files) for (var i=0;i<fi.files.length;i++) fd.append('reply_files[]', fi.files[i]);
            }
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 處理中…');
            $.ajax({ url: API_RESPOND, type:'POST', data: fd, processData:false, contentType:false, dataType:'json',
                success: function(res){
                    if (res && res.ok){
                        toast('已送出');
                        // 重抓詳情顯示「已完成」狀態，並同步刷新清單卡片的待處理/已完成徽章
                        $.get(API_DETAIL, { eventid: st.curId }, renderDetail, 'json');
                        loadList(1);
                    }
                    else { $btn.prop('disabled', false).text('重試'); toast(res && res.msg ? res.msg : '送出失敗'); }
                },
                error: function(){ $btn.prop('disabled', false).text('重試'); toast('連線失敗'); }
            });
        });

        /* ---------- 開啟通知按鈕 ---------- */
        function refreshPush(){
            if (!window.EGPush) return;
            var s = EGPush.status();
            var $b = $('#btn-push');
            if (s === 'granted') $b.html('<i class="fa fa-bell"></i>').attr('title','通知已開啟');
            else if (s === 'unsupported') $b.hide();
            else $b.html('<i class="fa fa-bell-o"></i>');
        }
        $('#btn-push').on('click', function(){
            if (!window.EGPush) return;
            var s = EGPush.status();
            if (s === 'granted'){ toast('通知已開啟'); return; } // 不提供「關閉此裝置推播」功能
            EGPush.enable().then(function(ok){ refreshPush(); if (ok) toast('通知已開啟'); });
        });
        setTimeout(refreshPush, 600);

        /* ---------- 啟動 ---------- */
        loadList(1);
        var openEvent = <?= (int)$openEvent ?>;
        if (openEvent > 0) openDetail(openEvent);
    });
    </script>
</body>
</html>
