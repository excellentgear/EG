<?php
/**
 * 工程處理紀錄（eng_log）
 * -----------------------------------------------------------------------------
 * 把發包／批圖過程中「問了誰、對方怎麼回、最後怎麼處理」留下來，並且能用
 * 客戶／料號／廠商三個角度查回來。無簽核、低門檻；正式單據仍在各自模組開。
 *
 * 最小單位是「問題項」不是對話串：一次批圖常有十幾二十條問題，客戶不會一次回完、
 * 也不會照順序回，所以每一條各自有對象、狀態、回覆與附件。
 *
 * 資料一律走 src/store/EngLog_API.php；共用邏輯 src/common/eng_log_lib.php。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/TD/eng_log.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/eng_log_lib.php';

$db = (new DBConnection())->getPDO();
el_ensure_schema($db);
$elUser = el_current_user($db);
$P = el_perms($db, $elUser);

/* 角色說明一律即時查現況組出來（鐵律4）：管理員把角色改名或刪掉之後，
   寫死的說明文字會繼續顯示舊內容而且不會報錯。 */
$roleRows = [];
try {
    $roleRows = $db->query("SELECT role_id, role_code, role_name, note, is_system FROM roles
                             WHERE module='eng_log' OR (role_code='admin' AND is_system=1)
                             ORDER BY is_system DESC, role_id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
$myRoleNames = [];
try {
    $st = $db->prepare("SELECT r.role_name FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                        WHERE ur.user_id=? AND (r.module='eng_log' OR (r.role_code='admin' AND r.is_system=1))");
    $st->execute([(int)$P['uid']]);
    $myRoleNames = $st->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}
$roleLabel = $myRoleNames ? implode('、', $myRoleNames)
           : ($P['isAdmin'] ? '管理者' : ($P['canView'] ? '（無本模組角色，權限來自其他來源）' : '無角色'));
$openId = (int)($_GET['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>工程處理紀錄</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        /* 版面語言與 views/user/personal_task.php 一致：F4F7FC 底、白色圓角卡片、
           深藍→綠松漸層標頭。狀態／綁定徽章仍走暖色系（ai-rules/10），
           personal_task 本身就是這樣混用的（外殼冷色、資料語意暖色）。 */

        /* 數字輸入框無上下增減按鈕（UI規範） */
        input[type=number]::-webkit-inner-spin-button,
        input[type=number]::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }
        input[type=number] { -moz-appearance:textfield; appearance:textfield; }

        :root {
            --primary-color:#2A3F54;
            --accent-color:#1ABB9C;
            --bg-color:#F4F7FC;
            --card-bg:#FFFFFF;
            --text-color:#495057;
        }
        body { background-color:var(--bg-color); font-family:"Segoe UI","Roboto","Helvetica Neue",Arial,sans-serif; color:var(--text-color); }
        .right_col { background-color:var(--bg-color) !important; }

        /* 側欄預設隱藏、由下方 JS 恢復——只抄 CSS 不抄 JS 側欄會整片消失（鐵律6） */
        #sidebar-menu { visibility:hidden; }
        .right_col .page-title { margin:8px 0 10px; overflow:hidden; }
        .page-help-btn { height:30px; font-size:13px; padding:0 14px; border:1px solid #E8EEF5; border-radius:15px;
            background:#fff; color:#2A3F54; cursor:pointer; box-shadow:0 1px 3px rgba(0,0,0,.06); }
        .page-help-btn:hover { background:#F4F7FC; border-color:#1ABB9C; color:#0e8c73; }
        @media print { .page-help-btn, .filter-bar, .stats-container { display:none !important; } }

        /* ── 上方統計卡＝快捷篩選 ─────────────────────────────── */
        .stats-container { display:flex; gap:15px; margin-bottom:15px; flex-wrap:wrap; }
        .stat-card { flex:1; min-width:140px; background:var(--card-bg); border-radius:8px; padding:13px 15px;
            box-shadow:0 2px 5px rgba(0,0,0,.05); border-left:4px solid transparent; position:relative;
            overflow:hidden; cursor:pointer; transition:transform .1s, box-shadow .1s; }
        .stat-card:hover { transform:translateY(-2px); box-shadow:0 5px 15px rgba(0,0,0,.1); }
        .stat-card.active { background:#fff; transform:scale(1.02); z-index:1; }
        .stat-card .stat-icon { position:absolute; right:14px; top:14px; font-size:30px; opacity:.1; }
        .stat-card .stat-value { font-size:24px; font-weight:800; color:var(--primary-color); }
        .stat-card .stat-label { font-size:12px; color:#888; font-weight:600; letter-spacing:1px; }
        .stat-card.c-open     { border-left-color:#5B8DEF; }
        .stat-card.c-open.active     { box-shadow:0 0 0 3px #5B8DEF; }
        .stat-card.c-wait     { border-left-color:#F39C12; }
        .stat-card.c-wait.active     { box-shadow:0 0 0 3px #F39C12; }
        .stat-card.c-over     { border-left-color:#E74C3C; background:#fff5f5; }
        .stat-card.c-over .stat-value { color:#C0392B; }
        .stat-card.c-over.active     { box-shadow:0 0 0 3px #E74C3C; }
        .stat-card.c-week     { border-left-color:#C08A52; }
        .stat-card.c-week .stat-value { color:#B5732A; }
        .stat-card.c-week.active     { box-shadow:0 0 0 3px #C08A52; }
        .stat-card.c-unlink   { border-left-color:#9A7BB0; }
        .stat-card.c-unlink .stat-value { color:#7E5E95; }
        .stat-card.c-unlink.active   { box-shadow:0 0 0 3px #9A7BB0; }
        .stat-card.c-done     { border-left-color:#1ABB9C; }
        .stat-card.c-done.active     { box-shadow:0 0 0 3px #1ABB9C; }

        /* ── 篩選列與主卡 ─────────────────────────────────────── */
        .filter-bar { background:#fff; padding:10px 12px; border-radius:8px; margin-bottom:15px;
            display:flex; gap:10px; align-items:center; flex-wrap:wrap; box-shadow:0 2px 5px rgba(0,0,0,.05); }
        .filter-bar .form-control { height:32px; font-size:13px; border-radius:6px; border-color:#D8E0EA; box-shadow:none; }
        .filter-bar .form-control:focus { border-color:#1ABB9C; }
        .filter-bar label { font-size:12px; color:#7A869A; font-weight:600; margin:0 5px 0 0; }
        .fb-item { display:flex; align-items:center; }
        .main-card { background:var(--card-bg); border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,.05); padding:15px; }
        .table-toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:8px; }

        .btn-teal { background:#1ABB9C; border-color:#16a085; color:#fff; }
        .btn-teal:hover, .btn-teal:focus { background:#16a085; border-color:#128f76; color:#fff; }
        .btn-navy { background:#2A3F54; border-color:#22344a; color:#fff; }
        .btn-navy:hover, .btn-navy:focus { background:#22344a; color:#fff; }
        .btn-soft { background:#fff; border:1px solid #D8E0EA; color:#5A6B7C; }
        .btn-soft:hover, .btn-soft:focus { background:#F4F7FC; border-color:#1ABB9C; color:#0e8c73; }

        /* ── 清單 ─────────────────────────────────────────────── */
        table.el-tbl { width:100%; }
        table.el-tbl thead th { background:#F8F9FA; color:#555; font-weight:700; border-bottom:2px solid #E9ECEF;
            padding:10px 8px; font-size:13px; white-space:nowrap; text-align:left; }
        table.el-tbl tbody td { padding:9px 8px; vertical-align:top; border-bottom:1px solid #F1F3F5; font-size:13px; }
        table.el-tbl tbody tr.clickable { cursor:pointer; }
        table.el-tbl tbody tr:hover { background:#FAFBFE; }
        table.el-tbl tbody tr.row-over { background:#fdecea; }
        table.el-tbl tbody tr.row-over:hover { background:#fbdcd8; }
        .el-no { font-family:Consolas,Monaco,monospace; font-size:12px; color:#8A94A0; white-space:nowrap; }
        .el-title { font-weight:700; color:#2A3F54; font-size:13.5px; }

        /* 綁定徽章：比照 personal_task 的淡底暖色小框（低調不搶眼） */
        .bind-badge { display:inline-block; padding:0 5px; border-radius:3px; font-size:10px; line-height:1.7;
            margin:2px 4px 0 0; border:1px solid #E3E9F1; background:#FDFAF5; color:#5A6B7C; white-space:nowrap; }
        .bind-badge b { font-weight:700; margin-right:4px; }
        .bind-bom      { color:#9A7BB0; border-color:#E2D5EC; background:#FAF7FC; }
        .bind-part     { color:#7FA3BC; border-color:#D8E5EE; background:#F7FAFC; }
        .bind-customer { color:#7FB3A2; border-color:#D5EAE2; background:#F6FBF9; }
        .bind-maker    { color:#C99A6B; border-color:#EEDFCE; background:#FCF8F3; }
        .bind-order    { color:#C08A52; border-color:#EFDFC9; background:#FDF8F0; }
        .bind-ship     { color:#6E9BB8; border-color:#D6E4EE; background:#F6FAFD; }
        .bind-return   { color:#B98080; border-color:#EBD7D7; background:#FDF7F7; }
        .bind-car_no, .bind-qa_no { color:#C0392B; border-color:#F0C8C2; background:#FDF3F1; }
        .bind-badge a { color:#9AA5B1; margin-left:5px; text-decoration:none; font-weight:700; }
        .bind-badge a:hover { color:#E74C3C; }

        /* 狀態籤：急件燈號三色（ai-rules/10），顏色不是唯一資訊，都同時寫天數 */
        .chip { display:inline-block; padding:1px 8px; border-radius:10px; font-size:11px; font-weight:700;
            line-height:1.8; white-space:nowrap; border:1px solid transparent; }
        .chip-wait  { background:#FCEFD9; border-color:#F0D6A8; color:#B5732A; }
        .chip-over  { background:#E74C3C; border-color:#C0392B; color:#fff; }
        .chip-done  { background:#E4F6F1; border-color:#B9E5DA; color:#0e8c73; }
        .chip-close { background:#F1F3F5; border-color:#E1E5EA; color:#8A94A0; }
        .chip-warn  { background:#FAF7FC; border-color:#E2D5EC; color:#7E5E95; }

        /* ── 問題項：一條一個區塊（回覆會越積越多，表格列裝不下） ── */
        .qitem { border-top:1px solid #F1F3F5; padding:12px 14px; }
        .qitem:first-child { border-top:none; }
        .qitem.is-done { background:#FAFBFC; }
        /* 欄位順序（使用者指定）：編號 → 狀態 → 日期 → 對象 → 問題 → ⋯
           狀態·日期·對象靠在問題左邊，一眼看得完，不要一個在左一個在右 */
        .qhead { display:grid; grid-template-columns:2rem 7.6rem 6.6rem 10rem minmax(0,1fr) 2.2rem;
            gap:0 10px; align-items:center; }
        .q-more { font-size:13px; color:#9AA5B1; text-decoration:none; padding:0 4px; }
        .q-more:hover { color:#1ABB9C; text-decoration:none; }

        /* 附件小圖：直接看得到內容，點開放大 */
        .att-strip { display:inline-flex; flex-wrap:wrap; gap:5px; align-items:center; margin-left:6px; vertical-align:middle; }
        .att-thumb { max-height:46px; max-width:82px; width:auto; height:auto; border:1px solid #D8E0EA;
            border-radius:4px; cursor:pointer; display:block; background:#fff; }
        .att-thumb:hover { border-color:#1ABB9C; }
        .qno { font-family:Consolas,Monaco,monospace; font-size:13px; color:#9AA5B1; font-weight:700; padding-top:1px; }
        .qtext { font-size:13.5px; line-height:1.7; color:#2A3F54; min-width:0; word-break:break-word; }
        .qmark { color:#1ABB9C; font-weight:700; margin-right:2px; }
        .qmeta { font-size:12px; color:#8A94A0; line-height:1.5; min-width:0; word-break:break-word; }
        .qmeta b { display:block; color:#5A6B7C; font-size:12.5px; font-weight:600; }
        .qdate { font-family:Consolas,Monaco,monospace; font-size:12px; color:#9AA5B1; }
        .qact { text-align:right; }
        .qreplies { margin:9px 0 0 calc(2rem + 10px); }
        .rep { display:grid; grid-template-columns:6.8rem minmax(0,1fr) 2rem; gap:0 10px; background:#F7F9FC;
            border:1px solid #E3E9F1; border-left:3px solid #1ABB9C; border-radius:6px;
            padding:7px 11px; font-size:12.5px; line-height:1.65; margin-bottom:6px; }
        .rep .rdate { font-family:Consolas,Monaco,monospace; font-size:11.5px; color:#0e8c73; font-weight:700; }
        .rep .rwho { display:block; color:#9AA5B1; font-weight:400; font-size:11px; }
        .rep .rbody { min-width:0; color:#495057; white-space:pre-wrap; word-break:break-word; }
        .rep .rdel { text-align:right; }
        .rep .rdel a { color:#C4CBD3; font-size:13px; }
        .rep .rdel a:hover { color:#E74C3C; }
        .att { display:inline-block; font-size:11px; border:1px solid #D8E0EA; padding:0 7px; border-radius:10px;
            color:#5B8DEF; margin:2px 4px 0 0; background:#fff; text-decoration:none; }
        .att:hover { background:#F0F5FB; color:#3B6FD4; text-decoration:none; }
        @media (max-width:900px) {
            .qhead { grid-template-columns:2rem minmax(0,1fr); }
            .qhead .qmeta, .qhead .qdate, .qhead .qstat, .qhead .qact { grid-column:2; text-align:left; }
        }

        /* ── 跳窗：漸層標頭＋淺底＋白色區塊卡（同 personal_task） ── */
        .el-mask { display:none; position:fixed; inset:0; background:rgba(42,63,84,.45); z-index:10050; overflow:auto; }
        .el-mask.on { display:block; }
        .el-modal { background:#F4F7FC; margin:26px auto; max-width:1040px; width:96%; border-radius:10px;
            overflow:hidden; box-shadow:0 12px 40px rgba(42,63,84,.35);
            max-height:calc(100vh - 52px); display:flex; flex-direction:column; }
        .el-modal.sm { max-width:640px; }
        .el-modal.md { max-width:820px; }
        .m-head { background:linear-gradient(135deg,#2A3F54 0%,#1ABB9C 100%); color:#fff; padding:13px 16px;
            font-size:15px; font-weight:700; display:flex; align-items:center; gap:10px; flex-shrink:0; }
        .m-head .sub { font-weight:400; font-size:12px; opacity:.85; }
        .m-head .x { margin-left:auto; cursor:pointer; font-size:24px; line-height:1; opacity:.75; }
        .m-head .x:hover { opacity:1; }
        .m-body { padding:15px; overflow:auto; flex:1 1 auto; }
        .m-foot { padding:10px 15px; border-top:1px solid #E8EEF5; text-align:right; flex-shrink:0; background:#F4F7FC; }
        .m-foot .btn { margin-left:6px; }

        .form-section { background:#fff; border:1px solid #E8EEF5; border-radius:8px; padding:12px 15px;
            margin-bottom:12px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
        .form-section-title { font-size:13px; font-weight:700; color:#2A3F54; margin-bottom:10px; }
        .form-section-title > i { color:#1ABB9C; margin-right:6px; }
        .el-modal .form-control { border-radius:6px; box-shadow:none; border-color:#D8E0EA; height:32px; font-size:13px; }
        .el-modal textarea.form-control { height:auto; }
        .el-modal .form-control:focus { border-color:#1ABB9C; }
        .el-modal label { font-size:12px; color:#7A869A; font-weight:600; margin-bottom:3px; }
        .fld { display:inline-block; margin:0 14px 10px 0; vertical-align:top; }
        .fld > label { display:block; }
        .req { color:#E74C3C; }
        .err { color:#E74C3C; font-size:12px; margin-top:3px; display:none; }
        .has-err .form-control { border-color:#E74C3C; }
        .has-err .err { display:block; }
        .tip { font-size:12px; color:#8A94A0; margin:0 0 10px; line-height:1.75; }
        .tip-warn { background:#FDECEA; border:1px solid #F5C6C0; border-left:3px solid #E74C3C; border-radius:6px;
            color:#8C3A28; padding:9px 12px; font-size:12.5px; line-height:1.75; margin:8px 0 0; }
        .tip-warn b { color:#C0392B; }

        /* ── 自動完成下拉：一定要 position:fixed ──────────────────
           .m-body 是 overflow:auto 的捲動容器，用 absolute 會被它裁掉，
           畫面上就是「打了字卻沒有清單可以選」（實際踩過）。 */
        .ac-wrap { position:relative; }
        .ac-list { position:fixed; z-index:10600; background:#fff; border:1px solid #D8E0EA; border-radius:6px;
            max-height:280px; overflow:auto; display:none; box-shadow:0 8px 24px rgba(42,63,84,.22); }
        .ac-list.on { display:block; }
        .ac-item { padding:7px 11px; cursor:pointer; border-bottom:1px solid #F1F3F5; font-size:13px; color:#2A3F54; }
        .ac-item:last-child { border-bottom:none; }
        .ac-item:hover, .ac-item.sel { background:#F0F9F7; }
        .ac-item .s { color:#8A94A0; font-size:11.5px; margin-left:8px; }
        .ac-empty { padding:9px 11px; font-size:12.5px; color:#9AA5B1; }

        /* ── 焦點浮動提示（欄位內不放 placeholder，避免誤以為已經輸入） ── */
        #egHint { position:fixed; z-index:10800; background:#2A3F54; color:#fff; font-size:12px; line-height:1.6;
            padding:5px 10px; border-radius:6px; max-width:340px; box-shadow:0 4px 14px rgba(42,63,84,.3);
            display:none; pointer-events:none; }
        #egHint::after { content:""; position:absolute; left:14px; bottom:-5px; width:0; height:0;
            border-left:5px solid transparent; border-right:5px solid transparent; border-top:5px solid #2A3F54; }
        #egHint.below::after { bottom:auto; top:-5px; border-top:none; border-bottom:5px solid #2A3F54; }

        .bind-row { display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; margin-bottom:10px; }
        .bind-box { border:1px dashed #D8E0EA; background:#FAFBFE; border-radius:6px; padding:9px 11px; min-height:42px; }

        table.q-input { width:100%; border-collapse:collapse; }
        table.q-input th { background:#F8F9FA; font-size:12px; padding:6px 8px; text-align:left;
            border-bottom:2px solid #E9ECEF; color:#555; font-weight:700; }
        table.q-input td { border-bottom:1px solid #F1F3F5; padding:4px 5px; }
        table.q-input .form-control { height:30px; font-size:12.5px; }
        table.q-input .qseq { text-align:center; color:#9AA5B1; font-size:12px; font-weight:700; }

        .pick-row { display:grid; grid-template-columns:1.7rem minmax(0,1fr) 6rem; gap:0 10px; padding:7px 11px;
            border-bottom:1px solid #F1F3F5; font-size:13px; align-items:baseline; }
        .pick-row:last-child { border-bottom:none; }
        .pick-row .pn { font-family:Consolas,Monaco,monospace; font-size:12.5px; color:#2A3F54; }
        .pick-row .qty { font-family:Consolas,Monaco,monospace; font-size:12px; text-align:right; color:#8A94A0; }
        .box-list { border:1px solid #E8EEF5; border-radius:6px; background:#fff; }

        /* 分頁列：一律 nowrap。容器一窄，「共 1 筆」這種短字串會被逐字折成直式 */
        .pager { display:flex; align-items:center; gap:6px; font-size:13px; color:#8A94A0; white-space:nowrap; flex:0 0 auto; }
        .pager > * { white-space:nowrap; flex:0 0 auto; }
        .pager .btn { padding:3px 10px; font-size:12.5px; }
        .table-toolbar > div { white-space:nowrap; }
        #listScope { white-space:nowrap; }

        /* 綁定 BOM 的製程條（比照 personal_task 的 .pt-bom-flow） */
        .el-bom-flow { margin-top:5px; padding:3px 8px; background:#F5F8FC; border:1px dashed #CDDBEA; border-radius:6px; }
        .bomf-head { font-size:11px; color:#5A7794; cursor:pointer; line-height:1.9; white-space:nowrap;
            overflow-x:auto; display:flex; align-items:center; gap:2px; }
        .bomf-head:hover { color:#2F5D85; }
        .bomf-caret { color:#5B8DEF; margin-right:3px; }
        .bomf-title { font-weight:700; }
        .bomf-no { font-family:Consolas,Monaco,monospace; color:#2A3F54; }
        .bomf-sep { color:#C4CBD3; }
        .bomf-part { color:#B5732A; text-decoration:underline; font-family:Consolas,Monaco,monospace; }
        .bomf-part:hover { color:#8F5416; }
        .bomf-cur { margin-left:8px; background:#E4F6F1; border:1px solid #B9E5DA; color:#0e8c73;
            border-radius:9px; padding:0 7px; font-weight:700; }
        .bomf-body { padding:4px 0 2px; overflow-x:auto; }
        .step-flow { display:flex; align-items:flex-start; flex-wrap:wrap; row-gap:6px; }
        .pt-step { display:flex; flex-direction:column; align-items:center; min-width:52px; max-width:108px; }
        .pt-step-top { display:flex; align-items:center; width:100%; }
        .pt-line { flex:1; height:3px; background:#E3E7ED; border-radius:2px; min-width:8px; }
        .pt-line.done { background:#1ABB9C; }
        .pt-line.edge { visibility:hidden; }
        .pt-dot { width:16px; height:16px; border-radius:50%; display:flex; align-items:center; justify-content:center;
            font-size:9px; font-weight:700; flex:0 0 16px; margin:0 2px; background:#fff; border:1px solid #CBD3DC; color:#9AA5B1; }
        .pt-step.reached .pt-dot { background:#1ABB9C; border-color:#1ABB9C; color:#fff; }
        .pt-step.current .pt-dot { background:#fff; border-color:#F39C12; color:#F39C12; }
        .pt-step-name { font-size:10px; margin-top:2px; color:#8A94A0; text-align:center; line-height:1.15; word-break:break-all; padding:0 2px; }
        .pt-step.reached .pt-step-name { color:#0e8c73; font-weight:600; }
        .pt-step.current .pt-step-name { color:#b9770e; font-weight:700; }
        .pt-step-maker { display:block; color:#B5732A; font-size:9px; line-height:1.1; }
        .pt-step-time { font-size:9px; color:#A8B0BA; white-space:nowrap; }
        .pt-step.reached .pt-step-time { color:#17a98a; }

        /* 料號小籤（可點開圖面／附件） */
        .part-chips { display:flex; flex-wrap:wrap; gap:4px; }
        .part-chip { display:inline-block; font-size:11px; line-height:1.7; padding:0 7px; border-radius:10px;
            border:1px solid #D8E5EE; background:#F7FAFC; color:#4A7C99; font-family:Consolas,Monaco,monospace;
            text-decoration:none; white-space:nowrap; }
        .part-chip:hover, .part-chip:focus { background:#E8F2F8; color:#2F6183; text-decoration:none; }
        .part-more { font-size:11px; line-height:1.7; color:#8A94A0; text-decoration:none; padding:0 4px; }
        .part-more:hover { color:#5B8DEF; text-decoration:none; }
        /* 綁定 ID 標籤：選定後顯示在對象名稱右側，一眼看得出「這是有綁到 ID 的」，
           使用者亂改文字就會消失，等於即時警示 */
        .idtag { display:inline-block; font-size:10.5px; font-weight:700; line-height:1.7; padding:0 6px;
            border-radius:9px; background:#E4F6F1; border:1px solid #B9E5DA; color:#0e8c73;
            font-family:Consolas,Monaco,monospace; margin-left:6px; white-space:nowrap; }
        .idtag.none { background:#FDECEA; border-color:#F5C6C0; color:#C0392B; }
        .idlock { color:#0e8c73; margin-left:5px; text-decoration:none; }
        .idlock:hover { color:#E74C3C; text-decoration:none; }
        /* 綁好之後欄位反灰唯讀，要換對象請按右邊的鎖頭 */
        input.locked { background:#EEF2F6 !important; color:#5A6B7C; cursor:not-allowed; }
        .q-idtag { display:block; margin:2px 0 0; }
        .qtarget-edit { color:#5A6B7C; font-size:12.5px; font-weight:600; border-bottom:1px dashed #C4CBD3;
            text-decoration:none; display:inline-block; }
        .qtarget-edit:hover { color:#1ABB9C; border-bottom-color:#1ABB9C; text-decoration:none; }
        .help-doc h4 { font-size:14px; color:#2A3F54; margin:16px 0 6px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc h4 > i { color:#1ABB9C; margin-right:6px; }
        .help-doc p, .help-doc li { font-size:13px; line-height:1.85; color:#495057; }
        .help-doc ul { padding-left:20px; }
        .to-top { position:fixed; right:22px; bottom:22px; z-index:900; display:none; }
        .no-access-box { max-width:520px; margin:80px auto; text-align:center; padding:40px; background:#fff;
            border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,.08); }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <!-- .right_col 第一個子元素一律 clear:both（.top_nav 高度 0 且浮動溢出，否則標題會被壓成寬度 0） -->
        <div style="clear:both;"></div>
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">工程處理紀錄
                <small style="color:#8a6d45;">發包／批圖的問題與往返，可用客戶·料號·廠商查回來</small></h2>
            <span style="margin-left:12px;font-size:12px;color:#7A4A12;background:#F7E0BD;border:1px solid #E4D3BC;
                         border-radius:12px;padding:2px 10px;">
                目前角色：<?= htmlspecialchars($roleLabel) ?>
                <a href="javascript:;" id="btnRoleHelp" title="各角色權限說明"
                   style="color:#8A5A2B;margin-left:4px;"><i class="fa fa-question-circle"></i></a>
            </span>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

        <?php if (!$P['canView']): ?>
            <div class="no-access-box">
                <i class="fa fa-lock" style="font-size:40px;color:#C4CBD3;"></i>
                <h3 style="color:#2A3F54;">沒有使用權限</h3>
                <p style="color:#8A94A0;">您目前沒有「工程處理紀錄」的使用權限，請洽管理員在「使用者權限設定」指派角色。</p>
            </div>
        <?php else: ?>

        <!-- 統計卡＝快捷篩選（點一下就是套用該篩選） -->
        <div class="stats-container" id="statCards"></div>

        <div class="filter-bar">
            <div class="fb-item"><label>關鍵字</label>
                <input type="text" id="fKw" class="form-control" style="width:200px;"
                       data-hint="標題、問題內容、對象回覆、綁定的單號都會一起搜；多個關鍵字用空白分隔，每個都要命中"></div>
            <div class="fb-item"><label>客戶</label>
                <select id="fCustomer" class="form-control" style="width:150px;" data-eg-filter="輸入客戶名稱篩選"></select></div>
            <div class="fb-item"><label>料號</label>
                <select id="fPart" class="form-control" style="width:160px;" data-eg-filter="輸入料號篩選"></select></div>
            <div class="fb-item"><label>廠商</label>
                <select id="fMaker" class="form-control" style="width:150px;" data-eg-filter="輸入廠商名稱篩選"></select></div>
            <div class="fb-item"><label>類型</label>
                <select id="fType" class="form-control" style="width:100px;"><option value="">全部</option></select></div>
            <div class="fb-item">
                <button class="btn btn-sm btn-soft" id="btnReset">清除條件</button></div>
        </div>

        <div class="main-card">
            <div class="table-toolbar">
                <div>
                    <?php if ($P['canEdit']): ?>
                    <button class="btn btn-sm btn-navy" id="btnNew"><i class="fa fa-plus"></i> 新增紀錄</button>
                    <?php endif; ?>
                    <span id="listScope" style="font-size:12px;color:#8A94A0;margin-left:10px;"></span>
                </div>
                <div class="pager" id="pager"></div>
            </div>
            <div style="overflow-x:auto;">
            <table class="el-tbl" id="listTbl">
                <thead><tr>
                    <th style="width:124px;">編號</th>
                    <th>標題與綁定</th>
                    <th style="width:160px;">問題進度</th>
                    <th style="width:104px;">狀態</th>
                    <th style="width:92px;">建立者</th>
                    <th style="width:100px;">建立日</th>
                </tr></thead>
                <tbody id="listBody"><tr><td colspan="6" style="text-align:center;color:#9AA5B1;padding:26px;">載入中…</td></tr></tbody>
            </table>
            </div>
        </div>

        <?php endif; ?>
    </div>
</div>
</div>

<button class="btn btn-default to-top" id="btnTop" title="回到頂端"><i class="fa fa-arrow-up"></i></button>

<!-- ═══ 新增／編輯案件 ═══ -->
<div class="el-mask" id="logMask"><div class="el-modal">
    <div class="m-head"><span id="logMTitle">新增紀錄</span><span class="sub" id="logMNo"></span>
        <span class="x" onclick="closeMask('logMask')">&times;</span></div>
    <div class="m-body">
        <div class="form-section">
            <div class="form-section-title"><i class="fa fa-file-text-o"></i>基本資料</div>
            <div class="fld" style="width:100%;max-width:440px;"><label>標題</label>
                <input type="text" id="gTitle" class="form-control" maxlength="200"
                       data-hint="不填的話系統會自動用「綁定對象＋類型」組一個，例如「RC105-N03-A 批圖問題」">
                <div class="err" id="eTitle"></div></div>
            <div class="fld"><label>類型（可不選）</label><select id="gType" class="form-control" style="width:120px;"></select></div>
            <div class="fld"><label>誰看得到</label><select id="gVis" class="form-control" style="width:120px;"></select></div>
            <div class="fld"><label>期限（可不填）</label><input type="datetime-local" id="gDeadline" class="form-control" style="width:195px;"></div>
            <div class="fld"><label>期限提醒</label>
                <span style="display:flex;align-items:center;gap:5px;">
                    <input type="number" id="gRemindVal" class="form-control" style="width:66px;" min="1"
                           data-hint="留空＝不提醒">
                    <select id="gRemindUnit" class="form-control" style="width:70px;"><option value="1440">天</option><option value="60">小時</option></select>
                    <span style="font-size:12px;color:#8A94A0;white-space:nowrap;">前提醒</span>
                </span></div>
            <div class="fld"><label>幾天內算急件</label>
                <input type="number" id="gUrgent" class="form-control" style="width:86px;" min="1" data-hint="留空＝預設 3 天"></div>
            <div class="fld" style="width:100%;"><label>案件備註</label>
                <textarea id="gNote" class="form-control" rows="2"></textarea></div>
        </div>

        <div class="form-section">
            <div class="form-section-title"><i class="fa fa-link"></i>綁定對象</div>
            <div class="tip">綁定是為了「日後查得回來」：綁了 BOM 會自動帶出料號、客戶與這張 BOM 的所有發包廠商，不必一個一個選。</div>
            <div class="bind-row">
                <div><label>型別</label>
                    <select id="gBindType" class="form-control" style="width:140px;"></select></div>
                <div class="ac-wrap"><label id="gBindLbl">關鍵字</label>
                    <input type="text" id="gBindKw" class="form-control" style="width:360px;" autocomplete="off"
                           data-hint="單號、客戶名稱、客戶ID、料號都可以搜">
                    <div class="ac-list" id="gBindAc"></div></div>
                <div><button class="btn btn-soft" id="btnAddManual" style="height:32px;display:none;">加入單號</button></div>
            </div>
            <div class="err" id="eBind" style="margin:-4px 0 8px;"></div>
            <div class="bind-box" id="gBindBox"></div>
            <div id="gBindWarn" class="tip-warn" style="display:none;"></div>
        </div>

        <div class="form-section" id="newItemsWrap">
            <div class="form-section-title"><i class="fa fa-question-circle-o"></i>問題（可一次打很多條，末列按 ↓ 自動加一列）</div>
            <div class="tip">一條問題只對一個對象；同一個問題要問客戶也要問廠商時，請拆成兩條各自等回覆。
                對象<b>要從清單選起來</b>，只打名字的話日後對方改名就對應不到。</div>
            <div style="overflow-x:auto;">
            <table class="q-input">
                <thead><tr><th style="width:34px;">#</th><th style="min-width:220px;">問題內容</th><th style="width:104px;">對象</th>
                    <th style="width:230px;">對象名稱</th><th style="width:118px;">聯絡人</th>
                    <th style="width:140px;">提出日期</th><th style="width:34px;"></th></tr></thead>
                <tbody id="qInputBody" data-eg-row-add="qRowAdd" data-eg-row-del="qRowDel"></tbody>
            </table>
            </div>
        </div>

        <div class="form-section">
            <div class="form-section-title"><i class="fa fa-paperclip"></i>附件</div>
            <div class="tip" id="gFileHint">尚未儲存前檔案先暫存，按「儲存」時自動掛到這筆紀錄。</div>
            <input type="file" id="gFile" multiple style="font-size:12px;">
            <div id="gFileList" style="margin-top:8px;"></div>
        </div>
    </div>
    <div class="m-foot">
        <button class="btn btn-soft" onclick="closeMask('logMask')">取消</button>
        <button class="btn btn-teal" id="btnSaveLog"><i class="fa fa-check"></i> 儲存</button>
    </div>
</div></div>

<!-- ═══ 案件明細 ═══ -->
<div class="el-mask" id="detMask"><div class="el-modal">
    <div class="m-head"><span id="detNo"></span><span class="sub" id="detSub"></span>
        <span class="x" onclick="closeMask('detMask')">&times;</span></div>
    <div class="m-body">
        <div class="form-section" style="padding:10px 12px;">
            <div id="detBinds"></div>
            <div id="detNote" class="tip" style="display:none;margin:8px 0 0;"></div>
            <div id="detUnlinked" class="tip-warn" style="display:none;"></div>
        </div>

        <div class="form-section">
            <div class="form-section-title" style="display:flex;align-items:center;">
                <span><i class="fa fa-question-circle-o"></i>問題項</span>
                <span style="margin-left:auto;font-weight:normal;" id="detItemActs"></span>
            </div>
            <div class="box-list" id="detItems"></div>
        </div>

        <div class="form-section">
            <div class="form-section-title"><i class="fa fa-paperclip"></i>案件附件</div>
            <div id="detFiles"></div>
        </div>
        <div id="detConclusion" class="form-section" style="display:none;border-left:3px solid #1ABB9C;font-size:13px;"></div>
    </div>
    <div class="m-foot" id="detFoot"></div>
</div></div>

<!-- ═══ 填回覆（可一次套用到多條問題） ═══ -->
<div class="el-mask" id="repMask"><div class="el-modal md">
    <div class="m-head"><span>填寫對象回覆</span><span class="sub" id="repSub"></span>
        <span class="x" onclick="closeMask('repMask')">&times;</span></div>
    <div class="m-body">
        <div class="form-section">
            <div class="form-section-title"><i class="fa fa-check-square-o"></i>這一次回覆涵蓋哪幾條問題</div>
            <div class="tip">客戶一通電話回了好幾題時，勾起來只要填一次。</div>
            <div id="repItems" class="box-list" style="max-height:230px;overflow:auto;"></div>
        </div>
        <div class="form-section">
            <div class="form-section-title"><i class="fa fa-reply"></i>回覆內容</div>
            <div class="fld"><label>回覆日期 <span class="req">*</span></label>
                <input type="date" id="rDate" class="form-control" style="width:165px;">
                <div style="font-size:11.5px;color:#9AA5B1;margin-top:3px;">預設今天；對方是幾天前回的請改成當天日期</div>
                <div class="err" id="eRDate"></div></div>
            <div class="fld"><label>回覆人</label>
                <input type="text" id="rBy" class="form-control" style="width:175px;" maxlength="60"
                       data-hint="對方回覆的窗口姓名，例如客戶的工程師"></div>
            <div class="fld"><label>方式</label><select id="rChannel" class="form-control" style="width:115px;"></select></div>
            <div class="fld" style="width:100%;"><label>回覆內容 <span class="req">*</span></label>
                <textarea id="rContent" class="form-control" rows="4"></textarea>
                <div class="err" id="eRContent"></div></div>
        </div>
        <div class="form-section">
            <div class="form-section-title"><i class="fa fa-paperclip"></i>附件（掛在這一則回覆上）</div>
            <input type="file" id="rFile" multiple style="font-size:12px;">
            <div id="rFileList" style="margin-top:8px;"></div>
        </div>
    </div>
    <div class="m-foot">
        <button class="btn btn-soft" onclick="closeMask('repMask')">取消</button>
        <button class="btn btn-teal" id="btnSaveReply"><i class="fa fa-check"></i> 儲存回覆</button>
    </div>
</div></div>

<!-- ═══ 單一問題項 新增／編輯 ═══ -->
<div class="el-mask" id="itemMask"><div class="el-modal sm">
    <div class="m-head"><span id="itemMTitle">新增問題</span><span class="x" onclick="closeMask('itemMask')">&times;</span></div>
    <div class="m-body">
        <div class="form-section">
            <div class="fld" style="width:100%;"><label>問題內容 <span class="req">*</span></label>
                <textarea id="iQuestion" class="form-control" rows="3"></textarea>
                <div class="err" id="eIQuestion"></div></div>
            <div class="fld"><label>對象</label><select id="iTargetType" class="form-control" style="width:118px;"></select></div>

            <!-- 客戶／廠商：打字搜尋（選起來才會綁到 ID） -->
            <span id="iTargetOuter">
                <div class="fld ac-wrap"><label>對象名稱 <span class="req">*</span>
                        <span id="iTargetIdTag" class="idtag" style="display:none;"></span></label>
                    <input type="text" id="iTargetKw" class="form-control" style="width:240px;" autocomplete="off"
                           data-hint="打字搜尋後要從清單點選；只打名字不選，日後對方改名就對應不到">
                    <div class="ac-list" id="iTargetAc"></div>
                    <div class="err" id="eITarget"></div></div>
            </span>
            <!-- 公司內部：先選部門，再選該部門底下的人（含兼任，職稱由大到小） -->
            <span id="iTargetInner" style="display:none;">
                <div class="fld"><label>部門 <span class="req">*</span></label>
                    <select id="iDept" class="form-control" style="width:150px;" data-eg-filter="輸入部門名稱篩選"></select></div>
                <div class="fld"><label>人員 <span class="req">*</span></label>
                    <select id="iPerson" class="form-control" style="width:230px;" data-eg-filter="輸入職稱或姓名篩選"></select>
                    <div class="err" id="eIPerson"></div></div>
            </span>

            <div class="fld"><label>聯絡人</label>
                <input type="text" id="iContact" class="form-control" style="width:140px;" maxlength="60"
                       data-hint="對方公司的窗口姓名（公司內部不必填）"></div>
            <div class="fld"><label>提出日期</label><input type="date" id="iAsked" class="form-control" style="width:155px;">
                <div class="err" id="eIAsked"></div></div>
            <div class="fld"><label>等幾天沒回就提醒</label>
                <input type="number" id="iFollowUp" class="form-control" style="width:96px;" min="1" max="365"
                       data-hint="留空＝預設 7 個工作天">
                <div style="font-size:11.5px;color:#9AA5B1;margin-top:3px;">算工作天</div></div>
        </div>
    </div>
    <div class="m-foot">
        <button class="btn btn-soft" onclick="closeMask('itemMask')">取消</button>
        <button class="btn btn-teal" id="btnSaveItem"><i class="fa fa-check"></i> 儲存</button>
    </div>
</div></div>

<!-- ═══ 出貨單／退貨單 多料號勾選 ═══ -->
<div class="el-mask" id="pickMask"><div class="el-modal sm">
    <div class="m-head"><span>這張單底下有多個料號</span><span class="x" onclick="closeMask('pickMask')">&times;</span></div>
    <div class="m-body">
        <div class="tip" id="pickHead"></div>
        <div class="tip">要綁定哪幾個料號？沒有勾到的料號，日後用那個料號是查不到這筆紀錄的。</div>
        <div style="margin-bottom:6px;">
            <a href="javascript:;" id="pickAll" style="font-size:12px;">全選</a>
            <a href="javascript:;" id="pickNone" style="font-size:12px;">全不選</a>
        </div>
        <div class="box-list" style="max-height:320px;overflow:auto;" id="pickBody"></div>
    </div>
    <div class="m-foot">
        <button class="btn btn-soft" onclick="closeMask('pickMask')">取消</button>
        <button class="btn btn-teal" id="btnPickOk">確定綁定</button>
    </div>
</div></div>

<!-- ═══ 結案 ═══ -->
<div class="el-mask" id="closeMask2"><div class="el-modal sm">
    <div class="m-head"><span>結案</span><span class="x" onclick="closeMask('closeMask2')">&times;</span></div>
    <div class="m-body">
        <div class="tip">寫一句話說明這件事最後怎麼處理，日後查回來時看得懂就好。</div>
        <div class="fld" style="width:100%;"><label>結論 <span style="color:#DD5138;">*</span></label>
            <textarea id="cConclusion" class="form-control" rows="3" style="font-size:13px;"></textarea>
            <div class="err" id="eCConclusion"></div></div>
    </div>
    <div class="m-foot">
        <button class="btn btn-soft" onclick="closeMask('closeMask2')">取消</button>
        <button class="btn btn-teal" id="btnDoClose">確定結案</button>
    </div>
</div></div>

<!-- ═══ 刪除二次確認（要手動輸入大寫 Y） ═══ -->
<div class="el-mask" id="delMask"><div class="el-modal sm">
    <div class="m-head" style="background:linear-gradient(135deg,#8C3A28 0%,#E74C3C 100%);">
        <span id="dcTitle">確認刪除</span><span class="x" onclick="closeMask('delMask')">&times;</span></div>
    <div class="m-body">
        <div class="form-section">
            <div class="tip" id="dcDetail" style="margin-bottom:10px;"></div>
            <div class="tip" style="color:#C0392B;">刪除後無法復原。確定的話請在下面<b>輸入大寫的 Y</b> 再按確定。</div>
            <input type="text" id="dcInput" class="form-control" maxlength="1" autocomplete="off"
                   style="width:90px;text-align:center;font-size:18px;font-weight:700;letter-spacing:2px;">
            <div class="err" id="dcErr" style="display:none;">請輸入大寫的 Y</div>
        </div>
    </div>
    <div class="m-foot">
        <button class="btn btn-soft" onclick="closeMask('delMask')">取消</button>
        <button class="btn" id="dcOk" style="background:#E74C3C;border-color:#C0392B;color:#fff;">確定刪除</button>
    </div>
</div></div>

<!-- ═══ 附件預覽（可按「在新分頁開啟」拉成獨立分頁） ═══ -->
<div class="el-mask" id="attMask"><div class="el-modal">
    <div class="m-head"><span id="attTitle"></span>
        <a id="attNewTab" href="#" target="_blank" class="btn btn-xs btn-soft" style="margin-left:12px;">
            <i class="fa fa-external-link"></i> 在新分頁開啟</a>
        <span class="x" onclick="closeMask('attMask')">&times;</span></div>
    <div class="m-body" id="attBody" style="background:#fff;"></div>
</div></div>

<!-- ═══ 角色說明 ═══ -->
<div class="el-mask" id="roleMask"><div class="el-modal sm">
    <div class="m-head"><span>角色權限說明</span><span class="x" onclick="closeMask('roleMask')">&times;</span></div>
    <div class="m-body">
        <table class="el-tbl"><thead><tr><th style="width:150px;">角色</th><th>說明</th></tr></thead><tbody>
        <?php foreach ($roleRows as $r): ?>
            <tr><td><?= htmlspecialchars($r['role_name']) ?><?= $r['is_system'] ? '（系統）' : '' ?></td>
                <td><?= htmlspecialchars((string)($r['note'] ?? '')) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$roleRows): ?>
            <tr><td colspan="2" style="color:#9AA5B1;">尚未建立本模組角色，請到「使用者權限設定」新增。</td></tr>
        <?php endif; ?>
        </tbody></table>
    </div>
    <div class="m-foot"><button class="btn btn-soft" onclick="closeMask('roleMask')">關閉</button></div>
</div></div>

<!-- ═══ 使用說明（鐵律7） ═══ -->
<div class="el-mask" id="helpUseMask"><div class="el-modal md">
    <div class="m-head"><span>工程處理紀錄 使用說明</span><span class="x" onclick="closeMask('helpUseMask')">&times;</span></div>
    <div class="m-body help-doc">
        <h4>這頁在做什麼</h4>
        <p>把發包、批圖過程中「問了誰、對方怎麼回、最後怎麼處理」留下來，
           日後可以用<b>客戶、料號、廠商</b>三個角度查回來。這裡<b>沒有簽核</b>，
           需要正式單據（工程變更申請單、異常矯正單…）請到各自的模組開立。</p>

        <h4>操作步驟</h4>
        <ul>
            <li><b>新增紀錄</b>：填標題 → 綁定對象 → 一次把所有問題打完（末列按 ↓ 自動加一列）→ 儲存。</li>
            <li><b>填回覆</b>：在明細裡勾選這一次回覆涵蓋哪幾條問題，填一次就好——
                客戶一通電話回了第 1、2、5 題時不用重打三次。</li>
            <li><b>回覆日期</b>預設今天，但一定要能改：對方多半是電話回的、你隔一兩天才補進系統，
                請改成<b>對方實際回覆的那一天</b>，否則之後查「這件事拖了幾天」會全錯。未來日期會被擋下。</li>
            <li><b>結案</b>：寫一句結論即可。結案後仍可重新開啟。</li>
        </ul>

        <h4>綁定：為什麼一定要綁</h4>
        <ul>
            <li>綁了 <b>BOM</b> 會自動帶出料號、客戶，以及這張 BOM 的<b>所有發包廠商</b>（逐關製程上就有），不必一個一個選。</li>
            <li><b>異常矯正單／品質異常處理單</b>目前還是紙本，所以單號用手填。但手填單號系統查不到它的料號與客戶，
                只填單號的話這筆紀錄<b>用客戶／料號／廠商都搜尋不到</b>——所以請一併綁 BOM、料號、出貨單或退貨單其中之一。
                系統只提示不硬擋（確實有對不到料號的異常單），沒綁的會標成「未連結」，之後補綁得回來。</li>
            <li>綁<b>出貨單或退貨單</b>時，如果那張單底下不只一個料號會跳出勾選清單（實測出貨單有四成是多料號）。
                沒勾到的料號日後查不到這筆紀錄。</li>
            <li>如果料號在主檔裡有同名的多筆，系統<b>不會亂猜</b>，那筆會顯示「未連結」，請自行再綁一個明確的料號。</li>
        </ul>

        <h4>提醒與逾期</h4>
        <ul>
            <li><b>期限提醒</b>：案件層，期限前幾天／幾小時提醒，空白＝不提醒。</li>
            <li><b>催回覆提醒</b>：問題項層，提出後等滿 N 個<b>工作天</b>仍沒回就提醒（預設 7 天，逐條可改）。
                週末與國定假日不算，所以週一上班不會整批變成逾期。</li>
            <li>催過之後對方說「再給我兩天」，請按該條的<b>「再等」</b>順延——不按的話這條從此不會再提醒。</li>
            <li>上方的<b>逾期未回</b>、<b>未連結料號</b>兩個篩選是每天最常點的入口。</li>
            <li>提醒走手機／電腦推播與 Telegram，<b>不會發成公告</b>。</li>
        </ul>

        <h4>誰看得到</h4>
        <ul>
            <li>每一筆可各自設定<b>僅自己／本部門／全公司</b>；自己建立的一律看得到。</li>
            <li>只能修改自己建立的紀錄，管理員才能改別人的。</li>
            <li>權限角色請洽管理員在「使用者權限設定」指派，說明見標題右側的
                <i class="fa fa-question-circle"></i>。</li>
        </ul>
    </div>
    <div class="m-foot"><button class="btn btn-teal" onclick="closeMask('helpUseMask')">我知道了</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script>
$(document).ready(function () {
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility', 'visible');
});

var API = '../../src/store/EngLog_API.php';
var CSRF = '', DICT = null, PERMS = null, ME = null, TODAY = '';
var ROWS = [], PAGE = 1, PER = 20, TOTAL = 0, QUICK = 'open';
var CUR = null;                 // 目前開啟的案件明細（跳窗用）
var EDIT_ID = 0, BINDS = [], TEMP_FILES = [], REP_TEMP_FILES = [];
var PICK_CTX = null, ITEM_EDIT = null;
var EXPANDED = {};              // 清單上展開中的案件： id -> 明細資料
var DEPTS = null;               // 部門清單（公司內部對象用，載入一次）
var OPEN_ID = <?= (int)$openId ?>;
var CAN_EDIT = <?= $P['canEdit'] ? 'true' : 'false' ?>;

/* 顯示用日期一律 YYYY.MM.DD（ai-rules/20，唯一實作 egFmtDate） */
function dispDate(v) { return (v && window.egFmtDate) ? egFmtDate(v) : (v || ''); }
function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }
function openMask(id) { $('#' + id).addClass('on'); }
function closeMask(id) { $('#' + id).removeClass('on'); $('.ac-list').removeClass('on'); hideHint(); }

/* 刪除一律要手動輸入大寫 Y 再確認（使用者要求）——刪掉的東西救不回來，
   用瀏覽器的 confirm 只要按 Enter 就過去了，太容易誤刪。 */
function confirmY(title, detail, cb) {
    $('#dcTitle').text(title);
    $('#dcDetail').text(detail || '');
    $('#dcInput').val('');
    $('#dcErr').hide();
    $('#dcOk').off('click').on('click', function () {
        if ($('#dcInput').val() !== 'Y') { $('#dcErr').show(); $('#dcInput').focus(); return; }
        closeMask('delMask'); cb();
    });
    openMask('delMask');
    setTimeout(function () { $('#dcInput').focus(); }, 60);
}
$(document).on('keydown', '#dcInput', function (e) { if (e.which === 13) $('#dcOk').click(); });

function apiGet(action, data) { return $.getJSON(API, $.extend({ action: action }, data || {})); }
function apiPost(action, data) { return $.post(API, $.extend({ action: action, csrf: CSRF }, data || {}), null, 'json'); }
$(document).ajaxError(function (e, xhr) {
    if (xhr && xhr.status) alert('連線發生問題（HTTP ' + xhr.status + '），請重新整理頁面後再試。');
});
function okOrAlert(r) {
    if (r && r.success) return true;
    alert((r && r.message) ? r.message : '操作失敗');
    return false;
}

/* ── 焦點浮動提示 ─────────────────────────────────────────────────────
   欄位內刻意不放 placeholder：淡灰字常被誤看成「已經有輸入了」。
   改成聚焦時在欄位上方浮一個小提示，離開就收起。 */
var $HINT = null;
function hideHint() { if ($HINT) $HINT.hide(); }
$(document).on('focus', '[data-hint]', function () {
    var txt = $(this).attr('data-hint');
    if (!txt) return;
    if (!$HINT) $HINT = $('<div id="egHint">').appendTo('body');
    $HINT.text(txt).show();
    var r = this.getBoundingClientRect(), h = $HINT.outerHeight(), w = $HINT.outerWidth();
    var top = r.top - h - 8, below = false;
    if (top < 6) { top = r.bottom + 8; below = true; }
    var left = Math.max(6, Math.min(r.left, $(window).width() - w - 8));
    $HINT.toggleClass('below', below).css({ top: top, left: left });
});
$(document).on('blur', '[data-hint]', hideHint);

/* ── 自動完成下拉：position:fixed 定位 ─────────────────────────────────
   跳窗的 .m-body 是 overflow:auto 的捲動容器，absolute 會被它裁掉，
   畫面上就是「打了字卻沒有清單」。所以一律 fixed ＋ 依輸入框即時算座標。 */
function placeAc($inp, $ac) {
    var r = $inp[0].getBoundingClientRect();
    var w = Math.max(r.width, 260);
    var left = Math.max(6, Math.min(r.left, $(window).width() - w - 8));
    var maxH = 280, top = r.bottom + 2;
    if (top + maxH > $(window).height() - 8) {
        var above = r.top - 2 - maxH;
        if (above > 6) top = r.top - 2 - Math.min(maxH, r.top - 10);
    }
    $ac.css({ left: left, top: top, width: w, maxHeight: maxH });
}
var acTimer = null;
function acSearch(type, kw, $inp, $ac, onPick) {
    kw = $.trim(kw || '');
    clearTimeout(acTimer);
    if (kw.length < 1) { $ac.removeClass('on').empty(); return; }
    acTimer = setTimeout(function () {
        apiGet('bind_search', { type: type, kw: kw }).done(function (r) {
            $ac.empty();
            placeAc($inp, $ac);
            if (!r.success || !r.rows || !r.rows.length) {
                $ac.append($('<div class="ac-empty">').text('查無資料，換個關鍵字試試（單號、客戶名稱、客戶ID、料號都可以搜）'));
                $ac.addClass('on'); return;
            }
            $.each(r.rows, function (i, row) {
                $('<div class="ac-item">').append($('<span>').text(row.label))
                    .append($('<span class="s">').text(row.sub || ''))
                    .on('mousedown', function (e) { e.preventDefault(); $ac.removeClass('on'); onPick(row); })
                    .appendTo($ac);
            });
            $ac.addClass('on');
        });
    }, 220);
}
$(document).on('blur', '.ac-wrap input', function () {
    var $ac = $(this).closest('.ac-wrap').find('.ac-list');
    setTimeout(function () { $ac.removeClass('on'); }, 180);
});
$(window).on('scroll resize', function () { $('.ac-list.on').removeClass('on'); hideHint(); });

/* ── 啟動 ─────────────────────────────────────────────────────────── */
$(function () {
    apiGet('bootstrap').done(function (r) {
        if (!r.success) { alert(r.message || '無法載入'); return; }
        CSRF = r.csrf; DICT = r.dict; PERMS = r.perms; ME = r.me; TODAY = r.today;
        fillDicts();
        loadStats();
        loadList();
        loadFilterOptions();
        if (OPEN_ID > 0) openDetail(OPEN_ID);
    });
});

function fillDicts() {
    var $ft = $('#fType'), $gt = $('#gType');
    $gt.append($('<option>').val('other').text('未分類'));
    $.each(DICT.log_types, function (k, v) {
        if (k !== 'other') { $ft.append($('<option>').val(k).text(v)); $gt.append($('<option>').val(k).text(v)); }
    });
    $ft.val(''); $gt.val('other');
    $.each(DICT.visibility, function (k, v) { $('#gVis').append($('<option>').val(k).text(v)); });
    $('#gVis').val('dept');
    $.each(DICT.channels, function (k, v) { $('#rChannel').append($('<option>').val(k).text(v)); });
    $.each(DICT.bind_types, function (k, v) { $('#gBindType').append($('<option>').val(k).text(v.name)); });
    $.each({ customer: '客戶', maker: '廠商', user: '公司內部' }, function (k, v) {
        $('#iTargetType').append($('<option>').val(k).text(v));
    });
}

/* ── 統計卡（＝快捷篩選） ─────────────────────────────────────────── */
var CARDS = [
    { k: 'open',     t: '處理中',      c: 'c-open',   f: 'fa-folder-open-o', n: 'open_cnt' },
    { k: 'waiting',  t: '待回覆',      c: 'c-wait',   f: 'fa-hourglass-half', n: 'waiting_cnt' },
    { k: 'overdue',  t: '逾期未回',    c: 'c-over',   f: 'fa-exclamation-triangle', n: 'overdue_cnt' },
    { k: 'week',     t: '本週到期',    c: 'c-week',   f: 'fa-calendar-o',    n: 'week_cnt' },
    { k: 'unlinked', t: '未連結料號',  c: 'c-unlink', f: 'fa-chain-broken',  n: 'unlinked_cnt' },
    { k: 'done',     t: '已結案',      c: 'c-done',   f: 'fa-check-circle-o', n: 'done_cnt' }
];
function loadStats() {
    apiGet('stats').done(function (r) {
        if (!r.success) return;
        var s = r.stats || {};
        var $w = $('#statCards').empty();
        $.each(CARDS, function (i, c) {
            $('<div class="stat-card">').addClass(c.c).addClass(c.k === QUICK ? 'active' : '')
                .attr('data-k', c.k)
                .append($('<i class="fa stat-icon">').addClass(c.f))
                .append($('<div class="stat-value">').text(s[c.n] != null ? s[c.n] : 0))
                .append($('<div class="stat-label">').text(c.t))
                .appendTo($w);
        });
        $('#listScope').text('全部 ' + (s.total || 0) + ' 筆'
            + (QUICK ? '　目前篩選：' + (($.grep(CARDS, function (x) { return x.k === QUICK; })[0] || {}).t || '') : '　目前顯示全部'));
    });
}
$(document).on('click', '#statCards .stat-card', function () {
    var k = $(this).attr('data-k');
    QUICK = (QUICK === k) ? '' : k;          // 再點一次＝取消這個篩選，回到全部
    PAGE = 1;
    $('#statCards .stat-card').removeClass('active');
    if (QUICK) $(this).addClass('active');
    loadList(); loadStats();
});

/* ── 清單 ─────────────────────────────────────────────────────────── */
function filterParams() {
    return { kw: $('#fKw').val(), customer: $('#fCustomer').val(), part: $('#fPart').val(),
             maker: $('#fMaker').val(), log_type: $('#fType').val(),
             quick: QUICK, page: PAGE, per: PER };
}
function loadList() {
    $('#listBody').html('<tr><td colspan="6" style="text-align:center;color:#9AA5B1;padding:26px;">載入中…</td></tr>');
    apiGet('list', filterParams()).done(function (r) {
        if (!r.success) { $('#listBody').html('<tr><td colspan="6" style="color:#E74C3C;padding:22px;">' + esc(r.message) + '</td></tr>'); return; }
        ROWS = r.rows || []; TOTAL = r.total || 0;
        renderList(); renderPager(); renderBomFlows();
    });
}
function renderList() {
    var $b = $('#listBody').empty();
    if (!ROWS.length) {
        $b.html('<tr><td colspan="6" style="text-align:center;color:#9AA5B1;padding:28px;">沒有符合條件的紀錄</td></tr>');
        return;
    }
    $.each(ROWS, function (i, r) {
        var wait = Number(r.item_wait) || 0, cnt = Number(r.item_cnt) || 0, done = Number(r.item_done) || 0;
        var over = (r.status !== 'done' && wait > 0 && Number(r.wait_days) >= 7);
        var $tr = $('<tr class="clickable">').addClass(over ? 'row-over' : '').attr('data-id', r.id);

        $tr.append($('<td>').append($('<span class="caret-tog" style="color:#9AA5B1;margin-right:5px;">')
                .html(EXPANDED[r.id] ? '<i class="fa fa-caret-down"></i>' : '<i class="fa fa-caret-right"></i>'))
            .append($('<span class="el-no">').text(r.log_no)));

        var $c2 = $('<td>');
        $c2.append($('<div class="el-title">').text(r.title));
        var $bd = $('<div style="margin-top:3px;">');
        $.each(r.binds || [], function (j, b) {
            // BOM 底下會另外畫一條完整的「BOM 製程」資訊條，這裡就不要再重複列一次徽章
            if (b.bind_type === 'bom') return;
            var name = (DICT.bind_types[b.bind_type] || {}).name || b.bind_type;
            $('<span class="bind-badge">').addClass('bind-' + b.bind_type)
                .append($('<b>').text(name)).append(document.createTextNode(b.bind_label || b.bind_id)).appendTo($bd);
        });
        if (r.unlinked) $bd.append($('<span class="chip chip-warn" style="margin-left:4px;">').text('未連結料號'));
        $c2.append($bd);
        $c2.append(buildPartChips(r));
        // 綁定 BOM 者顯示完整 BOM 資訊條（比照 personal_task），下方製程流程可自行展開
        $.each(r.binds || [], function (j, b) {
            if (b.bind_type !== 'bom') return;
            $c2.append($('<div class="el-bom-flow" data-pending="1">').attr('data-bom', b.bind_id)
                .html('<span style="font-size:11px;color:#9AA5B1;">BOM 製程載入中…</span>'));
        });
        $tr.append($c2);

        var $c3 = $('<td>');
        $c3.append($('<div style="font-size:12.5px;color:#5A6B7C;">').text(cnt ? ('已回 ' + done + ' / ' + cnt) : '尚未列問題'));
        if (wait > 0) $c3.append($('<div style="font-size:11.5px;color:#9AA5B1;margin-top:2px;">')
            .text('待回覆 ' + wait + ' 條，最久已等 ' + (r.wait_days || 0) + ' 個工作天'));
        $tr.append($c3);

        var $c4 = $('<td>');
        if (r.status === 'done') $c4.append($('<span class="chip chip-close">').text('已結案'));
        else if (over) $c4.append($('<span class="chip chip-over">').text('逾期 ' + r.wait_days + ' 天'));
        else if (wait > 0) $c4.append($('<span class="chip chip-wait">').text('待回覆'));
        else $c4.append($('<span class="chip chip-done">').text('處理中'));
        $tr.append($c4);

        $tr.append($('<td style="font-size:12.5px;color:#5A6B7C;">').text(r.owner_name || ''));
        $tr.append($('<td style="font-size:12px;color:#5A6B7C;font-weight:600;">').text(dispDate(r.created_at)));
        $b.append($tr);

        // 展開列：直接在清單上填回覆，不必開跳窗
        var $ex = $('<tr class="expand-row">').attr('data-for', r.id).hide();
        $ex.append($('<td colspan="6" style="padding:0;background:#FAFBFE;">')
            .append($('<div class="ex-wrap" style="padding:6px 10px 10px;">')));
        $b.append($ex);
        if (EXPANDED[r.id]) { $ex.show(); renderInline(r.id, $ex.find('.ex-wrap')); }
    });
}
/* ── 綁定 BOM 的製程條（顯示方式比照 personal_task）────────────────────
   一行摘要：BOM 製程（50%）：B-1150904015｜M5T22｜松田｜數量 33
   點摘要可展開／收合下方的逐關流程（圓點＋連接線），預設收合避免列高爆掉。 */
var BOM_FLOW_CACHE = {};
function renderBomFlows() {
    var need = [];
    $('#listBody .el-bom-flow[data-pending="1"]').each(function () {
        var b = $(this).attr('data-bom');
        if (BOM_FLOW_CACHE[b]) { fillBomFlow($(this), BOM_FLOW_CACHE[b]); }
        else if ($.inArray(b, need) < 0) need.push(b);
    });
    if (!need.length) return;
    apiGet('bom_flow', { boms: JSON.stringify(need) }).done(function (r) {
        if (!r.success) return;
        $.each(r.flows || {}, function (k, v) { BOM_FLOW_CACHE[k] = v; });
        $('#listBody .el-bom-flow[data-pending="1"]').each(function () {
            var b = $(this).attr('data-bom');
            if (BOM_FLOW_CACHE[b]) fillBomFlow($(this), BOM_FLOW_CACHE[b]);
            else $(this).removeAttr('data-pending').empty();
        });
    });
}
function fillBomFlow($w, f) {
    $w.removeAttr('data-pending').empty();
    var pct = (f.progress_pct == null ? '—' : f.progress_pct + '%');
    var $head = $('<div class="bomf-head">');
    $head.append($('<span class="bomf-caret">').html('<i class="fa fa-caret-right"></i>'));
    $head.append($('<span class="bomf-title">').text('BOM 製程（' + pct + '）：'));
    $head.append($('<span class="bomf-no">').text(f.bom));
    if (f.d_id) {
        $head.append($('<span class="bomf-sep">').text('｜'));
        $head.append($('<a href="javascript:;" class="bomf-part" title="點開這個料號的圖面與附件">').text(f.d_id)
            .on('click', function (e) { e.stopPropagation(); openPartFiles({ pk: 0, part_no: f.d_id }, f.bom); }));
    }
    if (f.Client_Name) $head.append($('<span class="bomf-sep">').text('｜')).append($('<span>').text(f.Client_Name));
    if (f.sqty != null && f.sqty !== '') $head.append($('<span class="bomf-sep">').text('｜')).append($('<span>').text('數量 ' + f.sqty));
    if (f.latest_process_name) $head.append($('<span class="bomf-cur">').text(f.latest_process_name));
    $w.append($head);

    var $body = $('<div class="bomf-body" style="display:none;">');
    var $flow = $('<div class="step-flow">');
    var n = (f.nodes || []).length;
    $.each(f.nodes || [], function (i, nd) {
        var $st = $('<div class="pt-step">').addClass(nd.reached ? 'reached' : '').addClass(nd.current ? 'current' : '');
        var $top = $('<div class="pt-step-top">');
        $top.append($('<span class="pt-line">').addClass(i === 0 ? 'edge' : '').addClass(nd.reached ? 'done' : ''));
        $top.append($('<span class="pt-dot">').text(i + 1));
        $top.append($('<span class="pt-line">').addClass(i === n - 1 ? 'edge' : '').addClass(nd.reached ? 'done' : ''));
        $st.append($top);
        var $nm = $('<div class="pt-step-name">').text(nd.name);
        if (nd.maker) $nm.append($('<span class="pt-step-maker">').text(nd.maker));
        $st.append($nm);
        var t = [];
        if (nd.outsource_date) t.push('發 ' + dispDate(nd.outsource_date).substring(5));
        if (nd.return_date) t.push('回 ' + dispDate(nd.return_date).substring(5));
        if (t.length) $st.append($('<div class="pt-step-time">').text(t.join('　')));
        $flow.append($st);
    });
    $body.append(n ? $flow : $('<span style="font-size:11px;color:#9AA5B1;">這張 BOM 沒有製程資料</span>'));
    $w.append($body);

    /* BOM 資訊條裡已經有料號了，上面那排料號小籤就不要再重複顯示同一個 */
    if (f.d_id) {
        $w.closest('td').find('.part-chip').each(function () {
            if ($.trim($(this).text()) === $.trim(String(f.d_id))) $(this).remove();
        });
        var $chips = $w.closest('td').find('.part-chips');
        if ($chips.length && !$chips.children().length) $chips.remove();
    }

    $head.on('click', function (e) {
        e.stopPropagation();
        var open = $body.is(':visible');
        $body.toggle(!open);
        $head.find('.bomf-caret').html(open ? '<i class="fa fa-caret-right"></i>' : '<i class="fa fa-caret-down"></i>');
    });
}

/* 只要展開得出料號就顯示在清單上（含綁 BOM／訂單／出貨單間接推導出來的）。
   多筆時先顯示前 2 個，其餘收成「＋N 更多」，點開才展開整份清單，避免整列被撐高。 */
var PART_SHOW = 2;
function buildPartChips(r) {
    var parts = r.parts || [];
    if (!parts.length) return $();
    var $w = $('<div class="part-chips" style="margin-top:3px;">');
    var render = function (all) {
        $w.empty();
        var show = all ? parts : parts.slice(0, PART_SHOW);
        $.each(show, function (i, p) {
            $('<a href="javascript:;" class="part-chip" title="點開這個料號的圖面與附件">')
                .text(p.part_no || ('#' + p.pk))
                .on('click', function (e) { e.stopPropagation(); openPartFiles(p, r.bom); })
                .appendTo($w);
        });
        if (!all && parts.length > PART_SHOW) {
            $('<a href="javascript:;" class="part-more">').text('＋' + (parts.length - PART_SHOW) + ' 更多')
                .on('click', function (e) { e.stopPropagation(); render(true); }).appendTo($w);
        } else if (all && parts.length > PART_SHOW) {
            $('<a href="javascript:;" class="part-more">').text('收合')
                .on('click', function (e) { e.stopPropagation(); render(false); }).appendTo($w);
        }
    };
    render(false);
    return $w;
}
/* 點料號開圖面：比照 OreadyReply_ForPm_BaseOfTime 的 openBomFiles——
   有 BOM 就把 BOM 帶過去，part_viewer 只會列這張 BOM 相關的圖檔。
   另外帶 pk（d_setting.d_id 整數）精確指名是哪一筆料號主檔，避免同名料號抓錯。 */
function openPartFiles(p, bom) {
    var w = screen.availWidth, h = screen.availHeight;
    var pw = Math.min(1400, Math.round(w * 0.85)), ph = Math.min(900, Math.round(h * 0.88));
    var url = '../pm/part_viewer.php?d_id=' + encodeURIComponent(p.part_no || '')
            + '&pk=' + encodeURIComponent(p.pk)
            + (bom ? '&bom=' + encodeURIComponent(bom) : '');
    window.open(url, 'part_dv_' + p.pk,
        'width=' + pw + ',height=' + ph + ',left=' + Math.round((w - pw) / 2) + ',top=' + Math.round((h - ph) / 2)
        + ',resizable=yes,scrollbars=yes,menubar=no,toolbar=no,location=no,status=no');
}

$(document).on('click', '#listBody tr.clickable', function (e) {
    if ($(e.target).closest('a,button,input,textarea,select,.att,.part-chip,.part-more').length) return;
    toggleExpand(Number($(this).attr('data-id')));
});
function toggleExpand(id) {
    var $ex = $('#listBody tr.expand-row[data-for="' + id + '"]');
    var $tr = $('#listBody tr.clickable[data-id="' + id + '"]');
    if (EXPANDED[id]) {
        delete EXPANDED[id]; $ex.hide().find('.ex-wrap').empty();
        $tr.find('.caret-tog').html('<i class="fa fa-caret-right"></i>');
        return;
    }
    $tr.find('.caret-tog').html('<i class="fa fa-caret-down"></i>');
    $ex.show().find('.ex-wrap').html('<div style="padding:10px;color:#9AA5B1;font-size:12.5px;">載入中…</div>');
    apiGet('get', { id: id }).done(function (r) {
        if (!okOrAlert(r)) { $ex.hide(); return; }
        EXPANDED[id] = r;
        renderInline(id, $ex.find('.ex-wrap'));
    });
}
function refreshExpanded(id, cb) {
    apiGet('get', { id: id }).done(function (r) {
        if (!r.success) return;
        EXPANDED[id] = r;
        var $w = $('#listBody tr.expand-row[data-for="' + id + '"] .ex-wrap');
        if ($w.length) renderInline(id, $w);
        if (CUR && CUR.log && Number(CUR.log.id) === Number(id)) { CUR = r; renderDetail(); }
        if (cb) cb(r);
    });
}

/* 展開列的內容：問題項＋行內回覆輸入 */
function renderInline(id, $w) {
    var d = EXPANDED[id];
    if (!d) return;
    var w = d.log.can_write && CAN_EDIT && d.log.status !== 'done';
    $w.empty();

    var $bar = $('<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:4px 0 6px;">');
    $bar.append($('<span style="font-size:12px;color:#8A94A0;">').text('共 ' + d.items.length + ' 條問題'));
    if (w) {
        $bar.append($('<button class="btn btn-xs btn-soft">').html('<i class="fa fa-plus"></i> 新增問題')
            .on('click', function () { CUR = d; openItem(null); }));
    }
    $bar.append($('<button class="btn btn-xs btn-soft">').html('<i class="fa fa-external-link"></i> 開啟完整明細')
        .on('click', function () { openDetail(id); }));
    // 已建立的紀錄要能直接從清單刪掉（使用者要求），不必先開明細
    if (d.log.can_write && CAN_EDIT) {
        $bar.append($('<span style="flex:1 1 auto;">'));
        $bar.append($('<button class="btn btn-xs btn-soft" style="color:#E74C3C;">').html('<i class="fa fa-trash-o"></i> 刪除整筆')
            .on('click', function () {
                confirmY('刪除整筆紀錄', d.log.log_no + '　' + d.log.title + '：底下的問題、回覆與附件都會一起刪除。', function () {
                    apiPost('delete_log', { id: id }).done(function (r) {
                        if (okOrAlert(r)) { delete EXPANDED[id]; loadList(); loadStats(); loadFilterOptions(); }
                    });
                });
            }));
    }
    $w.append($bar);

    if (!d.items.length) {
        $w.append($('<div style="padding:8px 2px;color:#9AA5B1;font-size:13px;">還沒有列出任何問題。</div>'));
        return;
    }
    var $box = $('<div class="box-list">');
    $.each(d.items, function (i, it) { $box.append(buildItemBlock(d, it, w, true)); });
    $w.append($box);
}

/* 一條問題的區塊（清單展開與明細跳窗共用同一份，避免兩邊長得不一樣） */
function buildItemBlock(d, it, canWrite, inline) {
    var $q = $('<div class="qitem">').addClass(it.status === 'resolved' || it.status === 'dropped' ? 'is-done' : '')
        .attr('data-item', it.id);
    /* 欄位順序（使用者指定）：編號 → 狀態 → 日期 → 對象 → 問題 → ⋯
       狀態·日期·對象擠在一起放在問題前面，一眼就看得完，不要散在兩端。 */
    var $h = $('<div class="qhead">');
    $h.append($('<span class="qno">').text(it.seq));

    var $s = $('<span class="qstat">');
    if (it.status === 'waiting') {
        $s.append($('<span class="chip">').addClass(it.overdue ? 'chip-over' : 'chip-wait')
            .text((it.overdue ? '逾期 ' : '待回覆 ') + it.wait_days + ' 天'));
    } else if (it.status === 'answered') $s.append($('<span class="chip chip-done">').text('已回覆'));
    else if (it.status === 'resolved') $s.append($('<span class="chip chip-close">').text('已解決'));
    else $s.append($('<span class="chip chip-close">').text('不處理'));
    $h.append($s);

    $h.append($('<span class="qdate">').text(it.asked_at ? dispDate(it.asked_at) : ''));

    /* 對象要能直接改（使用者要求：輸入錯了要改得回來）——點對象就開這一條的編輯窗 */
    var $m = $('<span class="qmeta">');
    if (canWrite) {
        $m.append($('<a href="javascript:;" class="qtarget-edit" title="點一下修改這一條的對象">')
            .text(it.target_label || '（未指定，點此設定）')
            .on('click', function (e) { e.stopPropagation(); CUR = d; openItem(it); }));
    } else {
        $m.append($('<b>').text(it.target_label || '—'));
    }
    if (it.target_contact) $m.append(document.createTextNode(it.target_contact));
    $h.append($m);

    $h.append($('<span class="qtext">').append($('<span class="qmark">').text('Q：'))
        .append(document.createTextNode(it.question)));

    var $a = $('<span class="qact">');
    if (canWrite) {
        $a.append($('<a href="javascript:;" class="q-more" title="更多動作">').html('<i class="fa fa-ellipsis-h"></i>')
            .on('click', function (e) {
                e.stopPropagation(); CUR = d;
                var $bar = $q.find('.q-actions');
                $bar.toggle();
                if ($bar.is(':visible')) buildItemActions($bar, d, it);
            }));
    }
    $h.append($a);
    $q.append($h);

    var $r = $('<div class="qreplies">');
    $.each(it.replies || [], function (j, rp) {
        var $one = $('<div class="rep">');
        // 完整顯示年月日（使用者要求），不要只留月.日
        $one.append($('<span class="rdate">').text(dispDate(rp.replied_on))
            .append($('<span class="rwho">').text(rp.channel ? (DICT.channels[rp.channel] || rp.channel) : '')));
        var $body = $('<span class="rbody">').text(rp.content);
        if (rp.reply_by) $body.prepend($('<span style="color:#9AA5B1;">').text(rp.reply_by + '：'));
        var rf = $.grep(d.files, function (x) { return x.owner_type === 'reply' && Number(x.owner_id) === Number(rp.id); });
        if (rf.length) $body.append(buildAttachStrip(rf));
        $one.append($body);
        var $del = $('<span class="rdel">');
        if (canWrite) {
            $del.append($('<a href="javascript:;" title="刪除這則回覆">&times;</a>').on('click', function () {
                confirmY('刪除這則回覆', '第 ' + it.seq + ' 條問題在 ' + dispDate(rp.replied_on) + ' 的這則回覆。', function () {
                    apiPost('reply_delete', { log_id: d.log.id, id: rp.id }).done(function (res) {
                        if (okOrAlert(res)) { refreshExpanded(d.log.id); loadStats(); }
                    });
                });
            }));
        }
        $one.append($del);
        $r.append($one);
    });

    /* 行內回覆輸入：打完按 Enter 直接存（Shift+Enter 換行）。
       比照 NewOrder_Track 的設計備註：存好閃綠底表示已儲存。 */
    if (canWrite && it.status !== 'dropped') {
        // 日期欄要夠寬才顯示得完整個 YYYY-MM-DD（太窄瀏覽器只會露出年份）
        var $ri = $('<div class="reply-input" style="display:grid;grid-template-columns:9.5rem minmax(0,1fr) auto;gap:0 10px;align-items:start;margin-bottom:4px;">');
        $ri.append($('<input type="date" class="form-control ri-date">').val(TODAY)
            .css({ height: '30px', fontSize: '12.5px', padding: '3px 8px' })
            .attr('title', '對方實際回覆的日期，預設今天'));
        $ri.append($('<textarea class="form-control ri-text" rows="1">')
            .css({ fontSize: '12.5px', minHeight: '30px', padding: '5px 8px' })
            .attr('data-hint', '打完按 Enter 直接儲存（Shift+Enter 換行）；左邊可以改成對方實際回覆的那一天')
            .attr('data-log', d.log.id).attr('data-item', it.id));
        $ri.append($('<span style="font-size:11px;color:#9AA5B1;line-height:30px;white-space:nowrap;" class="ri-msg">')
            .text('Enter 儲存'));
        $r.append($ri);
    }
    // ⋯ 點開後在回覆輸入框下方長出按鈕列（不用瀏覽器的 prompt 對話框）
    $r.append($('<div class="q-actions" style="display:none;">'));
    $q.append($r);

    if (it.conclusion) {
        $q.append($('<div style="margin:4px 0 0 calc(2rem + 10px);font-size:12.5px;color:#0e8c73;">')
            .text('處理：' + it.conclusion));
    }
    return $q;
}

/* Enter 儲存行內回覆 */
$(document).on('keydown', '.ri-text', function (e) {
    if (e.which !== 13 || e.shiftKey) return;
    e.preventDefault();
    var $ta = $(this), txt = $.trim($ta.val());
    if (!txt) return;
    var $row = $ta.closest('.reply-input');
    var d = $row.find('.ri-date').val() || TODAY;
    var $msg = $row.find('.ri-msg');
    if (d > TODAY) { $msg.css('color', '#E74C3C').text('不可以是未來日期'); return; }
    var logId = Number($ta.attr('data-log')), itemId = Number($ta.attr('data-item'));
    $msg.css('color', '#9AA5B1').text('儲存中…');
    apiPost('reply_add', { log_id: logId, item_ids: JSON.stringify([itemId]), replied_on: d, content: txt })
        .done(function (r) {
            if (!r.success) { $msg.css('color', '#E74C3C').text(r.message || '儲存失敗'); return; }
            $ta.css('background-color', '#d4edda');   // 綠底＝已儲存（同 NewOrder_Track）
            $msg.css('color', '#0e8c73').text('已儲存');
            setTimeout(function () { refreshExpanded(logId); loadStats(); loadList(); }, 450);
        });
});
$(document).on('input', '.ri-text', function () {
    $(this).css('background-color', '');
    $(this).closest('.reply-input').find('.ri-msg').css('color', '#9AA5B1').text('Enter 儲存');
});

function renderPager() {
    var pages = PER > 0 ? Math.max(1, Math.ceil(TOTAL / PER)) : 1;
    var $p = $('#pager').empty();
    $p.append($('<span>').text('共 ' + TOTAL + ' 筆'));
    // data-eg-skip：只有 4 個選項不需要打字篩選。共用檔的 MutationObserver 目前會對
    // 「動態產生的任何 select」都長出篩選框（eg_input_rules.js:435 沒有比照初次掃描檢查
    // data-eg-filter），不擋的話翻頁鈕旁邊會多一個和上方關鍵字重複的搜尋框。
    var $per = $('<select class="form-control" data-eg-skip style="width:78px;height:28px;font-size:12.5px;display:inline-block;">');
    $.each([5, 10, 20, 50], function (i, n) { $per.append($('<option>').val(n).text(n + ' 筆')); });
    $per.val(PER).on('change', function () { PER = Number($(this).val()); PAGE = 1; loadList(); });
    $p.append($per);
    $p.append($('<button class="btn btn-soft btn-sm">').html('<i class="fa fa-angle-left"></i>').prop('disabled', PAGE <= 1)
        .on('click', function () { if (PAGE > 1) { PAGE--; loadList(); } }));
    $p.append($('<span style="font-size:12.5px;">').text(PAGE + ' / ' + pages));
    $p.append($('<button class="btn btn-soft btn-sm">').html('<i class="fa fa-angle-right"></i>').prop('disabled', PAGE >= pages)
        .on('click', function () { if (PAGE < pages) { PAGE++; loadList(); } }));
}

/* 三軸篩選選項：只列索引裡真的出現過的（不撈全站主檔，避免上千筆下拉） */
function loadFilterOptions() {
    apiGet('filter_options').done(function (r) {
        if (!r.success) return;
        fillOpt($('#fCustomer'), r.customers, '全部客戶');
        fillOpt($('#fPart'), r.parts, '全部料號');
        fillOpt($('#fMaker'), r.makers, '全部廠商');
    });
}
function fillOpt($sel, rows, allText) {
    var keep = $sel.val();
    $sel.empty().append($('<option>').val('').text(allText + '（' + (rows || []).length + '）'));
    $.each(rows || [], function (i, x) { $sel.append($('<option>').val(x.id).text(x.label || String(x.id))); });
    if (keep) $sel.val(keep);
}

/* 篩選一律即時生效（使用者要求），不必按查詢；打字有 300ms 緩衝避免每個字都打一次 API */
var kwTimer = null;
$('#fKw').on('input', function () {
    clearTimeout(kwTimer);
    kwTimer = setTimeout(function () { PAGE = 1; loadList(); }, 300);
});
$('#fKw').on('keydown', function (e) { if (e.which === 13) { clearTimeout(kwTimer); PAGE = 1; loadList(); } });
$('#fCustomer,#fPart,#fMaker,#fType').on('change', function () { PAGE = 1; loadList(); });
$('#btnReset').on('click', function () {
    $('#fKw').val(''); $('#fCustomer,#fPart,#fMaker,#fType').val('');
    PAGE = 1; loadList();
});

/* ── 新增／編輯案件 ─────────────────────────────────────────────────── */
$('#btnNew').on('click', function () { openLogModal(0); });

function openLogModal(id) {
    EDIT_ID = id || 0; BINDS = []; TEMP_FILES = [];
    $('#gTitle,#gNote,#gDeadline,#gRemindVal,#gUrgent').val('');
    $('#gType').val('other'); $('#gVis').val('dept'); $('#gRemindUnit').val('1440');
    $('#gFileList').empty(); $('#gFile').val('');
    $('#logMask .has-err').removeClass('has-err');
    $('#gBindType').val('bom').trigger('change');
    $('#logMNo').text('');
    if (EDIT_ID > 0) {
        $('#logMTitle').text('編輯紀錄');
        $('#newItemsWrap').hide();
        $('#gFileHint').text('附件會直接掛到這筆紀錄。');
        apiGet('get', { id: EDIT_ID }).done(function (r) {
            if (!okOrAlert(r)) return;
            var g = r.log;
            $('#logMNo').text(g.log_no);
            $('#gTitle').val(g.title); $('#gType').val(g.log_type); $('#gVis').val(g.visibility);
            $('#gNote').val(g.note || '');
            $('#gDeadline').val(g.deadline ? String(g.deadline).replace(' ', 'T').substring(0, 16) : '');
            if (g.remind_before_minutes) {
                var m = Number(g.remind_before_minutes);
                if (m % 1440 === 0) { $('#gRemindVal').val(m / 1440); $('#gRemindUnit').val('1440'); }
                else { $('#gRemindVal').val(Math.round(m / 60)); $('#gRemindUnit').val('60'); }
            }
            $('#gUrgent').val(g.urgent_days || '');
            BINDS = $.map(r.binds || [], function (b) {
                return { bind_type: b.bind_type, bind_id: b.bind_id, bind_label: b.bind_label,
                         sel_parts: b.sel_parts ? JSON.parse(b.sel_parts) : null };
            });
            renderBinds(); renderEditFiles(r.files || []);
            openMask('logMask');
        });
    } else {
        $('#logMTitle').text('新增紀錄');
        $('#newItemsWrap').show();
        $('#gFileHint').text('尚未儲存前檔案先暫存，按「儲存」時自動掛到這筆紀錄。');
        $('#qInputBody').empty(); qRowAdd(null);
        renderBinds();
        openMask('logMask');
    }
}

function renderEditFiles(files) {
    var $w = $('#gFileList').empty();
    $.each($.grep(files, function (x) { return x.owner_type === 'log'; }), function (i, f) {
        $('<div style="font-size:12px;margin-bottom:4px;">')
            .append($('<a class="att" target="_blank">').attr('href', API + '?action=download&id=' + f.id)
                .text('📎 ' + (f.original_name || f.file_name)))
            .append($('<a href="javascript:;" style="margin-left:8px;color:#C4CBD3;">').text('刪除')
                .on('click', function () { delFile(f.id, $(this).closest('div')); }))
            .appendTo($w);
    });
}
function delFile(id, $row) {
    confirmY('刪除附件', '這個附件的實體檔案也會一併從 NAS 移除。', function () {
        apiPost('file_delete', { id: id }).done(function (r) { if (okOrAlert(r)) $row.remove(); });
    });
}

/* ── 問題輸入列（可增列表格；對象一律要選到 ID） ───────────────────── */
function qRowAdd(afterTr) {
    var $tr = $('<tr>');
    $tr.append($('<td class="qseq">'));
    $tr.append($('<td>').append($('<textarea class="form-control q-q" rows="1" maxlength="500">')
        .attr('data-hint', '例：外徑 φ32 未標公差，是否比照前批 h7？')));
    var $tt = $('<select class="form-control q-tt" data-eg-skip>').append($('<option value="">—</option>'))
        .append($('<option value="customer">客戶</option>')).append($('<option value="maker">廠商</option>'))
        .append($('<option value="user">公司內部</option>'));
    $tr.append($('<td>').append($tt));

    var $td = $('<td class="q-target">');
    $td.append($('<span class="q-outer ac-wrap">')
        .append($('<input type="text" class="form-control q-tn" autocomplete="off">')
            .attr('data-hint', '打字搜尋後要從清單點選；只打名字不選日後對應不到'))
        .append($('<span class="idtag q-idtag" style="display:none;">'))
        .append($('<div class="ac-list q-ac">')));
    $td.append($('<span class="q-inner" style="display:none;">')
        .append($('<select class="form-control q-dept" data-eg-filter="輸入部門名稱篩選" style="margin-bottom:3px;">').append($('<option value="">選部門</option>')))
        .append($('<select class="form-control q-person" data-eg-filter="輸入職稱或姓名篩選">').append($('<option value="">選人員</option>'))));
    $tr.append($td);

    $tr.append($('<td>').append($('<input type="text" class="form-control q-tc" maxlength="60">')
        .attr('data-hint', '對方公司的窗口姓名（公司內部不必填）')));
    $tr.append($('<td>').append($('<input type="date" class="form-control q-ad">').val(TODAY)));
    $tr.append($('<td style="text-align:center;">').append($('<a href="javascript:;" style="color:#C4CBD3;" title="刪除這一列">&times;</a>')
        .on('click', function () { qRowDel($(this).closest('tr')); })));
    if (afterTr) $(afterTr).after($tr); else $('#qInputBody').append($tr);
    renumberQ();
    return $tr[0];
}
function qRowDel($tr) {
    if ($('#qInputBody tr').length <= 1) {
        $tr.find('input,textarea').val(''); $tr.find('.q-ad').val(TODAY);
        $tr.find('.q-tn').removeAttr('data-id'); renumberQ(); return;
    }
    $tr.remove(); renumberQ();
}
function renumberQ() { $('#qInputBody tr').each(function (i) { $(this).find('.qseq').text(i + 1); }); }

/* 對象類別切換：公司內部＝部門＋人員兩個下拉；其餘＝打字搜尋 */
$(document).on('change', '.q-tt', function () {
    var $tr = $(this).closest('tr'), v = $(this).val();
    $tr.find('.q-tn').val('').removeAttr('data-id').prop('readonly', false).removeClass('locked');
    syncRowIdTag($tr);
    $tr.find('.q-person').empty().append($('<option value="">選人員</option>'));
    if (v === 'user') {
        $tr.find('.q-outer').hide(); $tr.find('.q-inner').show();
        loadDepts($tr.find('.q-dept'));
    } else {
        $tr.find('.q-inner').hide(); $tr.find('.q-outer').show();
    }
});
$(document).on('input', '.q-tn', function () {
    var $inp = $(this), $tr = $inp.closest('tr'), type = $tr.find('.q-tt').val();
    $inp.removeAttr('data-id');       // 一改字就解除綁定，避免名字與 ID 對不起來
    syncRowIdTag($tr);
    if (!type || type === 'user') return;
    acSearch(type, $inp.val(), $inp, $tr.find('.q-ac'), function (row) {
        $inp.val(row.label).attr('data-id', row.id);
        syncRowIdTag($tr);
    });
});
/* 問題輸入列的綁定 ID 標籤＋鎖頭（與上面的編輯窗同一套規則） */
function syncRowIdTag($tr) {
    var id = $tr.find('.q-tn').attr('data-id') || '';
    var $t = $tr.find('.q-idtag').empty();
    if (id) {
        $tr.find('.q-tn').prop('readonly', true).addClass('locked');
        $t.removeClass('none').append(document.createTextNode('ID ' + id))
          .append($('<a href="javascript:;" class="idlock" title="解鎖並重新選擇對象">')
              .html('<i class="fa fa-lock"></i>')
              .on('click', function () {
                  $tr.find('.q-tn').val('').removeAttr('data-id').prop('readonly', false).removeClass('locked');
                  $tr.find('.q-tc').val('');
                  syncRowIdTag($tr); $tr.find('.q-tn').focus();
              }))
          .show();
    } else { $tr.find('.q-tn').prop('readonly', false).removeClass('locked'); $t.hide(); }
}
$(document).on('change', '.q-dept', function () {
    loadDeptPeople($(this).val(), $(this).closest('tr').find('.q-person'));
});

/* 部門與人員（走 people_lib：只列未離職、含兼任、依職稱由大到小） */
function loadDepts($sel, onDone) {
    var fill = function () {
        var keep = $sel.val();
        $sel.empty().append($('<option value="">選部門</option>'));
        $.each(DEPTS || [], function (i, d) { $sel.append($('<option>').val(d.id).text(d.name)); });
        if (keep) $sel.val(keep);
        if (onDone) onDone();
    };
    if (DEPTS) { fill(); return; }
    apiGet('dept_list').done(function (r) { DEPTS = (r.success ? r.rows : []) || []; fill(); });
}
function loadDeptPeople(deptId, $sel, keepId, onDone) {
    $sel.empty().append($('<option value="">選人員</option>'));
    if (!deptId) { if (onDone) onDone(); return; }
    apiGet('dept_people', { dept_id: deptId }).done(function (r) {
        if (r.success) {
            $.each(r.rows || [], function (i, p) {
                $('<option>').val(p.id).text(p.display).attr('data-name', p.label).appendTo($sel);
            });
        }
        if (keepId) $sel.val(String(keepId));
        if (onDone) onDone();
    });
}

/* ── 綁定 ─────────────────────────────────────────────────────────── */
$('#gBindType').on('change', function () {
    var t = $(this).val(), meta = DICT.bind_types[t] || {};
    $('#gBindKw').val('').removeAttr('data-id');
    $('#eBind').text('');
    if (Number(meta.manual)) {
        $('#gBindLbl').text('直接輸入單號');
        // 這兩種單目前是紙本、各自有自己的編號規則，不要在這裡示範格式誤導使用者
        $('#gBindKw').attr('data-hint', '請照紙本上的單號原樣輸入');
        $('#btnAddManual').show();
    } else {
        $('#gBindLbl').text('關鍵字');
        $('#gBindKw').attr('data-hint',
            t === 'customer' ? '可搜客戶名稱或客戶ID'
          : t === 'maker'    ? '可搜廠商名稱或編號'
          : t === 'part'     ? '可搜料號、圖號、客戶名稱'
                             : '單號、客戶名稱、客戶ID、料號都可以搜');
        $('#btnAddManual').hide();
    }
});
$('#gBindKw').on('input', function () {
    var t = $('#gBindType').val(), meta = DICT.bind_types[t] || {};
    if (Number(meta.manual)) return;
    acSearch(t, $(this).val(), $(this), $('#gBindAc'), function (row) {
        tryAddBind(t, String(row.id), row.label);
    });
});
$('#btnAddManual').on('click', function () {
    var t = $('#gBindType').val(), v = $.trim($('#gBindKw').val());
    if (!v) { $('#eBind').text('請輸入單號'); $('#gBindKw').focus(); return; }
    $('#eBind').text('');
    tryAddBind(t, v, v);
    apiGet('manual_no_check', { type: t, no: v, except: EDIT_ID }).done(function (r) {
        if (r.success && r.rows && r.rows.length) {
            alert('這個單號在另外 ' + r.rows.length + ' 筆紀錄也出現過：\n'
                + $.map(r.rows, function (x) { return x.log_no + '　' + x.title; }).join('\n'));
        }
    });
});

function tryAddBind(type, id, label) {
    for (var i = 0; i < BINDS.length; i++) {
        if (BINDS[i].bind_type === type && String(BINDS[i].bind_id) === String(id)) { $('#gBindKw').val(''); return; }
    }
    if ($.inArray(type, DICT.multipart) >= 0) {
        apiGet('carrier_parts', { type: type, id: id }).done(function (r) {
            if (!r.success) { alert(r.message); return; }
            var parts = r.parts || [];
            if (parts.length <= 1) {
                BINDS.push({ bind_type: type, bind_id: id, bind_label: label, sel_parts: null });
                $('#gBindKw').val(''); renderBinds(); return;
            }
            PICK_CTX = { type: type, id: id, label: label, parts: parts };
            $('#pickHead').html(esc((DICT.bind_types[type] || {}).name) + ' <b>' + esc(label) + '</b>　'
                + esc(r.head.client || '') + '　' + esc(dispDate(r.head.date))
                + '　共 <b>' + parts.length + '</b> 個料號');
            var $b = $('#pickBody').empty();
            $.each(parts, function (i, p) {
                $('<div class="pick-row">')
                    .append($('<span>').append($('<input type="checkbox" class="pk" checked>').val(p.d_id)))
                    .append($('<span class="pn">').text(p.part_no || ('d_id ' + p.d_id)))
                    .append($('<span class="qty">').text(p.qty != null ? p.qty : ''))
                    .appendTo($b);
            });
            openMask('pickMask');
        });
        return;
    }
    BINDS.push({ bind_type: type, bind_id: id, bind_label: label, sel_parts: null });
    $('#gBindKw').val('');
    renderBinds();
}
$('#pickAll').on('click', function () { $('#pickBody .pk').prop('checked', true); });
$('#pickNone').on('click', function () { $('#pickBody .pk').prop('checked', false); });
$('#btnPickOk').on('click', function () {
    if (!PICK_CTX) return;
    var sel = [];
    $('#pickBody .pk:checked').each(function () { sel.push(Number($(this).val())); });
    if (!sel.length) { alert('請至少勾選一個料號，否則這筆紀錄用料號查不回來。'); return; }
    BINDS.push({ bind_type: PICK_CTX.type, bind_id: PICK_CTX.id, bind_label: PICK_CTX.label,
                 sel_parts: (sel.length === PICK_CTX.parts.length ? null : sel) });
    PICK_CTX = null; $('#gBindKw').val('');
    closeMask('pickMask'); renderBinds();
});

function renderBinds() {
    var $b = $('#gBindBox').empty();
    if (!BINDS.length) $b.append($('<span style="font-size:12px;color:#B6BEC7;">尚未綁定任何對象</span>'));
    $.each(BINDS, function (i, x) {
        var meta = DICT.bind_types[x.bind_type] || {};
        var txt = x.bind_label || x.bind_id;
        if (x.sel_parts && x.sel_parts.length) txt += '（' + x.sel_parts.length + ' 個料號）';
        $('<span class="bind-badge">').addClass('bind-' + x.bind_type)
            .append($('<b>').text(meta.name || x.bind_type))
            .append(document.createTextNode(txt))
            .append($('<a href="javascript:;" title="移除">&times;</a>').on('click', function () {
                BINDS.splice(i, 1); renderBinds();
            }))
            .appendTo($b);
    });
    var hasCarrier = false, manualNames = [];
    $.each(BINDS, function (i, x) {
        if ($.inArray(x.bind_type, DICT.carrier) >= 0) hasCarrier = true;
        if (Number((DICT.bind_types[x.bind_type] || {}).manual)) manualNames.push((DICT.bind_types[x.bind_type] || {}).name);
    });
    if (manualNames.length && !hasCarrier) {
        $('#gBindWarn').html('<b>提醒：</b>這筆只填了' + esc(manualNames.join('、'))
            + '的單號，系統查不到它的料號與客戶，之後用<b>客戶／料號／廠商都搜尋不到這筆紀錄</b>。'
            + '建議一併綁定 BOM、料號、出貨單或退貨單其中之一。（確實對不到料號的話仍可儲存，清單上會標「未連結料號」）').show();
    } else $('#gBindWarn').hide();
}

/* 附件：上傳當下就送出（新增中先存 temp，儲存時轉正＝鐵律5） */
$('#gFile').on('change', function () { uploadFiles(this, TEMP_FILES, $('#gFileList'), EDIT_ID, 'log', EDIT_ID); });
$('#rFile').on('change', function () { uploadFiles(this, REP_TEMP_FILES, $('#rFileList'), 0, 'reply', 0); });

function uploadFiles(input, bucket, $list, logId, ownerType, ownerId) {
    var files = input.files;      // 送出時直讀 input.files（見記憶 file_upload_change_event）
    if (!files || !files.length) return;
    for (var i = 0; i < files.length; i++) {
        (function (f) {
            var fd = new FormData();
            fd.append('action', 'upload'); fd.append('csrf', CSRF); fd.append('file', f);
            fd.append('owner_type', ownerType);
            if (logId > 0 && ownerType === 'log') { fd.append('log_id', logId); fd.append('owner_id', ownerId || logId); }
            var $row = $('<div style="font-size:12px;color:#9AA5B1;margin-bottom:4px;">').text('上傳中… ' + f.name).appendTo($list);
            $.ajax({ url: API, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
                .done(function (r) {
                    if (!r.success) { $row.css('color', '#E74C3C').text(f.name + '：' + r.message); return; }
                    if (!(logId > 0 && ownerType === 'log')) bucket.push(r.id);
                    $row.empty().css('color', '#495057')
                        .append($('<span>').text(f.name))
                        .append($('<a href="javascript:;" style="margin-left:8px;color:#C4CBD3;">').text('移除')
                            .on('click', function () {
                                apiPost('file_delete', { id: r.id }).done(function (d) {
                                    if (!okOrAlert(d)) return;
                                    var k = $.inArray(r.id, bucket); if (k >= 0) bucket.splice(k, 1);
                                    $row.remove();
                                });
                            }));
                })
                .fail(function () { $row.css('color', '#E74C3C').text(f.name + '：上傳失敗'); });
        })(files[i]);
    }
    input.value = '';
}

$('#btnSaveLog').on('click', function () {
    $('#logMask .has-err').removeClass('has-err');
    var rv = $.trim($('#gRemindVal').val());
    var remind = rv === '' ? '' : (Number(rv) * Number($('#gRemindUnit').val()));

    var items = [], bad = '';
    if (EDIT_ID === 0) {
        $('#qInputBody tr').each(function (idx) {
            var $tr = $(this), q = $.trim($tr.find('.q-q').val());
            if (!q) return;
            var tt = $tr.find('.q-tt').val(), tid = '', tlabel = '';
            if (tt === 'user') {
                tid = $tr.find('.q-person').val() || '';
                tlabel = $tr.find('.q-person option:selected').attr('data-name') || '';
                if (!tid) bad = bad || ('第 ' + (idx + 1) + ' 條：公司內部要選到「人員」');
            } else if (tt) {
                tid = $tr.find('.q-tn').attr('data-id') || '';
                tlabel = $.trim($tr.find('.q-tn').val());
                if (!tid) bad = bad || ('第 ' + (idx + 1) + ' 條：對象要從清單點選（只打名字日後對應不到）');
            }
            var ad = $tr.find('.q-ad').val();
            if (ad && ad > TODAY) bad = bad || ('第 ' + (idx + 1) + ' 條：提出日期不可以是未來日期');
            items.push({ question: q, target_type: tt || '', target_id: tid, target_label: tlabel,
                         target_contact: $.trim($tr.find('.q-tc').val()), asked_at: ad });
        });
        if (bad) { alert(bad); return; }
    }

    var $btn = $(this).prop('disabled', true);
    apiPost('save_log', {
        id: EDIT_ID, title: $.trim($('#gTitle').val()), log_type: $('#gType').val(),
        visibility: $('#gVis').val(), deadline: $('#gDeadline').val(), remind_before_minutes: remind,
        urgent_days: $.trim($('#gUrgent').val()), note: $.trim($('#gNote').val()),
        binds: JSON.stringify(BINDS), items: JSON.stringify(items), temp_files: JSON.stringify(TEMP_FILES)
    }).done(function (r) {
        $btn.prop('disabled', false);
        if (!okOrAlert(r)) return;
        closeMask('logMask');
        EXPANDED[r.id] = null; delete EXPANDED[r.id];
        loadList(); loadStats(); loadFilterOptions();
        if (r.warning) alert('已儲存。\n\n' + r.warning);
    }).fail(function () { $btn.prop('disabled', false); });
});

/* ── 案件明細跳窗（完整檢視；日常填回覆在清單展開即可） ────────────── */
function openDetail(id) {
    apiGet('get', { id: id }).done(function (r) {
        if (!okOrAlert(r)) return;
        CUR = r; EXPANDED[id] = r;
        renderDetail(); openMask('detMask');
    });
}
function renderDetail() {
    var g = CUR.log, w = CUR.log.can_write && CAN_EDIT;
    $('#detNo').text(g.log_no + '　' + g.title);
    $('#detSub').text((DICT.log_types[g.log_type] || '未分類') + ' ／ ' + dispDate(g.created_at)
        + ' ／ ' + (g.owner_name || '') + ' ／ ' + (DICT.visibility[g.visibility] || ''));

    var $bd = $('#detBinds').empty();
    if (!(CUR.binds || []).length) $bd.append($('<span style="font-size:12px;color:#B6BEC7;">未綁定任何對象</span>'));
    $.each(CUR.binds || [], function (i, b) {
        var meta = DICT.bind_types[b.bind_type] || {};
        var txt = b.bind_label || b.bind_id;
        var sel = b.sel_parts ? JSON.parse(b.sel_parts) : null;
        if (sel && sel.length) txt += '（' + sel.length + ' 個料號）';
        $('<span class="bind-badge">').addClass('bind-' + b.bind_type)
            .append($('<b>').text(meta.name || b.bind_type)).append(document.createTextNode(txt)).appendTo($bd);
    });
    var done = 0;
    $.each(CUR.items, function (i, it) { if (it.status === 'answered' || it.status === 'resolved') done++; });
    $bd.append($('<span style="font-size:11.5px;color:#8A94A0;margin-left:8px;">')
        .text('已回 ' + done + ' / ' + CUR.items.length));

    if (g.note) $('#detNote').text('備註：' + g.note).show(); else $('#detNote').hide();
    if (CUR.unlinked) {
        $('#detUnlinked').html('<b>未連結料號：</b>這筆目前沒有連到任何料號，之後用客戶／料號／廠商都查不到它。'
            + '請編輯後綁定 BOM、料號、出貨單或退貨單。').show();
    } else $('#detUnlinked').hide();

    var $acts = $('#detItemActs').empty();
    if (w && g.status !== 'done') {
        $acts.append($('<button class="btn btn-xs btn-soft">').html('<i class="fa fa-plus"></i> 新增問題')
            .on('click', function () { openItem(null); }));
        if (CUR.items.length) {
            $acts.append($('<button class="btn btn-xs btn-soft" style="margin-left:6px;">')
                .html('<i class="fa fa-reply-all"></i> 一次回多條')
                .on('click', function () { openReply(null); }));
        }
    }

    var $w = $('#detItems').empty();
    if (!CUR.items.length) $w.append($('<div style="padding:16px;color:#9AA5B1;font-size:13px;">還沒有列出任何問題。</div>'));
    $.each(CUR.items, function (i, it) { $w.append(buildItemBlock(CUR, it, w && g.status !== 'done', false)); });

    var $f = $('#detFiles').empty();
    var logFiles = $.grep(CUR.files, function (x) { return x.owner_type === 'log'; });
    if (!logFiles.length) $f.append($('<span style="font-size:12px;color:#B6BEC7;">沒有案件層附件</span>'));
    $.each(logFiles, function (i, x) {
        $('<a class="att" target="_blank">').attr('href', API + '?action=download&id=' + x.id)
            .text('📎 ' + (x.original_name || x.file_name)).appendTo($f);
    });

    if (g.status === 'done') {
        $('#detConclusion').html('<b>結論：</b>' + esc(g.conclusion || '')
            + '　<span style="color:#9AA5B1;font-size:12px;">（' + esc(dispDate(g.closed_at)) + ' 結案）</span>').show();
    } else $('#detConclusion').hide();

    var $foot = $('#detFoot').empty();
    $foot.append($('<button class="btn btn-soft">').text('關閉').on('click', function () { closeMask('detMask'); }));
    if (w) {
        $foot.append($('<button class="btn btn-soft">').text('編輯').on('click', function () {
            closeMask('detMask'); openLogModal(CUR.log.id);
        }));
        if (g.status === 'done') {
            $foot.append($('<button class="btn btn-navy">').text('重新開啟').on('click', function () {
                apiPost('reopen_log', { id: CUR.log.id }).done(function (r) {
                    if (okOrAlert(r)) { openDetail(CUR.log.id); loadList(); loadStats(); }
                });
            }));
        } else {
            $foot.append($('<button class="btn btn-teal">').text('結案').on('click', function () {
                $('#cConclusion').val(''); $('#closeMask2 .has-err').removeClass('has-err');
                openMask('closeMask2');
            }));
        }
        $foot.append($('<button class="btn btn-soft" style="color:#E74C3C;">').text('刪除').on('click', function () {
            var _lid = CUR.log.id;
            confirmY('刪除整筆紀錄', CUR.log.log_no + '　' + CUR.log.title + '：底下的問題、回覆與附件都會一起刪除。', function () {
                apiPost('delete_log', { id: _lid }).done(function (r) {
                    if (okOrAlert(r)) { closeMask('detMask'); delete EXPANDED[_lid]; loadList(); loadStats(); loadFilterOptions(); }
                });
            });
        }));
    }
}

/* 問題項的更多動作 */
/* 問題項的更多動作：⋯ 點開後在回覆輸入框下方長出一排按鈕。
   刻意不用瀏覽器的 prompt()／confirm() 選單——那種對話框長相不受控、也很難看。 */
function buildItemActions($bar, d, it) {
    var lid = d.log.id;
    $bar.empty().css({ display: 'flex', flexWrap: 'wrap', gap: '6px', alignItems: 'center',
                       padding: '7px 9px', marginTop: '2px', background: '#F7F9FC',
                       border: '1px solid #E3E9F1', borderRadius: '6px' });
    $bar.append($('<span style="font-size:11.5px;color:#9AA5B1;margin-right:2px;">').text('第 ' + it.seq + ' 條：'));

    var btn = function (txt, icon, cls) {
        return $('<button type="button" class="btn btn-xs">').addClass(cls || 'btn-soft')
            .html('<i class="fa ' + icon + '"></i> ' + txt);
    };
    $bar.append(btn('編輯內容／對象', 'fa-pencil').on('click', function () { CUR = d; openItem(it); }));

    if (it.status !== 'resolved') {
        $bar.append(btn('標記已解決', 'fa-check', 'btn-teal').on('click', function () {
            askConclusion(it, function (c) {
                apiPost('item_status', { log_id: lid, id: it.id, status: 'resolved', conclusion: c })
                    .done(function (r) { if (okOrAlert(r)) { refreshExpanded(lid); loadStats(); } });
            });
        }));
    }
    if (it.status !== 'dropped') {
        $bar.append(btn('不處理', 'fa-ban').on('click', function () {
            askConclusion(it, function (c) {
                apiPost('item_status', { log_id: lid, id: it.id, status: 'dropped', conclusion: c })
                    .done(function (r) { if (okOrAlert(r)) { refreshExpanded(lid); loadStats(); } });
            });
        }));
    }
    if (it.status !== 'waiting') {
        $bar.append(btn('退回待回覆', 'fa-undo').on('click', function () {
            apiPost('item_status', { log_id: lid, id: it.id, status: 'waiting', conclusion: it.conclusion || '' })
                .done(function (r) { if (okOrAlert(r)) { refreshExpanded(lid); loadStats(); } });
        }));
    }
    if (it.status === 'waiting') {
        // 催過一次、對方說「再給我幾天」時用；不順延的話這一條不會再提醒
        var $d = $('<input type="number" class="form-control" min="1" max="365" value="3">')
            .css({ width: '58px', height: '24px', fontSize: '12px', padding: '2px 5px', display: 'inline-block' });
        $bar.append($('<span style="font-size:11.5px;color:#8A94A0;">').text('再等'))
            .append($d).append($('<span style="font-size:11.5px;color:#8A94A0;">').text('天'))
            .append(btn('順延提醒', 'fa-clock-o').on('click', function () {
                apiPost('item_snooze', { log_id: lid, id: it.id, days: Number($d.val()) })
                    .done(function (r) { if (okOrAlert(r)) refreshExpanded(lid); });
            }));
    }
    $bar.append($('<span style="flex:1 1 auto;">'));
    $bar.append(btn('刪除這一條', 'fa-trash-o').css('color', '#E74C3C').on('click', function () {
        confirmY('刪除第 ' + it.seq + ' 條問題', it.question, function () {
            apiPost('item_delete', { log_id: lid, id: it.id })
                .done(function (r) { if (okOrAlert(r)) { refreshExpanded(lid); loadStats(); loadList(); } });
        });
    }));
    $bar.append(btn('收起', 'fa-times').on('click', function () { $bar.hide(); }));
}
/* 結論：就地長出一個輸入列，不用 prompt */
function askConclusion(it, cb) {
    var $bar = $('.qitem[data-item="' + it.id + '"] .q-actions');
    $bar.empty().css({ display: 'flex', flexWrap: 'wrap', gap: '6px', alignItems: 'center',
                       padding: '7px 9px', background: '#F7F9FC', border: '1px solid #E3E9F1', borderRadius: '6px' });
    var $in = $('<input type="text" class="form-control">').val(it.conclusion || '')
        .css({ flex: '1 1 240px', height: '26px', fontSize: '12.5px' })
        .attr('data-hint', '這一條最後怎麼處理？可留空');
    $bar.append($('<span style="font-size:11.5px;color:#8A94A0;">').text('處理結果（可留空）：')).append($in);
    $bar.append($('<button type="button" class="btn btn-xs btn-teal">').text('確定')
        .on('click', function () { cb($.trim($in.val())); }));
    $bar.append($('<button type="button" class="btn btn-xs btn-soft">').text('取消')
        .on('click', function () { $bar.hide(); }));
    $in.focus();
}

/* ── 附件小圖 ─────────────────────────────────────────────────────────
   圖片直接出縮圖，點開在跳窗放大；跳窗上有「在新分頁開啟」可以拉成獨立分頁。
   非圖片（PDF/文件）維持一顆可點的檔名籤。 */
function isImgName(n) { return /\.(jpe?g|png|gif|webp|bmp)$/i.test(String(n || '')); }
function buildAttachStrip(files) {
    var $w = $('<span class="att-strip">');
    $.each(files, function (i, f) {
        var url = API + '?action=download&id=' + f.id;
        var name = f.original_name || f.file_name;
        if (isImgName(name)) {
            $('<img class="att-thumb">').attr('src', url).attr('alt', name).attr('title', name)
                .on('click', function (e) { e.stopPropagation(); openAttach(url, name, true); })
                .appendTo($w);
        } else {
            $('<a class="att" href="javascript:;">').text('📎 ' + name)
                .on('click', function (e) { e.stopPropagation(); openAttach(url, name, false); })
                .appendTo($w);
        }
    });
    return $w;
}
function openAttach(url, name, isImg) {
    $('#attTitle').text(name);
    $('#attNewTab').attr('href', url);
    var $b = $('#attBody').empty();
    if (isImg) $b.append($('<img>').attr('src', url).css({ maxWidth: '100%', maxHeight: 'calc(100vh - 220px)', display: 'block', margin: '0 auto' }));
    else $b.append($('<iframe>').attr('src', url).css({ width: '100%', height: 'calc(100vh - 220px)', border: 'none' }));
    openMask('attMask');
}

/* 單一問題項 新增／編輯 */
function openItem(it) {
    ITEM_EDIT = it;
    $('#itemMask .has-err').removeClass('has-err');
    $('#itemMTitle').text(it ? ('編輯第 ' + it.seq + ' 條問題') : '新增問題');
    $('#iQuestion').val(it ? it.question : '');
    $('#iContact').val(it ? (it.target_contact || '') : '');
    $('#iAsked').val(it ? (it.asked_at || TODAY) : TODAY);
    $('#iFollowUp').val(it ? (it.follow_up_days || '') : '');
    var tt = it ? (it.target_type || 'customer') : 'customer';
    $('#iTargetType').val(tt);
    $('#iTargetKw').val('').removeAttr('data-id');
    $('#iPerson').empty().append($('<option value="">選人員</option>'));
    applyItemTargetMode(tt);
    syncTargetIdTag();
    if (it && it.target_id) {
        if (tt === 'user') {
            // 回填時先把部門帶出來，再依 user_id 選回那個人
            loadDepts($('#iDept'), function () {
                findPersonDept(it.target_id, function (deptId) {
                    if (deptId) { $('#iDept').val(String(deptId)); loadDeptPeople(deptId, $('#iPerson'), it.target_id); }
                });
            });
        } else {
            $('#iTargetKw').val(it.target_label || '').attr('data-id', it.target_id);
            syncTargetIdTag();
        }
    } else if (tt === 'user') { loadDepts($('#iDept')); }
    openMask('itemMask');
}
function applyItemTargetMode(tt) {
    if (tt === 'user') { $('#iTargetOuter').hide(); $('#iTargetInner').show(); loadDepts($('#iDept')); }
    else { $('#iTargetInner').hide(); $('#iTargetOuter').show(); }
}
/* 依 user_id 找出他屬於哪些部門（用既有 dept_people 逐部門比對，不另開端點） */
function findPersonDept(userId, cb) {
    loadDepts($('#iDept'), function () {
        var list = (DEPTS || []).slice(), i = 0;
        (function next() {
            if (i >= list.length) { cb(null); return; }
            var d = list[i++];
            apiGet('dept_people', { dept_id: d.id }).done(function (r) {
                var hit = false;
                $.each((r.rows || []), function (k, p) { if (String(p.id) === String(userId)) hit = true; });
                if (hit) cb(d.id); else next();
            });
        })();
    });
}
$('#iTargetType').on('change', function () {
    $('#iTargetKw').val('').removeAttr('data-id');
    syncTargetIdTag();
    $('#iPerson').empty().append($('<option value="">選人員</option>'));
    applyItemTargetMode($(this).val());
});
$('#iDept').on('change', function () { loadDeptPeople($(this).val(), $('#iPerson')); });
/* 綁定 ID 標籤：選定後顯示在對象名稱右側；使用者一改文字就解除綁定、標籤跟著變紅字提示，
   這樣不會發生「名字看起來對、其實沒綁到任何 ID」的情況（使用者要求）。 */
function syncTargetIdTag() {
    var id = $('#iTargetKw').attr('data-id') || '';
    var $t = $('#iTargetIdTag').empty();
    if (id) {
        // 綁好之後把欄位鎖起來（使用者要求）：避免有人在名字後面多打幾個字，
        // 看起來還是綁著、其實文字已經和主檔對不起來。要換對象請按鎖頭。
        $('#iTargetKw').prop('readonly', true).addClass('locked');
        $t.removeClass('none').append(document.createTextNode('ID ' + id))
          .append($('<a href="javascript:;" class="idlock" title="解鎖並重新選擇對象">')
              .html('<i class="fa fa-lock"></i>')
              .on('click', function () {
                  $('#iTargetKw').val('').removeAttr('data-id').prop('readonly', false).removeClass('locked');
                  $('#iContact').val('');       // 聯絡人是跟著對象走的，一併清掉
                  syncTargetIdTag(); $('#iTargetKw').focus();
              }))
          .show();
    } else {
        $('#iTargetKw').prop('readonly', false).removeClass('locked');
        if ($.trim($('#iTargetKw').val()) !== '') $t.addClass('none').text('未綁定 ID').show();
        else $t.hide();
    }
}
$('#iTargetKw').on('input', function () {
    var t = $('#iTargetType').val(), $inp = $(this);
    $inp.removeAttr('data-id');
    syncTargetIdTag();
    if (t === 'user') return;
    acSearch(t, $inp.val(), $inp, $('#iTargetAc'), function (row) {
        $inp.val(row.label).attr('data-id', row.id);
        syncTargetIdTag();
    });
});
$('#btnSaveItem').on('click', function () {
    $('#itemMask .has-err').removeClass('has-err');
    var q = $.trim($('#iQuestion').val());
    if (!q) { $('#iQuestion').closest('.fld').addClass('has-err'); $('#eIQuestion').text('請填寫問題內容'); return; }
    var tt = $('#iTargetType').val(), tid = '', tlabel = '';
    if (tt === 'user') {
        tid = $('#iPerson').val() || '';
        tlabel = $('#iPerson option:selected').attr('data-name') || '';
        if (!tid) { $('#iPerson').closest('.fld').addClass('has-err'); $('#eIPerson').text('請選擇人員'); return; }
    } else {
        tid = $('#iTargetKw').attr('data-id') || '';
        tlabel = $.trim($('#iTargetKw').val());
        if (!tid) {
            $('#iTargetKw').closest('.fld').addClass('has-err');
            $('#eITarget').text('請從清單點選對象（只打名字的話日後對方改名就對應不到）');
            return;
        }
    }
    var ad = $('#iAsked').val();
    if (ad && ad > TODAY) { $('#iAsked').closest('.fld').addClass('has-err'); $('#eIAsked').text('提出日期不可以是未來日期'); return; }
    var lid = CUR.log.id;
    apiPost('item_save', {
        log_id: lid, id: ITEM_EDIT ? ITEM_EDIT.id : 0, question: q,
        target_type: tt, target_id: tid, target_label: tlabel,
        target_contact: $.trim($('#iContact').val()), asked_at: ad,
        follow_up_days: $.trim($('#iFollowUp').val())
    }).done(function (r) {
        if (!okOrAlert(r)) return;
        closeMask('itemMask'); refreshExpanded(lid); loadStats(); loadList(); loadFilterOptions();
    });
});

/* ── 一次回多條（明細跳窗內） ─────────────────────────────────────── */
function openReply(itemIds) {
    REP_TEMP_FILES = []; $('#rFileList').empty(); $('#rFile').val('');
    $('#rContent').val(''); $('#rBy').val(''); $('#rDate').val(TODAY); $('#rChannel').val('phone');
    $('#repMask .has-err').removeClass('has-err');
    var $w = $('#repItems').empty();
    var pending = $.grep(CUR.items, function (x) { return x.status === 'waiting' || x.status === 'answered'; });
    var show = pending.length ? pending : CUR.items;
    $.each(show, function (i, it) {
        var checked = itemIds ? ($.inArray(it.id, itemIds) >= 0) : false;
        $('<div class="pick-row" style="grid-template-columns:1.7rem minmax(0,1fr) 6rem;">')
            .append($('<span>').append($('<input type="checkbox" class="rp">').val(it.id).prop('checked', checked)))
            .append($('<span style="font-size:12.5px;">').text(it.seq + '. ' + it.question))
            .append($('<span style="font-size:11.5px;color:#9AA5B1;text-align:right;">').text(it.target_label || ''))
            .appendTo($w);
    });
    $('#repSub').text('共 ' + show.length + ' 條可勾選');
    openMask('repMask');
}
$('#btnSaveReply').on('click', function () {
    $('#repMask .has-err').removeClass('has-err');
    var ids = [];
    $('#repItems .rp:checked').each(function () { ids.push(Number($(this).val())); });
    if (!ids.length) { alert('請至少勾選一條問題'); return; }
    var content = $.trim($('#rContent').val());
    if (!content) { $('#rContent').closest('.fld').addClass('has-err'); $('#eRContent').text('請填寫回覆內容'); return; }
    var d = $('#rDate').val();
    if (d && d > TODAY) { $('#rDate').closest('.fld').addClass('has-err'); $('#eRDate').text('回覆日期不可以是未來日期'); return; }

    var $btn = $(this).prop('disabled', true);
    var lid = CUR.log.id;
    apiPost('reply_add', { log_id: lid, item_ids: JSON.stringify(ids), replied_on: d,
        reply_by: $.trim($('#rBy').val()), channel: $('#rChannel').val(), content: content,
        temp_files: JSON.stringify(REP_TEMP_FILES) })
        .done(function (r) {
            $btn.prop('disabled', false);
            if (!okOrAlert(r)) return;
            closeMask('repMask'); refreshExpanded(lid); loadList(); loadStats();
        }).fail(function () { $btn.prop('disabled', false); });
});

/* ── 結案 ─────────────────────────────────────────────────────────── */
$('#btnDoClose').on('click', function () {
    var c = $.trim($('#cConclusion').val());
    $('#closeMask2 .has-err').removeClass('has-err');
    if (!c) { $('#cConclusion').closest('.fld').addClass('has-err'); $('#eCConclusion').text('請填寫結論'); return; }
    apiPost('close_log', { id: CUR.log.id, conclusion: c }).done(function (r) {
        if (!okOrAlert(r)) return;
        closeMask('closeMask2'); openDetail(CUR.log.id); loadList(); loadStats();
    });
});

/* ── 雜項 ─────────────────────────────────────────────────────────── */
$('#btnPageHelp').on('click', function () { openMask('helpUseMask'); });
$('#btnRoleHelp').on('click', function () { openMask('roleMask'); });
$(window).on('scroll', function () { $('#btnTop').toggle($(window).scrollTop() > 300); });
$('#btnTop').on('click', function () { $('html,body').animate({ scrollTop: 0 }, 200); });
$(document).on('keydown', function (e) { if (e.which === 27) $('.el-mask.on').last().removeClass('on'); });
</script>
</body>
</html>
