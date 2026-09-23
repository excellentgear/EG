<?php
/**
 * 工程變更申請／審查／通知單（2-TD-01-01）
 * -----------------------------------------------------------------------------
 * 紙本流程：申請單位 ↓ 倉管 ↓ 技術 ↓ 其他單位(僅需會審者) ↓ 技術
 *   申請人 → 單位主管 → 倉管組(確認庫存) → 技術課(設計分析) → 核准(核示)
 *   → [技術課判定需會審時] 生產課／品保課／倉管組／生管組／採購組／業務課 會審 → 管制員 → 結案
 *
 * 資料一律走 src/store/EngChange_API.php；共用邏輯 src/common/eng_change_lib.php。
 * 列印為 A4 直式 1:1（@page size:A4 portrait; margin:0），版面以 mm 定寸，避免縮放讓圖章失真。
 * 簽章一律走 eg_stamp.js（ai-rules/18）；解析人與職稱依本單日期回推當時職務（ai-rules/22）。
 *
 * 自動產生：圖面變更紀錄（views/QC/drawing_change_log.php）送出且「變更來源＝客戶」時，
 *           後端自動在這裡建一張草稿（見 dwg_submit_change → ec_auto_from_dwg_change）。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/TD/eng_change.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/eng_change_lib.php';

$db = (new DBConnection())->getPDO();
ec_ensure_schema($db);
$ecUser = ec_current_user($db);
$P = ec_perms($db, $ecUser);
/* 角色說明一律**即時查現況**組出來（鐵律4）：管理員把角色改名或刪掉之後，
   寫死的說明文字會繼續顯示舊內容而且不會報錯，只能靠事後才發現。 */
$roleRows = [];
try {
    $roleRows = $db->query("SELECT role_id, role_code, role_name, note, is_system FROM roles
                             WHERE module='eng_change' OR (role_code='admin' AND is_system=1)
                             ORDER BY is_system DESC, role_id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
/* 本人實際被指派到的角色名稱（可能不只一個）；用實際指派而不是拿權限高低猜一個字，
   這樣即使角色被改名，這裡顯示的永遠是真的存在的名稱。 */
$myRoleNames = [];
if ($ecUser) {
    try {
        $st = $db->prepare("SELECT DISTINCT r.role_name FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                             WHERE ur.user_id=? AND (r.module='eng_change' OR (r.role_code='admin' AND r.is_system=1))");
        $st->execute([(int)$ecUser['id']]);
        $myRoleNames = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {}
}
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
    <title>工程變更申請單</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; }
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px;
            background:#F0A24B; color:#fff; cursor:pointer; }
        .page-help-btn:hover { background:#d98a33; }
        @media print { .page-help-btn { display:none !important; } }
        .help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc b { color:#8A5A2B; }
        .help-doc ul { margin:4px 0 8px; padding-left:20px; }
        .help-doc li { margin:2px 0; }
        .help-doc .tip { background:#FFF7E8; border:1px dashed #F0A24B; border-radius:6px; padding:6px 10px; margin:6px 0; }

        /* 暖色系（ai-rules/10）：淺底深棕字、深底白字 */
        .ec-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .ec-toolbar label { margin:0 2px 0 6px; font-size:12px; color:#7A4A12; font-weight:600; }
        .ec-toolbar .form-control { height:30px; font-size:13px; width:auto; display:inline-block; }
        .ec-btn { height:30px; font-size:13px; padding:0 12px; border-radius:4px; border:1px solid #d98a33;
            background:#F0A24B; color:#fff; cursor:pointer; }
        .ec-btn:hover { background:#d98a33; }
        .ec-btn.ghost { background:#fff; color:#8A5A2B; }
        .ec-btn.ghost:hover { background:#F7E0BD; }
        .ec-btn[disabled] { opacity:.5; cursor:not-allowed; }
        table.ec-list { width:100%; border-collapse:collapse; font-size:13px; background:#fff; }
        table.ec-list th { background:#8A5A2B; color:#fff; padding:6px 8px; font-weight:600; white-space:nowrap; }
        table.ec-list td { border-bottom:1px solid #EFE3D0; padding:5px 8px; vertical-align:middle; }
        table.ec-list tr:hover td { background:#FFF7E8; }
        .pill { display:inline-block; font-size:11px; border-radius:10px; padding:1px 8px; white-space:nowrap; }
        .pill.draft { background:#EFE7DC; color:#8A6A45; }
        .pill.wait  { background:#F7E0BD; color:#7A4A12; }
        .pill.done  { background:#8A5A2B; color:#fff; }
        .pill.rej   { background:#DD5138; color:#fff; }
        .pill.todo  { background:#F0A24B; color:#fff; }
        .ec-noperm { border:1.5px solid #E8D5B5; border-radius:8px; background:#FDF8EF; padding:24px; color:#7A4A12; }

        .ec-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; overflow:auto; padding:24px 12px; }
        .ec-mask.on { display:block; }
        .ec-modal { background:#fff; border-radius:8px; max-width:900px; margin:0 auto; box-shadow:0 8px 30px rgba(0,0,0,.3); }
        .ec-modal.xwide { max-width:1100px; }
        .ec-modal.narrow { max-width:560px; }
        .ec-modal .m-head { background:#8A5A2B; color:#fff; padding:9px 14px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; align-items:center; font-size:15px; }
        .ec-modal .m-close { cursor:pointer; font-size:18px; line-height:1; }
        .ec-modal .m-body { padding:14px 16px; max-height:74vh; overflow:auto; }
        .ec-modal .m-foot { padding:9px 14px; border-top:1px solid #EFE3D0; text-align:right; }
        .b-ok { height:32px; padding:0 16px; border-radius:4px; border:1px solid #d98a33; background:#F0A24B; color:#fff; cursor:pointer; }
        .b-ok:hover { background:#d98a33; }

        .fgrid { display:grid; grid-template-columns:repeat(4,1fr); gap:8px 12px; }
        .fgrid .full { grid-column:1/-1; }
        .fgrid .half { grid-column:span 2; }
        .fld label { display:block; font-size:12px; color:#7A4A12; font-weight:600; margin-bottom:2px; }
        .fld .form-control { height:30px; font-size:13px; }
        .fld textarea.form-control { height:auto; }
        .fld .err { color:#DD5138; font-size:11px; margin-top:2px; display:none; }
        .fld.bad .form-control { border-color:#DD5138; background:#FFF3F0; }
        .fld.bad .err { display:block; }
        .sec { border:1px solid #E8D5B5; border-radius:6px; margin:10px 0; }
        .sec > .sh { background:#F7E0BD; color:#7A4A12; font-weight:700; font-size:13px; padding:5px 10px; border-radius:5px 5px 0 0; }
        .sec > .sb { padding:10px; }
        .ro-auto { background:#F5F0E8; color:#6b5540; }
        .chk { display:block; font-size:13px; color:#5b3a1e; margin:3px 0; font-weight:normal; cursor:pointer; }
        .chk input { margin-right:5px; }
        .stage-flow { display:flex; flex-wrap:wrap; gap:4px; align-items:center; font-size:12px; margin-bottom:8px; }
        .stage-flow .s { border:1px solid #E8D5B5; border-radius:12px; padding:1px 10px; background:#fff; color:#8A6A45; }
        .stage-flow .s.on { background:#F0A24B; color:#fff; border-color:#d98a33; font-weight:700; }
        .stage-flow .s.ok { background:#8A5A2B; color:#fff; border-color:#8A5A2B; }
        .stage-flow .arw { color:#C9B79A; }
        .rv-card { border:1px solid #E8D5B5; border-radius:6px; padding:8px 10px; margin-bottom:8px; background:#FDF8EF; }
        .rv-card.skip { opacity:.55; }
        .rv-card .rh { font-weight:700; color:#7A4A12; font-size:13px; margin-bottom:4px; display:flex; align-items:center; gap:8px; }
        /* 綁定圖示：選了料號當下就要看得到「已經綁上主檔了」（暖色系，淺底深棕字） */
        .bind-tag { display:inline-block; font-size:11px; line-height:16px; border-radius:9px; padding:0 8px;
            background:#F7E0BD; color:#7A4A12; border:1px solid #E4D3BC; margin-left:6px; font-weight:normal; }
        .bind-tag.bad { background:#FFF3F0; color:#DD5138; border-color:#F3C4BB; }

        /* 附件區：一個標籤一列（同一個標籤只能挑一個檔案） */
        .att-box { border:1px solid #E8D5B5; border-radius:6px; background:#FDF8EF; padding:6px 8px; min-height:34px; }
        .att-row { display:flex; align-items:center; gap:6px; padding:3px 0; border-bottom:1px dashed #EFE3D0; font-size:12px; }
        .att-row:last-child { border-bottom:0; }
        .att-cat { color:#7A4A12; font-weight:700; white-space:nowrap; }
        .att-file { flex:1; color:#5b3a1e; word-break:break-all; }
        .att-file .none { color:#aaa; }
        .att-seq { display:inline-block; background:#8A5A2B; color:#fff; border-radius:9px;
            font-size:10px; line-height:16px; padding:0 7px; margin-right:4px; }
        .att-mini { height:22px; padding:0 8px; font-size:11px; border-radius:3px; border:1px solid #d98a33;
            background:#fff; color:#8A5A2B; cursor:pointer; white-space:nowrap; }
        .att-mini:hover { background:#F7E0BD; }
        .att-mini.del { border-color:#DD5138; color:#DD5138; }
        .att-empty { color:#aaa; font-size:12px; }
        /* 挑檔案清單／PDF 頁縮圖 */
        .att-f { display:flex; align-items:center; gap:8px; border:1px solid #EFE3D0; border-radius:5px;
            padding:5px 8px; margin-bottom:5px; background:#fff; font-size:12px; }
        .att-f:hover { background:#FFF7E8; }
        .att-f .nm { flex:1; color:#5b3a1e; word-break:break-all; }
        .att-f .mt { color:#8a6d45; font-size:11px; white-space:nowrap; }
        .att-pages { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:8px; }
        .att-pg { border:1.5px solid #E8D5B5; border-radius:5px; padding:4px; background:#fff; cursor:pointer; text-align:center; }
        .att-pg:hover { border-color:#F0A24B; background:#FFF7E8; }
        .att-pg canvas { width:100%; height:auto; display:block; border:1px solid #EFE3D0; }
        .att-pg .pn { font-size:11px; color:#7A4A12; margin-top:2px; }
        .cat-pick { border:1px solid #EFE3D0; border-radius:4px; background:#fff; padding:5px;
            max-height:160px; overflow:auto; font-size:12px; }
        .hist { font-size:12px; border-collapse:collapse; width:100%; }
        .hist th { background:#F7E0BD; color:#7A4A12; padding:4px 6px; }
        .hist td { border-bottom:1px solid #EFE3D0; padding:4px 6px; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">工程變更申請單
                <small style="color:#8a6d45;">2-TD-01-01　申請／審查／通知</small></h2>
            <span style="margin-left:12px;font-size:12px;color:#7A4A12;background:#F7E0BD;border:1px solid #E4D3BC;
                         border-radius:12px;padding:2px 10px;">
                目前角色：<?= htmlspecialchars($roleLabel) ?>
                <a href="javascript:;" id="btnRoleHelp" title="各角色權限說明"
                   style="color:#8A5A2B;margin-left:4px;"><i class="fa fa-question-circle"></i></a>
            </span>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$P['canView'] && !$P['canEdit']): ?>
        <div class="ec-noperm">
            <h4><i class="fa fa-lock"></i> 無工程變更申請單權限</h4>
            <p>請洽系統管理員於「使用者權限設定 → 工程變更申請單」開通角色（申請／檢閱／管理員）。</p>
        </div>
<?php else: ?>
        <div class="ec-toolbar">
            <input type="text" id="f-kw" class="form-control" style="width:220px;"
                   placeholder="搜尋文件編號／料號／客戶／申請人／內容…">
            <label>狀態</label>
            <select id="f-status" class="form-control" data-eg-skip>
                <option value="">全部</option>
                <option value="DRAFT">草稿</option>
                <option value="SUP">待單位主管</option>
                <option value="WH">待倉管組</option>
                <option value="TD">待技術課</option>
                <option value="APPROVE">待核准</option>
                <option value="REVIEW">會審中</option>
                <option value="CTRL">待管制員</option>
                <option value="CLOSED">已結案</option>
                <option value="REJECTED">已退回</option>
            </select>
            <label style="font-weight:normal;"><input type="checkbox" id="f-mine"> 只看我開的</label>
            <label style="font-weight:normal;"><input type="checkbox" id="f-todo"> 只看待我簽</label>
            <button class="ec-btn ghost" id="btnSearch"><i class="fa fa-search"></i> 查詢</button>
            <span style="flex:1"></span>
            <?php if ($P['canEdit']): ?>
            <button class="ec-btn" id="btnNew"><i class="fa fa-plus"></i> 開立申請單</button>
            <?php endif; ?>
            <?php if ($P['canAdmin']): ?>
            <button class="ec-btn ghost" id="btnSetting"><i class="fa fa-cog"></i> 設定</button>
            <?php endif; ?>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
            <span id="listInfo" style="font-size:12px;color:#8a6d45;"></span>
            <span id="pager" style="font-size:12px;"></span>
        </div>
        <div style="overflow-x:auto;">
        <table class="ec-list">
            <thead><tr>
                <th style="width:34px;"><input type="checkbox" id="chkAll"></th>
                <th>文件編號</th><th>日期</th><th>客戶</th><th>料號</th>
                <th>申請單位</th><th>申請人</th><th>變更方式</th><th>狀態</th><th style="width:150px;">操作</th>
            </tr></thead>
            <tbody id="listBody"><tr><td colspan="10" style="text-align:center;color:#aaa;padding:20px;">載入中…</td></tr></tbody>
        </table>
        </div>
        <div style="text-align:right;margin-top:8px;">
            <button class="ec-btn ghost" id="btnPrintSel"><i class="fa fa-print"></i> 列印勾選</button>
            <?php if ($P['canAdmin']): ?>
            <button class="ec-btn ghost" id="btnDelSel" style="border-color:#DD5138;color:#DD5138;"><i class="fa fa-trash"></i> 刪除勾選</button>
            <?php endif; ?>
        </div>
<?php endif; ?>
    </div>
</div>
</div>

<!-- ══════════ 明細／編輯 ══════════ -->
<div class="ec-mask" id="ecMask"><div class="ec-modal xwide">
    <div class="m-head"><span id="ecTitle">工程變更申請單</span><span class="m-close" onclick="closeMask('ecMask')">✕</span></div>
    <div class="m-body">
        <div class="stage-flow" id="stageFlow"></div>
        <div id="preHint" style="display:none;border:1px solid #E8D5B5;background:#FFF7E8;border-radius:6px;
             padding:6px 10px;margin-bottom:8px;color:#7A4A12;font-size:12px;"></div>
        <div id="rejBox" style="display:none;border:1px solid #DD5138;background:#FFF3F0;border-radius:6px;padding:8px 10px;margin-bottom:8px;color:#8a2b1a;font-size:13px;"></div>

        <!-- 表頭 -->
        <div class="sec"><div class="sh">申請內容</div><div class="sb">
            <div class="fgrid">
                <div class="fld"><label>文件編號</label>
                    <input type="text" id="e_doc_no" class="form-control ro-auto" readonly data-eg-skip></div>
                <div class="fld"><label>日期 <span style="color:#DD5138">*</span></label>
                    <input type="date" id="e_apply_date" class="form-control"><div class="err"></div></div>
                <div class="fld half"><label>料號 <span style="color:#DD5138">*</span>
                        <small style="font-weight:normal;color:#aaa;">（選了自動帶客戶）</small>
                        <span id="e_part_bind" class="bind-tag" style="display:none;"></span></label>
                    <input type="text" id="e_part_kw" class="form-control" placeholder="輸入料號或客戶關鍵字後選擇…" list="partList">
                    <datalist id="partList"></datalist><div class="err"></div></div>
                <div class="fld half"><label>客戶名稱 <span style="color:#DD5138">*</span>
                        <small style="font-weight:normal;color:#aaa;">（由料號綁定產生，不可修改）</small>
                        <span id="e_cust_bind" class="bind-tag" style="display:none;"></span></label>
                    <input type="text" id="e_customer" class="form-control ro-auto" readonly data-eg-skip>
                    <div class="err"></div></div>
                <div class="fld half"><label>申請人 <span style="color:#DD5138">*</span>
                        <small style="font-weight:normal;color:#aaa;" id="e_applicant_hint"></small></label>
                    <select id="e_applicant" class="form-control" data-eg-filter="輸入姓名或部門篩選…"></select>
                    <input type="text" id="e_applicant_ro" class="form-control ro-auto" readonly data-eg-skip style="display:none;">
                    <div class="err"></div></div>
                <div class="fld half"><label>申請職務（部門／職稱） <span style="color:#DD5138">*</span>
                        <small style="font-weight:normal;color:#aaa;">（有兼任時請選要用哪個身分申請）</small></label>
                    <select id="e_post" class="form-control" data-eg-filter="輸入部門或職稱篩選…"></select>
                    <div class="err"></div></div>
                <div class="fld half"><label>變更方式 <span style="color:#DD5138">*</span></label>
                    <div id="e_ctype"></div><div class="err"></div></div>
                <!-- 使用者要求 2026-09-23：變更方式右側的空白處放「挑料號附件」，
                     可挑哪些標籤由管理員在設定裡指定，附件規則（必選／可選／不可選）隨變更方式而不同。 -->
                <div class="fld half" id="attApplyWrap"><label>附件
                        <small style="font-weight:normal;color:#aaa;" id="attApplyRule"></small></label>
                    <div class="att-box" id="attApplyBox"></div><div class="err"></div></div>
                <div class="fld full"><label>設變事由說明
                        <small style="font-weight:normal;color:#aaa;">（僅「其他變更」須填寫；請簡述變更原因，例：生產課因架機需求提出變更…）</small></label>
                    <textarea id="e_reason" class="form-control" rows="3"></textarea>
                    <div id="e_reason_lock" style="display:none;font-size:11px;color:#8a6d45;margin-top:2px;">
                        目前的變更方式不需要填寫設變事由說明（僅「其他變更」須填寫）。</div>
                    <div class="err"></div></div>
            </div>
        </div></div>

        <!-- 倉管：確認庫存 -->
        <div class="sec" data-stage="WH"><div class="sh">確認庫存（倉管組）</div><div class="sb">
            <div class="fgrid">
                <div class="fld half"><label>庫存數量</label>
                    <input type="text" id="e_stock_qty" class="form-control">
                    <div id="e_stock_sys" style="font-size:11px;color:#8a6d45;margin-top:2px;"></div>
                    <div class="err"></div></div>
                <div class="fld half"><label>已完工待入庫數量</label>
                    <input type="text" id="e_wip_qty" class="form-control">
                    <div id="e_wip_sys" style="font-size:11px;color:#8a6d45;margin-top:2px;"></div>
                    <div class="err"></div></div>
                <div class="fld full" style="margin-top:-4px;">
                    <button class="ec-btn ghost" id="btnStockReload" style="height:24px;padding:0 10px;font-size:12px;display:none;">
                        <i class="fa fa-refresh"></i> 重新帶入系統數量</button>
                    <span style="font-size:11px;color:#aaa;margin-left:6px;">系統數量僅供比對，實際以倉管清點為準，可直接改上面的欄位。</span>
                </div>
            </div>
        </div></div>

        <!-- 技術：設計分析 -->
        <div class="sec" data-stage="TD"><div class="sh">設計分析（技術課）</div><div class="sb">
            <div class="fld"><label>更新圖面需附上</label><div id="e_design"></div><div class="err"></div></div>
            <!-- 使用者要求 2026-09-23：選了「更新圖面需附上」的任一結果，就一定要挑附件；
                 可挑多個標籤，但同一個標籤只能挑一個檔案，PDF 可以整份多頁不必指定頁。 -->
            <div class="fld" style="margin-top:6px;" id="attDesignWrap"><label>附件（更新圖面需附上）
                    <small style="font-weight:normal;color:#aaa;" id="attDesignHint"></small></label>
                <div class="att-box" id="attDesignBox"></div><div class="err"></div></div>
            <!-- 單一製程＝不必經過倉管確認庫存（使用者要求 2026-09-23）；勾了之後送簽自動略過倉管那一關，
                 庫存舊料這一題也一併不必判定（同一件事：既然不需要確認庫存，庫存舊料能不能修改也就無所謂）。 -->
            <div class="fld" style="margin-top:6px;"><label>製程型態</label>
                <label class="chk"><input type="checkbox" id="e_single_process"> 單一製程（不需確認庫存）
                    <small style="color:#8a6d45;">勾選後<b>不經過倉管組確認庫存</b>，送簽時自動略過那一關、也不會通知倉管。</small></label></div>
            <div class="fld" style="margin-top:6px;"><label>庫存舊料</label><div id="e_oldstock"></div>
                <div id="e_oldstock_na" style="display:none;color:#8a6d45;font-size:12px;">
                    （單一製程不需確認庫存，此項不需確認）</div>
                <div class="err"></div></div>
            <div class="fld" style="margin-top:6px;"><label>設計分析補充</label>
                <textarea id="e_design_note" class="form-control" rows="2"></textarea></div>
            <div id="e_review_pick" style="margin-top:10px;display:none;border:1px solid #E8D5B5;
                 border-radius:6px;padding:8px 10px;background:#FFF7E8;">
                <label style="font-size:12px;color:#7A4A12;font-weight:600;margin-bottom:2px;">
                    需要哪些單位會審　<small style="font-weight:normal;color:#8a6d45;">（由技術課決定，可複選）</small></label>
                <div id="e_review_hint" style="font-size:11px;color:#8a6d45;margin-bottom:4px;"></div>
                <div id="e_review_units"></div>
                <div id="e_review_err" style="font-size:11px;color:#DD5138;margin-top:3px;display:none;">
                    選了「需修改圖面與會審」就要勾選至少一個會審單位</div>
            </div>
        </div></div>

        <!-- 核示 -->
        <div class="sec" data-stage="APPROVE"><div class="sh">核示</div><div class="sb">
            <div class="fld"><label>核示結果</label><div id="e_verdict"></div><div class="err"></div></div>
            <div class="fld" style="margin-top:6px;display:none;" id="e_verdict_other_wrap"><label>其他（請說明）</label>
                <input type="text" id="e_verdict_other" class="form-control"><div class="err"></div></div>
            <div class="fld" style="margin-top:6px;"><label>補充意見</label>
                <textarea id="e_verdict_note" class="form-control" rows="2"></textarea></div>
        </div></div>

        <!-- 會審 -->
        <div class="sec" id="reviewSec" data-stage="REVIEW"><div class="sh">相關單位會審</div><div class="sb" id="reviewBody"></div></div>

        <!-- 管制 -->
        <div class="sec" data-stage="CTRL"><div class="sh">管制（技術課）</div><div class="sb">
            <div class="fld"><label>需修改文件資料</label>
                <!-- 「圖面」固定勾選不給取消（使用者要求 2026-09-23）：工程變更一定會動到圖面。
                     disabled 的勾選框不會送出，所以後端 ec_normalize_stage_fields() 會再強制一次。 -->
                <label class="chk"><input type="checkbox" id="e_ctrl_drawing" checked disabled> 圖面
                    <small style="color:#8a6d45;">（固定勾選，不可取消）</small></label>
                <label class="chk"><input type="checkbox" id="e_ctrl_bom"> BOM</label>
                <label class="chk"><input type="checkbox" id="e_ctrl_manual"> 操作手冊</label>
                <div class="err"></div></div>
        </div></div>

        <div class="sec"><div class="sh">簽核紀錄</div><div class="sb">
            <!-- 使用者要求 2026-09-23：簽核紀錄不顯示「送出人」 -->
            <table class="hist"><thead><tr><th style="width:110px;">關卡</th><th style="width:70px;">狀態</th>
                <th>簽核人</th><th style="width:130px;">簽核時間</th><th>意見</th>
                <th style="width:70px;" class="only-admin">操作</th></tr></thead>
            <tbody id="histBody"></tbody></table>
        </div></div>
    </div>
    <div class="m-foot" style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;">
        <span id="ecHint" style="flex:1;text-align:left;font-size:12px;color:#8a6d45;align-self:center;"></span>
        <button class="ec-btn ghost" id="btnEcPrint"><i class="fa fa-print"></i> 列印</button>
        <button class="ec-btn ghost" id="btnEcPrintAtt" style="display:none;"><i class="fa fa-paperclip"></i> 列印所有附件</button>
<?php if ($P['canAdmin']): ?>
        <button class="ec-btn ghost" id="btnEcBulk" style="display:none;"><i class="fa fa-stamp"></i> 一次代簽全部</button>
<?php endif; ?>
        <button class="ec-btn ghost" id="btnEcDel" style="display:none;border-color:#DD5138;color:#DD5138;"><i class="fa fa-trash"></i> 刪除</button>
        <button class="ec-btn ghost" id="btnEcSave" style="display:none;"><i class="fa fa-save"></i> 儲存草稿</button>
        <button class="ec-btn" id="btnEcSubmit" style="display:none;"><i class="fa fa-paper-plane"></i> 送出</button>
        <button class="ec-btn ghost" id="btnEcReject" style="display:none;border-color:#DD5138;color:#DD5138;">退回</button>
        <button class="ec-btn" id="btnEcSign" style="display:none;"><i class="fa fa-check"></i> 簽核</button>
        <button class="b-ok" onclick="closeMask('ecMask')">關閉</button>
    </div>
</div></div>

<!-- ══════════ 退回原因 ══════════ -->
<div class="ec-mask" id="rejMask"><div class="ec-modal narrow">
    <div class="m-head"><span>退回</span><span class="m-close" onclick="closeMask('rejMask')">✕</span></div>
    <div class="m-body">
        <div class="fld"><label>退回原因 <span style="color:#DD5138">*</span>
                <small style="font-weight:normal;color:#aaa;">（會一併通知申請人，請寫清楚要改什麼）</small></label>
            <textarea id="rejReason" class="form-control" rows="4"></textarea><div class="err">請填寫退回原因</div></div>
    </div>
    <div class="m-foot"><button class="b-ok" id="btnRejOk">確定退回</button></div>
</div></div>

<!-- ══════════ 挑選料號附件 ══════════ -->
<div class="ec-mask" id="attMask"><div class="ec-modal xwide">
    <div class="m-head"><span id="attTitle">挑選附件</span><span class="m-close" onclick="closeMask('attMask')">✕</span></div>
    <div class="m-body">
        <div id="attHint" style="border:1px solid #E8D5B5;background:#FFF7E8;border-radius:6px;
             padding:6px 10px;margin-bottom:8px;color:#7A4A12;font-size:12px;"></div>
        <div id="attStep1">
            <div id="attFiles"></div>
        </div>
        <!-- PDF 要指定頁（申請內容那一段）：直接把每一頁畫成縮圖讓人點，不要叫人自己數第幾頁 -->
        <div id="attStep2" style="display:none;">
            <div style="margin-bottom:6px;">
                <button class="ec-btn ghost" id="btnAttBack"><i class="fa fa-arrow-left"></i> 換一個檔案</button>
                <span id="attPickedName" style="font-size:12px;color:#7A4A12;margin-left:8px;"></span>
            </div>
            <div id="attPages" class="att-pages"></div>
        </div>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('attMask')">關閉</button></div>
</div></div>

<!-- ══════════ 一次代簽全部（管理員） ══════════ -->
<div class="ec-mask" id="bulkMask"><div class="ec-modal xwide">
    <div class="m-head"><span>一次代簽全部</span><span class="m-close" onclick="closeMask('bulkMask')">✕</span></div>
    <div class="m-body">
        <div style="border:1px solid #E8D5B5;background:#FFF7E8;border-radius:6px;padding:6px 10px;
             margin-bottom:8px;color:#7A4A12;font-size:12px;">
            <b>一欄一個簽章人員</b>——技術課在這張表單有兩格要蓋章（設計分析、管制員），可以分別挑不同的人。<br>
            簽章時間由系統依<b>正確的簽核順序</b>自動配：每一格比前一格晚
            <b>隨機 8~54 分鐘</b>，而且<b>全部在同一天簽完</b>。<br>
            <b>當天請假的人一律不可選</b>（清單上會寫明是什麼假）——請改挑他的代理人，或把簽章日期改到他沒請假的那一天。
        </div>
        <div class="fld" style="max-width:240px;margin-bottom:8px;"><label>簽章日期 <span style="color:#DD5138">*</span>
                <small style="font-weight:normal;color:#aaa;">（不可早於申請單日期）</small></label>
            <input type="date" id="bulkDate" class="form-control"><div class="err"></div></div>
        <div id="bulkSlots"></div>
    </div>
    <div class="m-foot" style="display:flex;gap:6px;justify-content:flex-end;">
        <span id="bulkHint" style="flex:1;text-align:left;font-size:12px;color:#8a6d45;align-self:center;"></span>
        <button class="b-ok" id="btnBulkOk">確定代簽</button>
    </div>
</div></div>

<!-- ══════════ 更正某一格的簽章（管理員，限代簽過的） ══════════ -->
<div class="ec-mask" id="fixMask"><div class="ec-modal">
    <div class="m-head"><span id="fixTitle">更正簽章</span><span class="m-close" onclick="closeMask('fixMask')">✕</span></div>
    <div class="m-body">
        <div style="border:1px solid #E8D5B5;background:#FFF7E8;border-radius:6px;padding:6px 10px;
             margin-bottom:8px;color:#7A4A12;font-size:12px;">
            這一格是<b>管理員代簽</b>的，所以可以更正簽章人員與時間（<b>結案後也可以</b>）。
            本人自己簽的章不會出現這個按鈕——那等於改掉別人的簽名。</div>
        <div class="fld"><label>簽章人員 <span style="color:#DD5138">*</span></label>
            <select id="fixWho" class="form-control" data-eg-filter="輸入姓名或部門篩選…"></select><div class="err"></div></div>
        <div class="fld" style="margin-top:6px;"><label>簽章時間 <span style="color:#DD5138">*</span>
                <small style="font-weight:normal;color:#aaa;">（不可早於申請單日期，也不可晚於現在）</small></label>
            <input type="datetime-local" id="fixAt" class="form-control"><div class="err"></div></div>
    </div>
    <div class="m-foot"><button class="b-ok" id="btnFixOk">確定更正</button></div>
</div></div>

<!-- ══════════ 管理員代簽：要代誰簽 ══════════ -->
<div class="ec-mask" id="proxyMask"><div class="ec-modal narrow">
    <div class="m-head"><span>代理簽核</span><span class="m-close" onclick="closeMask('proxyMask')">✕</span></div>
    <div class="m-body">
        <div style="border:1px solid #E8D5B5;background:#FFF7E8;border-radius:6px;padding:6px 10px;
             margin-bottom:8px;color:#7A4A12;font-size:12px;">
            你不是這一關的簽核人，這是<b>管理員代簽</b>。<b>章仍然蓋原本該簽的那個人</b>，
            下一關的「送出人員」也記成他；實際是你按的這件事會記在本單的簽核紀錄（<b>列印不會印出來</b>）。</div>
        <div class="fld"><label>這一關要代誰簽 <span style="color:#DD5138">*</span></label>
            <select id="proxyWho" class="form-control" data-eg-filter="輸入姓名或部門篩選…"></select></div>
        <div class="fld" style="margin-top:6px;"><label>簽章時間
                <small style="font-weight:normal;color:#aaa;">（留空＝現在；不可早於申請單日期）</small></label>
            <input type="datetime-local" id="proxyAt" class="form-control"></div>
    </div>
    <div class="m-foot"><button class="b-ok" id="btnProxyOk">確定代簽</button></div>
</div></div>

<!-- ══════════ 會審填寫 ══════════ -->
<div class="ec-mask" id="rvMask"><div class="ec-modal">
    <div class="m-head"><span id="rvTitle">會審</span><span class="m-close" onclick="closeMask('rvMask')">✕</span></div>
    <div class="m-body">
        <div id="rvChecks"></div>
        <div id="rvExtras" style="margin-top:8px;"></div>
        <div class="fld" style="margin-top:8px;"><label>會審意見
                <small style="font-weight:normal;color:#aaa;">（無相關意見者可留空）</small></label>
            <textarea id="rvOpinion" class="form-control" rows="3"></textarea></div>
    </div>
    <div class="m-foot"><button class="b-ok" id="btnRvOk">確定並簽名</button></div>
</div></div>

<?php if ($P['canAdmin']): ?>
<!-- ══════════ 設定（管理員） ══════════ -->
<div class="ec-mask" id="setMask"><div class="ec-modal">
    <div class="m-head"><span>工程變更申請單 設定</span><span class="m-close" onclick="closeMask('setMask')">✕</span></div>
    <div class="m-body">
        <div class="sec"><div class="sh">綁定 AS 文件編號</div><div class="sb">
            <div style="display:flex;gap:8px;align-items:center;">
                <span id="setAsDoc" style="font-size:13px;color:#5b3a1e;">（未綁定）</span>
                <button class="ec-btn ghost" id="btnPickAsDoc">選擇文件…</button>
                <button class="ec-btn ghost" id="btnClearAsDoc">取消綁定</button>
            </div>
            <div style="font-size:11px;color:#aaa;margin-top:4px;">
                列印時表頭的表單名稱取自這份文件的名稱、頁尾右下角的編號依本單日期回推當時生效的版次。</div>
        </div></div>
        <div class="sec"><div class="sh">各關卡簽章人來源</div><div class="sb">
            <div class="fgrid" id="setSigns"></div>
            <div style="font-size:11px;color:#aaa;margin-top:6px;">
                一律即時解析（不寫死人名）；解析時以本單日期回推當時的職務，本人不在時自動換代理人並在圖章加「代」字。</div>
        </div></div>
        <div class="sec"><div class="sh">附件：可挑選的標籤</div><div class="sb">
            <div style="font-size:11px;color:#8a6d45;margin-bottom:6px;">
                勾選之後，申請人／技術課才挑得到那個標籤底下的料號附件。
                標籤本身在<b>主檔管理 → 附件標籤設定</b>維護（本頁不另存一份，改名一處生效）。
                <b>同一段、同一個標籤只能挑一個檔案</b>。</div>
            <div class="fgrid">
                <div class="fld half"><label>申請內容可挑的標籤
                        <small style="font-weight:normal;color:#aaa;">（PDF 必須指定一頁）</small></label>
                    <div class="cat-pick" id="catPickApply"></div></div>
                <div class="fld half"><label>設計分析可挑的標籤
                        <small style="font-weight:normal;color:#aaa;">（PDF 可整份多頁）</small></label>
                    <div class="cat-pick" id="catPickDesign"></div></div>
                <div class="fld half"><label>申請內容的提示文字</label>
                    <input type="text" id="set_hint_apply" class="form-control" maxlength="200"></div>
                <div class="fld half"><label>設計分析的提示文字</label>
                    <input type="text" id="set_hint_design" class="form-control" maxlength="200"></div>
            </div>
        </div></div>
        <div class="sec"><div class="sh">附件：各變更方式的規則</div><div class="sb">
            <div id="setAttachRules"></div>
            <div style="font-size:11px;color:#aaa;margin-top:6px;">
                「必選附件」＝沒挑附件就送不出去（前端即時擋、後端同規則再擋一次）；
                「不可選附件」＝選了這個變更方式時，申請內容的附件區整個不出現。</div>
        </div></div>
        <div class="sec"><div class="sh">圖章模板</div><div class="sb">
            <div class="fgrid">
                <div class="fld half"><label>各關卡簽章</label>
                    <select id="set_stamp" class="form-control" data-eg-filter="輸入模板名稱篩選…"></select></div>
                <div class="fld half"><label>會審簽章</label>
                    <select id="set_rv_stamp" class="form-control" data-eg-filter="輸入模板名稱篩選…"></select></div>
            </div>
        </div></div>
        <div class="sec"><div class="sh">列印</div><div class="sb">
            <label class="chk"><input type="checkbox" id="set_print_sign_log"> 在頁尾附註下方加印一份「簽核紀錄」</label>
            <div style="font-size:11px;color:#aaa;">
                印的是 <b>關卡／簽核人（含當時部門職稱）／簽核日期</b>。
                <b>絕對不會出現「管理員○○○代簽」之類的字樣</b>——那只在系統畫面的簽核紀錄看得到。</div>
        </div></div>
        <div class="sec"><div class="sh">自動產生</div><div class="sb">
            <label class="chk"><input type="checkbox" id="set_auto"> 圖面變更紀錄送出且「變更來源＝客戶」時，自動建立一張工程變更申請單草稿</label>
            <div style="font-size:11px;color:#aaa;">
                同一次變更不重複開單：已有工程變更單時，以<b>客戶版次</b>或<b>客戶圖面日期</b>為判定標準；
                兩者都沒有時由建立者認定有變更（一律建成草稿，等人確認後才送出）。</div>
        </div></div>
    </div>
    <div class="m-foot"><button class="b-ok" id="btnSetSave">儲存設定</button></div>
</div></div>
<?php endif; ?>

<!-- ══════════ 各角色權限說明（RBAC：標頭 ? 圖示點開；內容即時查現況，不寫死） ══════════ -->
<div class="ec-mask" id="roleMask"><div class="ec-modal">
    <div class="m-head"><span><i class="fa fa-users"></i> 工程變更申請單　各角色權限說明</span>
        <span class="m-close" onclick="closeMask('roleMask')">✕</span></div>
    <div class="m-body help-doc">
        <p>你目前的角色：<b><?= htmlspecialchars($roleLabel) ?></b></p>
        <table class="hist" style="margin-bottom:10px;">
            <thead><tr><th style="width:140px;">角色</th><th>可以做什麼</th></tr></thead>
            <tbody>
<?php if (!$roleRows): ?>
            <tr><td colspan="2" style="color:#aaa;">尚未建立任何角色，請至「使用者權限設定」建立。</td></tr>
<?php else: foreach ($roleRows as $rr): ?>
            <tr><td><b><?= htmlspecialchars($rr['role_name']) ?></b></td>
                <td><?= (int)$rr['is_system'] === 1
                        ? '系統角色，固定擁有全部權限（不可修改）'
                        : htmlspecialchars((string)($rr['note'] ?: '（尚未填寫說明）')) ?></td></tr>
<?php endforeach; endif; ?>
            </tbody>
        </table>
        <div class="tip"><b>簽核權不看角色</b>：流程各關卡由系統依「本單日期<b>當時</b>的職務」解析出該簽的人，
            是那個人才簽得下去（本人不在時自動換代理人）。<b>管制員這一關可以指定某個課室底下的特定幾個人（複選）</b>，
            其中任何一位簽了就算過這一關。沒有任何角色的人，仍看得到「輪到自己簽」的那幾張單。</div>
        <div class="tip">角色與人員的對應請到
            <a href="../user/user_permissions.php#eng-role-section" target="_blank" style="color:#b5762a;">
                使用者權限設定 → 工程變更申請單 角色指派</a> 設定；
            各關卡要找誰簽則在本頁右上「設定」→「各關卡簽章人來源」。</div>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('roleMask')">我知道了</button></div>
</div></div>

<!-- ══════════ 使用說明（鐵律7） ══════════ -->
<div class="ec-mask" id="helpUseMask"><div class="ec-modal xwide">
    <div class="m-head"><span><i class="fa fa-question-circle"></i> 工程變更申請單 使用說明</span>
        <span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>這一頁在做什麼</h4>
        <p>客戶改圖、藍圖有誤、或廠內因製造需求要改變設計時，用這張單走完
           「申請 → 倉管確認庫存 → 技術課設計分析 → 核示 → 相關單位會審 → 管制」的流程，
           並留下可追溯的簽核紀錄。對應 AS9100 表單 <b>2-TD-01-01 工程變更申請/審查/通知單</b>。</p>

        <h4>流程與關卡</h4>
        <p style="font-size:13px;">申請單位　↓　倉管　↓　技術　↓　其他單位（僅需會審者）　↓　技術</p>
        <ul>
            <li><b>申請人</b>：填料號（選了自動綁客戶）、變更方式與附件，按「送出」正式成立。
                <b>設變事由說明只有選「其他變更」時才打得了字</b>，其餘變更方式一律反灰。</li>
            <li><b>單位主管</b>：依「設定 → 各關卡簽章人來源」解析。要讓<b>部門內職級比申請人高的主管都能簽（任一位即可）</b>，
                請選<b>「職級高於申請人的主管」</b>——例如業務課組員開單，經理與課長<b>兩位都會收到通知</b>，誰先簽就算誰的。
                選「單位主管」或「申請部門主管」時只會解析出<b>一位</b>（該單位職級最高的那個），其他主管收不到通知。
                該單位一位主管都沒有時才往上一層單位找，<b>只追溯到「課」級</b>（規則見 ai-rules/24 審核層級規範）。</li>
            <li><b>倉管組</b>：填「庫存數量」「已完工待入庫數量」後簽核。這兩個數字系統會<b>自動帶入讓你確認是否相同</b>——
                庫存數量取自庫存管理、已完工待入庫取自「未結案 BOM 中最後一道製程已完工」的批次；
                <b>實際以清點為準，可直接改</b>，也可以按「重新帶入系統數量」還原。
                <b>技術課勾了「單一製程（不需確認庫存）」時，這一關會整個跳過、也不會通知倉管。</b></li>
            <li><b>技術課</b>：做設計分析——勾<b>「僅修改圖面（修改後結案）」</b>就不跑會審；
                勾<b>「需修改圖面與會審」</b>要一併勾選需要哪些單位會審。同時判定庫存舊料可否修改。
                <b>選了「更新圖面需附上」的任一結果，就一定要挑附件才簽得下去。</b>
                另可勾<b>「單一製程（不需確認庫存）」</b>＝這張單不必經過倉管（在送出前勾才來得及跳過那一關）。</li>
            <li><b>核准</b>：核示「准予變更／暫緩變更／其他」，可填補充意見。</li>
            <li><b>相關單位會審</b>：<b>由技術課那一關的填寫人員決定哪些單位需要會審</b>，不是六個單位一律都會簽——
                沒有被勾選的單位不會收到通知、也不必簽。被勾選的單位<b>各自獨立、不分先後</b>，
                全部簽完才會往下一關（管制員）。</li>
            <li><b>管制員</b>：勾選需修改的文件資料，簽完即<b>結案</b>。
                <b>「圖面」固定勾選、不可取消</b>（工程變更一定會動到圖面），BOM 與操作手冊才是選填。
                管制員可以在<b>設定</b>裡指定某個課室底下的<b>特定幾個人（複選）</b>，其中任何一位簽了就算過這一關。</li>
        </ul>

        <h4>附件（挑料號附件）</h4>
        <ul>
            <li>附件<b>不是重新上傳</b>，是從<b>這張單料號底下已經有的料號附件</b>裡挑一個引用——
                料號附件本身不會被複製也不會被改到，移除只是解除這張單的引用。</li>
            <li>可以挑哪些<b>標籤</b>由管理員在<b>設定 → 附件：可挑選的標籤</b>指定，申請內容與設計分析<b>各一份清單</b>。</li>
            <li><b>同一段、同一個標籤只能挑一個檔案</b>；要換就按「換一個」（原本那一個自動被取代）。</li>
            <li><b>申請內容的 PDF 一定要指定其中一頁</b>——挑了檔案之後系統會把每一頁畫成縮圖讓你點。
                <b>設計分析的 PDF 可以整份多頁</b>，不必指定頁。</li>
            <li>各個<b>變更方式</b>要不要附件由管理員設定：<b>必選</b>（沒挑就送不出去）／<b>可選</b>／<b>不可選</b>（附件區整個不出現）。</li>
            <li>列印時附件會<b>自動編號</b>（附件1、附件2…），表單上印「編號　標籤名稱　附件備註」；
                按<b>「列印所有附件」</b>可以把選定的附件逐張印出來，<b>每一張右上角印同一個編號</b>，跟表單對得起來。
                PDF 會自動轉成可列印的頁面；Word／Excel 這類系統畫不出來的檔會印一張說明頁提醒你自己開檔列印。</li>
        </ul>
        <div class="tip">任何一關都可以<b>退回</b>，退回<b>必須填原因</b>，系統會通知申請人；
            申請人修正後按「重新送出」會從第一關重跑。</div>

        <h4>誰可以簽？</h4>
        <ul>
            <li>簽核權<b>不看角色</b>：系統依「本單日期<b>當時</b>的職務」解析出各關卡該簽的人，是那個人才簽得下去。</li>
            <li>該簽的人請假／不在時，自動換成他的<b>代理人</b>，圖章右下角會多一個「代」字。</li>
            <li>各關卡要找誰簽可以在<b>設定</b>裡改（職級高於申請人的主管／各部門主管／最高核准人員／指定人員…），一律即時解析，不寫死人名。</li>
            <li>畫面左下角的<b>「目前等待」</b>會把該關卡<b>全部</b>可簽的人連同<b>部門與職稱</b>列出來，不是只印一位。</li>
            <li><b>管理員代簽</b>（補歷史紙本，或當事人長期不在時把單子推動）：
                <b>章仍然蓋原本該簽的那個人</b>，這一關有好幾位合格簽核人時會先問要代誰簽；
                下一關的「送出人員」也記成他。實際是管理員按的這件事<b>只在本單的簽核紀錄顯示</b>（橘色小籤），
                <b>列印版不會印出來</b>。</li>
        </ul>

        <h4>刪除</h4>
        <ul>
            <li><b>申請人可以刪除自己建立、而且還沒送出的草稿</b>（明細右下角的「刪除這張草稿」）。
                送出之後就刪不掉了——那時候簽核紀錄與通知已經在外面跑。</li>
            <li>刪除是<b>真的刪掉、不留紀錄</b>，無法復原；文件編號的流水號不回收。</li>
            <li>管理員可以刪任何一張（含已送出的），刪除時相關通知會一併關閉。</li>
        </ul>

        <h4>會自動產生嗎？</h4>
        <ul>
            <li>會。<b>圖面變更紀錄</b>（品管 → 圖面變更紀錄）送出時，若<b>變更來源＝客戶</b>，
                系統會自動在這裡建一張<b>草稿</b>，並把客戶、料號、變更摘要帶進來。</li>
            <li><b>同一次變更不會重複開單</b>：判定標準是<b>客戶版次</b>或<b>客戶圖面日期</b>——
                同料號已經有相同版次／相同圖面日期的單就直接指向那一張。
                兩者都沒有（客戶圖常常沒有版次）時，由建立者認定有變更而開單，但一律是草稿、要有人確認過才送得出去。</li>
            <li>不想要自動產生可以在<b>設定</b>裡關掉。</li>
        </ul>

        <h4>文件編號</h4>
        <ul>
            <li>格式＝<b>西元年月日＋3 位流水號</b>（紙本規定，例：20220101001），系統自動產生。</li>
            <li>編號依<b>表單上的日期</b>產生而不是建檔當天；<b>草稿階段改了日期，編號會跟著重編</b>，
                補歷史紙本時編號才跟表單上的日期對得起來。</li>
        </ul>

        <h4>列印</h4>
        <ul>
            <li>A4 直式 <b>1:1 不縮放</b>，版面以 mm 定寸，圖章大小不會失真。</li>
            <li>表頭的表單名稱取自綁定的 AS 文件；頁尾右下角的文件編號與版次<b>依本單日期回推</b>當時生效的版本。</li>
            <li>勾選多筆按「列印勾選」＝<b>逐筆各自開視窗排隊</b>，關掉一份才開下一份。</li>
            <li>每次列印都會記錄到「列印與簽核紀錄」（列印人、時間、電腦），這是 AS9100 的可追溯性要求。</li>
        </ul>

        <h4>權限角色</h4>
        <ul>
            <li><b>eng_change_edit（申請人員）</b>：開立、修改、送出自己的申請單。</li>
            <li><b>eng_change_view（檢閱人員）</b>：唯讀查看全部申請單。</li>
            <li><b>eng_change_admin（管理員）</b>：代開、改他人的單、刪除、模組設定、AS 綁定、代簽任何一關。</li>
            <li>沒有任何角色的人，仍看得到「輪到自己簽」的那幾張單（否則收到通知點進來會是空白頁）。</li>
        </ul>
        <div class="tip">設定入口：右上工具列的「設定」（限管理員）——AS 文件綁定、各關卡簽章人來源、
            <b>附件可挑選的標籤</b>、<b>各變更方式的附件規則</b>、圖章模板、自動產生開關。</div>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">我知道了</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<!-- ★eg_stamp_tpl.js 一定要跟著載：EGStamp.stamp() 內部是
     `if (tplSchema && global.EGStampTpl) 用模板 else 用預設回墨印(圓章)`，
     漏載它的話設定選了長方章、列印出來仍然是圓章，而且完全不報錯（實際踩過） -->
<script src="../../resource/js/eg_stamp_tpl.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp_tpl.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});

var API   = '../../src/store/EngChange_API.php';
var CSRF  = '';
var DICT  = null, PERMS = null, ME = null, SETTINGS = null, AS_DOC = null;
var ROWS = [], PAGE = 1, PER = 20;
var CUR = null, CUR_REVIEWS = [], CUR_UNIT = '', CUR_CAN_TD = false;
var PART_CACHE = {};
/* 附件（使用者要求 2026-09-23）：CUR_ATT 已經由後端編好號（附件1、附件2…），
   畫面／列印／「列印所有附件」三處一律沿用同一份順序，不在前端重排。 */
var CUR_ATT = [], ATT_CATS = {apply:[], design:[]}, ATT_RULE = 'optional', ATT_RULES_ALL = {}, ATT_HINT = {};
var ATT_SLOT = '', ATT_CAT = 0, ATT_FILE = null;   // 挑選跳窗目前的狀態
var CUR_SIGNERS = {}, ATT_EDIT = {apply:false, design:false};
var CUR_SLOTS = [], FIX_SLOT = '';   // 各簽章格的狀態／正在更正哪一格

function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }
/* 日期顯示一律 YYYY.MM.DD（ai-rules/20）；空值不要印成 "1970.01.01" */
function dispDate(s){ s = String(s || '').substring(0,10); return s ? (window.egFmtDate ? egFmtDate(s) : s) : ''; }
function openMask(id){ $('#'+id).addClass('on'); }
function closeMask(id){ $('#'+id).removeClass('on'); }
/* API 用 HTTP 狀態碼回錯，jQuery 非 2xx 不進 success —— 統一在這裡顯示，避免「按了沒反應」 */
$(document).ajaxError(function(e, xhr){
    if (xhr && xhr.responseJSON && xhr.responseJSON.error) alert(xhr.responseJSON.error);
});
/* 憑證失效時自動換一張 token 重送一次，使用者才不必把填好的表單重打一遍 */
function post(data, cb){
    data.csrf = CSRF;
    $.post(API, data, function(r){
        if (r && !r.ok && r.code === 'CSRF') {
            $.getJSON(API, {action:'csrf_token'}, function(t){
                if (!t.ok) return;
                CSRF = t.csrf; data.csrf = CSRF;
                $.post(API, data, function(r2){ cb && cb(r2); }, 'json');
            });
            return;
        }
        cb && cb(r);
    }, 'json');
}
function stampHtml(name, date, deputy, tpl, dept, pos){
    try { if (window.EGStamp && EGStamp.stamp) return EGStamp.stamp(name, date || '', !!deputy, tpl ? tpl.schema : null, dept || '', pos || ''); }
    catch(e){}
    return esc(name || '');
}

/* ══════════════════ 初始化 ══════════════════ */
$.getJSON(API, {action:'bootstrap'}, function(r){
    if (!r.ok) return;
    CSRF = r.csrf; DICT = r.dict; PERMS = r.perms; ME = r.me; SETTINGS = r.settings; AS_DOC = r.as_doc;
    ATT_HINT = r.attach_hint || {};
    buildRadios('#e_ctype',    'ctype',    DICT.change_types);
    buildRadios('#e_design',   'design',   DICT.design_results);
    buildRadios('#e_oldstock', 'oldstock', DICT.old_stock);
    buildRadios('#e_verdict',  'verdict',  DICT.verdicts);
    var ru = $('#e_review_units').empty();
    $.each(DICT.review_units, function(k, d){
        ru.append('<label class="chk"><input type="checkbox" class="rv-need" value="'+k+'"> '+esc(d.label)+'</label>');
    });
    if (SETTINGS) buildSettings();
    loadList(function(){
        var openId = <?= (int)$openId ?>;
        // 帶 ?id= 進來（多半是從通知點進來的）就自動開啟並捲到目前這一關
        if (openId) openEc(openId, true);
    });
});

function buildRadios(sel, name, map){
    var h = '';
    $.each(map, function(k, label){
        h += '<label class="chk"><input type="radio" name="'+name+'" value="'+k+'"> '+esc(label)+'</label>';
    });
    $(sel).html(h);
}

/* ══════════════════ 清單 ══════════════════ */
function loadList(cb){
    $.getJSON(API, {action:'list', keyword:$('#f-kw').val(), status:$('#f-status').val(),
                    mine:$('#f-mine').is(':checked')?1:0, todo:$('#f-todo').is(':checked')?1:0}, function(r){
        if (!r.ok) return;
        ROWS = r.rows || []; PAGE = 1;
        renderList();
        cb && cb();
    });
}
function renderList(){
    var total = ROWS.length, pages = Math.max(1, Math.ceil(total / PER));
    if (PAGE > pages) PAGE = pages;
    var slice = ROWS.slice((PAGE-1)*PER, PAGE*PER);
    var h = '';
    slice.forEach(function(d){
        var cls = d.status === 'DRAFT' ? 'draft' : (d.status === 'CLOSED' ? 'done'
                : (d.status === 'REJECTED' ? 'rej' : 'wait'));
        var todo = (d.can_sign || (d.my_review_units && d.my_review_units.length))
                 ? ' <span class="pill todo">待我簽</span>' : '';
        h += '<tr>'
          + '<td><input type="checkbox" class="ck" value="'+d.ec_id+'"></td>'
          + '<td><a href="javascript:;" onclick="openEc('+d.ec_id+')"><b>'+esc(d.doc_no)+'</b></a></td>'
          + '<td>'+dispDate(d.apply_date)+'</td>'
          + '<td>'+esc(d.customer_name)+'</td>'
          + '<td>'+esc(d.part_no)+'</td>'
          + '<td>'+esc(d.apply_dept_name)+'</td>'
          + '<td>'+esc(d.applicant_name)+'</td>'
          + '<td style="font-size:12px;">'+esc((DICT.change_types||{})[d.change_type] || '')+'</td>'
          + '<td><span class="pill '+cls+'">'+esc(d.status_label)+'</span>'+todo+'</td>'
          + '<td><button class="ec-btn ghost" style="height:24px;padding:0 8px;font-size:12px;" onclick="openEc('+d.ec_id+')">開啟</button> '
          + '<button class="ec-btn ghost" style="height:24px;padding:0 8px;font-size:12px;" onclick="printQueue(['+d.ec_id+'],0)">列印</button></td>'
          + '</tr>';
    });
    $('#listBody').html(h || '<tr><td colspan="10" style="text-align:center;color:#aaa;padding:20px;">沒有符合條件的申請單</td></tr>');
    $('#listInfo').text('共 ' + total + ' 筆');
    var p = '';
    if (pages > 1) {
        p += '<button class="ec-btn ghost" style="height:24px;padding:0 8px;" onclick="gotoPage('+(PAGE-1)+')"'+(PAGE<=1?' disabled':'')+'>‹</button> ';
        p += '第 ' + PAGE + ' / ' + pages + ' 頁 ';
        p += '<button class="ec-btn ghost" style="height:24px;padding:0 8px;" onclick="gotoPage('+(PAGE+1)+')"'+(PAGE>=pages?' disabled':'')+'>›</button>';
    }
    $('#pager').html(p);
    $('#chkAll').prop('checked', false);
}
function gotoPage(n){ PAGE = n; renderList(); }
function selIds(){ return $('.ck:checked').map(function(){ return parseInt(this.value); }).get(); }
$('#chkAll').on('change', function(){ $('.ck').prop('checked', this.checked); });
$('#btnSearch').on('click', function(){ loadList(); });
$('#f-kw').on('keydown', function(e){ if (e.which === 13) loadList(); });
$('#f-status, #f-mine, #f-todo').on('change', function(){ loadList(); });

/* ══════════════════ 明細 ══════════════════ */
function openEc(id, scrollToStage){
    $.getJSON(API, {action:'get', id:id}, function(r){
        if (!r.ok) return;
        CUR = r.row; CUR_REVIEWS = r.reviews || [];
        CUR_ATT = r.attachments || []; ATT_CATS = r.attach_cats || {apply:[], design:[]};
        ATT_RULE = r.attach_rule || 'optional'; ATT_RULES_ALL = r.attach_rules_all || {};
        CUR_SIGNERS = r.signers || {}; CUR_SLOTS = r.sign_slots || [];
        fillEc(r);
        openMask('ecMask');
        // 從通知點進來時直接捲到「目前輪到的那一關」，不要讓人自己從頭找到底
        //（使用者要求：點開通知要自動出現在核示位置）
        if (scrollToStage) scrollToStageSec(r.row.status);
    });
}
function scrollToStageSec(stage){
    var $sec = $('#ecMask .sec[data-stage="' + stage + '"]');
    if (!$sec.length || $sec.is(':hidden')) return;
    var $body = $('#ecMask .m-body');
    setTimeout(function(){
        $body.animate({ scrollTop: $body.scrollTop() + $sec.position().top - 8 }, 250);
        // 閃一下讓人知道要看哪裡
        $sec.css('box-shadow', '0 0 0 3px #F0A24B');
        setTimeout(function(){ $sec.css('box-shadow', ''); }, 1600);
    }, 250);
}
function fillEc(r){
    var d = r.row;
    $('#ecTitle').text('工程變更申請單　' + (d.doc_no || '') + '　' + (d.status_label || ''));
    // 流程圖
    var order = ['SUP','WH','TD','APPROVE','REVIEW','CTRL'], flow = '';
    var curIdx = order.indexOf(d.status);
    order.forEach(function(k, i){
        if (k === 'REVIEW' && d.design_result !== 'need_review') return;   // 不需會審就不畫這一格
        var cls = (d.status === 'CLOSED') ? 'ok'
                : (curIdx < 0 ? '' : (i < curIdx ? 'ok' : (i === curIdx ? 'on' : '')));
        if (flow) flow += '<span class="arw">›</span>';
        flow += '<span class="s '+cls+'">'+esc(DICT.stages[k] || k)+'</span>';
    });
    flow = '<span class="s '+(d.status==='DRAFT'?'on':'ok')+'">申請</span><span class="arw">›</span>' + flow
         + '<span class="arw">›</span><span class="s '+(d.status==='CLOSED'?'ok':'')+'">結案</span>';
    $('#stageFlow').html(flow);

    if (d.status === 'REJECTED') {
        $('#rejBox').show().html('<b>已退回</b>（' + esc((DICT.stages||{})[d.reject_stage] || '') + '）：' + esc(d.reject_reason || ''));
    } else $('#rejBox').hide();

    $('#e_doc_no').val(d.doc_no || '');
    $('#e_apply_date').val(String(d.apply_date || '').substring(0,10));
    $('#e_part_kw').val(d.part_no || '').data('did', d.d_id || 0);
    $('#e_customer').val(d.customer_name || '').data('cid', d.customer_id || 0);
    showBindTags(d.d_id || 0, d.customer_name || '', d.customer_id || '');

    $('input[name=ctype]').prop('checked', false).filter('[value="'+(d.change_type||'')+'"]').prop('checked', true);
    $('#e_reason').val(d.change_reason || '');
    $('#e_stock_qty').val(d.stock_qty || '');
    $('#e_wip_qty').val(d.wip_qty || '');
    $('input[name=design]').prop('checked', false).filter('[value="'+(d.design_result||'')+'"]').prop('checked', true);
    $('input[name=oldstock]').prop('checked', false).filter('[value="'+(d.old_stock||'')+'"]').prop('checked', true);
    $('#e_design_note').val(d.design_note || '');
    $('input[name=verdict]').prop('checked', false).filter('[value="'+(d.verdict||'')+'"]').prop('checked', true);
    $('#e_verdict_other').val(d.verdict_other || '');
    $('#e_verdict_other_wrap').toggle(d.verdict === 'other');
    $('#e_verdict_note').val(d.verdict_note || '');
    $('#e_single_process').prop('checked', +d.single_process === 1);
    // 「圖面」固定勾選（後端也會強制），舊資料沒勾的開起來也一律顯示成勾選
    $('#e_ctrl_drawing').prop('checked', true);
    $('#e_ctrl_bom').prop('checked', +d.ctrl_bom === 1);
    $('#e_ctrl_manual').prop('checked', +d.ctrl_manual === 1);
    $('.rv-need').prop('checked', false);
    CUR_REVIEWS.forEach(function(rv){ if (rv.needed) $('.rv-need[value="'+rv.unit_key+'"]').prop('checked', true); });

    loadPeople(String(d.apply_date||'').substring(0,10), d.applicant_id, d.apply_dept_id);
    loadStockSnap(d);
    renderReviews(r);
    renderHist(r.approvals || [], d);
    applyStageUI(d, r.signers || {});
    renderAttach('apply');
    renderAttach('design');
}

/** 依關卡決定「哪一段可以填、哪些按鈕出現」——不是這一關的人一律唯讀（後端也會再擋一次） */
function applyStageUI(d, signers){
    var editHead = +d.can_edit === 1;
    // 日期是不是鎖住了：使用者要求「只有送出才鎖定日期」，且**連管理員都不能繞過**
    // （後端 ec_date_locked 同一套規則再擋一次），所以這裡不能只看 editHead（那對管理員永遠是 true）
    var dateLocked = +d.date_locked === 1;
    $('#e_apply_date').prop('disabled', !editHead || dateLocked)
        .attr('title', dateLocked ? '已送出，日期不可再修改' : '');
    $('#e_part_kw,#e_reason').prop('disabled', !editHead);
    $('#e_applicant,#e_post').prop('disabled', !editHead);
    if (!editHead) $('#e_post').prop('disabled', true);
    $('input[name=ctype]').prop('disabled', !editHead);

    var st = d.status, mine = +d.can_sign === 1;
    // 「提早填寫」：本身就在該課室的人可以先把自己那一段填好（填但不簽，使用者要求 2026-08-25）。
    // 輪到自己那一關時當然也能填，所以兩者取聯集。
    var pre = d.prefill || [];
    var may = function(stage){ return pre.indexOf(stage) >= 0 || (mine && st === stage); };
    var canWH = may('WH'), canTD = may('TD'), canCT = may('CTRL');
    // 核示沒有「本身部門」的概念，所以一般人只有輪到自己那一關才填得了；
    // 但管理員要能提早填（後端 ec_can_prefill_stage 也是同一套），否則「一次代簽全部」
    // 永遠會卡在「請選擇核示結果」——那一關的欄位在單子走到之前根本打不開。
    var canAP = may('APPROVE');

    $('#e_stock_qty,#e_wip_qty').prop('disabled', !canWH);
    $('input[name=design],.rv-need').prop('disabled', !canTD);
    $('#e_design_note,#e_single_process').prop('disabled', !canTD);
    syncSingleProcess(canTD);
    // 附件能不能改：申請內容那一段跟著表頭、設計分析那一段跟著技術課（後端 ec_attach_can_edit 同一套）
    ATT_EDIT = {apply: editHead, design: canTD};
    // 這一區一律顯示給「填得了技術課那一段」的人，不隨 radio 開開關關——
    // 整區消失會讓人以為系統沒有這個功能（使用者實際回報過）。
    CUR_CAN_TD = canTD;
    $('#e_review_pick').toggle(canTD || d.design_result === 'need_review' || hasNeeded());
    syncReviewPick();
    $('input[name=verdict]').prop('disabled', !canAP);
    $('#e_verdict_other,#e_verdict_note').prop('disabled', !canAP);
    $('#e_ctrl_bom,#e_ctrl_manual').prop('disabled', !canCT);
    $('#e_ctrl_drawing').prop('checked', true).prop('disabled', true);   // 固定勾選，任何人都不給取消
    syncReasonLock();
    // 提早填的區塊給個提示，免得使用者以為自己已經簽了
    $('#preHint').toggle(pre.length > 0 && st !== 'CLOSED').html(
        pre.length ? ('<i class="fa fa-pencil"></i> 你是「'
            + pre.map(function(x){ return DICT.stages[x] || x; }).join('、')
            + '」的人員，可以先把那幾段填好按「儲存」；<b>真正的簽核仍要等單子走到那一關</b>。') : '');

    $('#btnEcSave').toggle(editHead || pre.length > 0)
                   .html('<i class="fa fa-save"></i> ' + (editHead ? '儲存草稿' : '儲存填寫內容'));
    $('#btnEcSubmit').toggle(editHead).text(st === 'REJECTED' ? '重新送出' : '送出');
    $('#btnEcSign').toggle(mine && st !== 'REVIEW').text('簽核（' + (DICT.stages[st] || '') + '）');
    $('#btnEcReject').toggle((mine && st !== 'REVIEW') || (st === 'REVIEW' && (d.my_review_units||[]).length > 0));
    // 只能刪自己建立且尚未送出的（管理員任何一張都可以）＝後端 ec_can_delete_row 同一套
    $('#btnEcDel').toggle(+d.can_delete === 1)
                  .html('<i class="fa fa-trash"></i> ' + (d.status === 'DRAFT' ? '刪除這張草稿' : '刪除'));
    $('#btnEcPrintAtt').toggle(CUR_ATT.length > 0).html('<i class="fa fa-paperclip"></i> 列印所有附件（' + CUR_ATT.length + '）');
    // 一次代簽全部：管理員限定，且要先送出（DRAFT 的申請人那一格是由「送出」蓋的），還有沒簽的格子才出現
    var pending = (CUR_SLOTS || []).filter(function(s){ return !s.signed; }).length;
    $('#btnEcBulk').toggle(!!(PERMS && PERMS.canAdmin) && st !== 'DRAFT' && pending > 0)
                   .html('<i class="fa fa-pencil-square-o"></i> 一次代簽全部（' + pending + '）');

    // 「目前等待」要印出**部門與職稱**，而且多人可簽的關卡要把人全部列出來（使用者要求 2026-09-23）
    var who = signers[st] || {}, list = who.list || [];
    var whoTxt = list.length ? list.map(function(x){ return x.label; }).join('、')
                             : '（解析不到簽核人，請檢查「設定 → 各關卡簽章人來源」與組織角色綁定）';
    $('#ecHint').text(
        st === 'CLOSED'  ? '已結案' :
        st === 'REJECTED'? '已退回，請修正後重新送出' :
        st === 'DRAFT'   ? '草稿（尚未送出，不會通知任何人）' :
        st === 'REVIEW'  ? '會審中，需會審的單位全部簽完才會進入管制關卡'
                         : ('目前等待：' + (DICT.stages[st] || '') + '　' + whoTxt
                            + (list.length > 1 ? '（其中任一位簽了就算過這一關）' : ''))
    );
}

/** 設變事由說明：只有「其他變更」可以填，其餘一律反灰（使用者要求 2026-09-23） */
function syncReasonLock(){
    var editHead = CUR && +CUR.can_edit === 1;
    var ct = $('input[name=ctype]:checked').val() || '';
    var on = editHead && ct === 'other';
    $('#e_reason').prop('disabled', !on).toggleClass('ro-auto', !on);
    $('#e_reason_lock').toggle(!!editHead && ct !== '' && ct !== 'other');
}

function renderReviews(r){
    var d = r.row, need = d.design_result === 'need_review';
    $('#reviewSec').toggle(need || (r.reviews||[]).some(function(x){ return x.needed; }));
    var h = '';
    (r.reviews || []).forEach(function(rv){
        var def = DICT.review_units[rv.unit_key] || {checks:{}, extras:{}};
        if (!rv.needed) {
            h += '<div class="rv-card skip"><div class="rh">'+esc(rv.label)+'　<span class="pill draft">不需會審</span></div></div>';
            return;
        }
        var lines = '';
        $.each(def.checks, function(k, label){
            lines += '<div style="font-size:12px;color:#5b3a1e;">' + (rv.checks[k] ? '☑' : '☐') + ' ' + esc(label) + '</div>';
        });
        $.each(def.extras, function(k, label){
            if (rv.extras[k]) lines += '<div style="font-size:12px;color:#5b3a1e;">'+esc(label)+'：'+esc(rv.extras[k])+'</div>';
        });
        if (rv.opinion) lines += '<div style="font-size:12px;color:#7A4A12;margin-top:2px;">意見：'+esc(rv.opinion)+'</div>';
        var right = rv.signed_at
            ? '<span class="pill done">已簽　'+esc(rv.signer_name)+'　'+dispDate(rv.signed_at)+'</span>'
              + (rv.signer_proxy_name ? ' <span class="pill todo" title="實際按下簽核的人；列印不會印出">管理員 '
                  + esc(rv.signer_proxy_name) + ' 代簽</span>' : '')
            : '<span class="pill wait">待簽'+(rv.expect_name ? '：'+esc(rv.expect_name) : '')+'</span>';
        var btn = rv.can_sign
            ? ' <button class="ec-btn" style="height:24px;padding:0 10px;font-size:12px;" onclick="openReview(\''+rv.unit_key+'\')">填寫並簽名</button>' : '';
        h += '<div class="rv-card"><div class="rh">'+esc(rv.label)+right+btn+'</div>'+lines+'</div>';
    });
    $('#reviewBody').html(h || '<div style="color:#aaa;font-size:12px;">技術課尚未判定是否需要會審</div>');
}

/* 簽核紀錄（使用者要求 2026-09-23）：
     ・不顯示「送出人」
     ・以**各簽章格**為主（申請人那一格本來就沒有 approval_record，用簽核紀錄湊會漏掉）
     ・管理員代簽過的格子標橘色小籤並可「更正」（改人、改時間）；**列印版一律不印這個** */
function renderHist(approvals, d){
    var isAdmin = !!(PERMS && PERMS.canAdmin);
    $('.only-admin').toggle(isAdmin);
    var h = '';
    (CUR_SLOTS || []).forEach(function(s){
        var tag = (isAdmin && s.proxy_name)
            ? ' <span class="pill todo" title="實際按下簽核的人；列印不會印出">管理員 ' + esc(s.proxy_name) + ' 代簽</span>' : '';
        var act = (isAdmin && s.can_fix)
            ? '<button type="button" class="att-mini" onclick="openFixSign(\''+s.key+'\')">更正</button>' : '';
        h += '<tr><td>'+esc(s.label)+'</td>'
          +  '<td>'+(s.signed ? '已簽核' : '<span style="color:#aaa;">未簽</span>')+'</td>'
          +  '<td>'+esc(s.signer_name||'')+tag+'</td>'
          +  '<td>'+esc(String(s.signed_at||'').substring(0,16))+'</td>'
          +  '<td></td>'
          +  (isAdmin ? '<td>'+act+'</td>' : '') + '</tr>';
    });
    // 退回是流程事實不是簽章格，仍然要看得到（含退回原因）
    (approvals || []).forEach(function(a){
        if (a.status !== 'rejected') return;
        h += '<tr><td>'+esc(a.label)+'</td><td style="color:#DD5138;">退回</td>'
          +  '<td>'+esc(a.approved_by||'')+'</td>'
          +  '<td>'+esc(String(a.approved_at||'').substring(0,16))+'</td>'
          +  '<td>'+esc(a.note||'')+'</td>'
          +  (isAdmin ? '<td></td>' : '') + '</tr>';
    });
    $('#histBody').html(h || '<tr><td colspan="6" style="color:#aaa;text-align:center;">尚無簽核紀錄</td></tr>');
}

/* ══════════════════ 附件（挑料號附件）══════════════════
   使用者要求 2026-09-23：
     ① 可挑哪些標籤由管理員設定（申請內容／設計分析各一份清單）
     ② 同一段、同一個標籤只能挑一個檔案
     ③ 申請內容的 PDF 要指定其中一頁；設計分析可以整份多頁
     ④ 變更方式決定申請內容的附件是必選／可選／不可選
   附件編號（附件1、附件2…）一律由後端算好帶回來，前端不重排。 */
function attOf(slot, catId){
    for (var i = 0; i < CUR_ATT.length; i++)
        if (CUR_ATT[i].slot === slot && +CUR_ATT[i].cat_id === +catId) return CUR_ATT[i];
    return null;
}
function renderAttach(slot){
    var isApply = slot === 'apply';
    var $wrap = $(isApply ? '#attApplyWrap' : '#attDesignWrap');
    var $box  = $(isApply ? '#attApplyBox'  : '#attDesignBox');
    var cats  = ATT_CATS[slot] || [];
    var rule  = isApply ? ATT_RULE : 'required';    // 設計分析那一段：選了結果就是必選

    if (isApply) {
        // 「不可選附件」的變更方式：整個附件區不出現（使用者指定）
        $wrap.toggle(rule !== 'none');
        $('#attApplyRule').text(rule === 'required' ? '（本變更方式必須附上附件）'
                              : rule === 'optional' ? '（可附可不附）' : '');
        if (rule === 'none') return;
    } else {
        $('#attDesignHint').text('');
    }
    if (!cats.length) {
        $box.html('<span class="att-empty">管理員還沒有設定這一段可以挑哪些附件標籤（設定 → 附件：可挑選的標籤）。</span>');
        return;
    }
    var canEdit = !!ATT_EDIT[slot];
    var hint = ATT_HINT[slot] || '';
    var h = hint ? '<div style="font-size:11px;color:#8a6d45;margin-bottom:3px;">'+esc(hint)+'</div>' : '';
    cats.forEach(function(c){
        var a = attOf(slot, c.id);
        h += '<div class="att-row"><span class="att-cat">'+esc(c.name)+'</span><span class="att-file">';
        if (a) {
            h += '<span class="att-seq">'+esc(a.seq_label)+'</span>' + esc(a.orig_name)
               + (a.page_no ? '　<b>第 '+a.page_no+' 頁</b>' + (a.page_count ? '／共 '+a.page_count+' 頁' : '') : '')
               + (a.note ? '　<span style="color:#8a6d45;">'+esc(a.note)+'</span>' : '');
        } else {
            h += '<span class="none">（未選）</span>';
        }
        h += '</span>';
        if (a) h += '<button type="button" class="att-mini" onclick="openAttFile('+a.attach_id+')">檢視</button>';
        if (canEdit) {
            h += '<button type="button" class="att-mini" onclick="openAttPick(\''+slot+'\','+c.id+')">'
               + (a ? '換一個' : '挑選…') + '</button>';
            if (a) h += '<button type="button" class="att-mini del" onclick="delAttach('+a.id+')">移除</button>';
        }
        h += '</div>';
    });
    $box.html(h);
}
function openAttFile(attachId){
    window.open('../../src/store/Part_Attachment_API.php?action=download&id=' + attachId, '_blank');
}
function delAttach(rowId){
    if (!CUR) return;
    if (!confirm('確定移除這一個附件？（只是解除這張單的引用，料號附件本身不會被刪掉）')) return;
    post({action:'attach_del', ec_id:CUR.ec_id, row_id:rowId}, function(r){
        if (!r.ok) return;
        CUR_ATT = r.rows || [];
        renderAttach('apply'); renderAttach('design');
        $('#btnEcPrintAtt').toggle(CUR_ATT.length > 0)
            .html('<i class="fa fa-paperclip"></i> 列印所有附件（' + CUR_ATT.length + '）');
    });
}
function openAttPick(slot, catId){
    if (!CUR) return;
    /* ★候選清單是依「DB 上這張單的料號」篩的，所以剛開的草稿在畫面上打完料號、
       還沒按儲存就來挑附件時，後端看到的 d_id 還是空的 → 候選永遠 0 筆，
       而且訊息會變成「這個料號底下沒有掛○○標籤的附件」，看起來像資料有問題（實測踩到）。
       改成：畫面上的料號跟 DB 不一致時，先把表頭存起來再開跳窗。 */
    var uiDid = +($('#e_part_kw').data('did') || 0);
    if (+CUR.can_edit === 1 && uiDid && uiDid !== +(CUR.d_id || 0)) {
        post($.extend({action:'save'}, headPayload()), function(r){
            if (!r.ok) return;
            $('#e_doc_no').val(r.doc_no || '');
            CUR.d_id = uiDid; CUR.part_no = $('#e_part_kw').val().trim();
            openAttPick(slot, catId);
        });
        return;
    }
    if (!+(CUR.d_id || 0)) { alert('請先選好料號（附件是從這張單料號底下的料號附件裡挑）'); return; }
    ATT_SLOT = slot; ATT_CAT = catId; ATT_FILE = null;
    var cat = (ATT_CATS[slot] || []).filter(function(c){ return +c.id === +catId; })[0] || {name:''};
    $('#attTitle').text('挑選附件　' + (DICT.attach_slots[slot] ? DICT.attach_slots[slot].label : slot) + '　—　' + cat.name);
    $('#attStep1').show(); $('#attStep2').hide();
    $('#attFiles').html('<div style="color:#aaa;">載入中…</div>');
    $('#attHint').html(esc(ATT_HINT[slot] || '') + '<br>只列本單料號「<b>' + esc(CUR.part_no || '') + '</b>」底下掛「'
                     + esc(cat.name) + '」標籤的附件，<b>新的排在最前面</b>。同一個標籤只能挑一個檔案。');
    openMask('attMask');
    $.getJSON(API, {action:'attach_candidates', id:CUR.ec_id, slot:slot, cat_id:catId}, function(r){
        if (!r.ok) return;
        var rows = r.rows || [];
        if (!rows.length) {
            $('#attFiles').html('<div style="color:#aaa;">這個料號底下沒有掛「'+esc(cat.name)
                + '」標籤的附件。請先到料號主檔上傳，或改挑其他標籤。</div>');
            return;
        }
        var h = '';
        rows.forEach(function(f){
            h += '<div class="att-f">'
              +  '<span class="nm">' + esc(f.show_name)
              +  (f.is_pdf ? ' <span class="pill draft">PDF</span>' : '')
              +  (f.note ? '<br><span class="mt">' + esc(f.note) + '</span>' : '') + '</span>'
              +  '<span class="mt">' + (f.issue_stamp_date ? '發行 ' + dispDate(f.issue_stamp_date) + '　' : '')
              +  '上傳 ' + dispDate(f.uploaded_at) + '　' + esc(f.uploaded_by || '') + '</span>'
              +  '<button type="button" class="att-mini" onclick="window.open(\'' + f.url + '\',\'_blank\')">預覽</button>'
              +  '<button type="button" class="att-mini" onclick="pickAttFile(' + f.id + ',' + f.is_pdf + ','
              +     JSON.stringify(f.show_name).replace(/"/g,'&quot;') + ',' + (r.need_page?1:0) + ')">選這一個</button>'
              +  '</div>';
        });
        $('#attFiles').html(h);
    });
}
/** 選定檔案：要指定頁的 PDF 進第二步挑頁，其餘直接存 */
function pickAttFile(attachId, isPdf, showName, needPage){
    ATT_FILE = {id:attachId, name:showName};
    if (!isPdf || !needPage) { saveAttach(attachId, 0, 0); return; }
    $('#attStep1').hide(); $('#attStep2').show();
    $('#attPickedName').html('<b>' + esc(showName) + '</b>　請點選要附上的那一頁');
    $('#attPages').html('<div style="color:#aaa;">正在載入 PDF…</div>');
    renderPdfPages('../../src/store/Part_Attachment_API.php?action=download&id=' + attachId, attachId);
}
$('#btnAttBack').on('click', function(){ $('#attStep2').hide(); $('#attStep1').show(); });

/* pdf.js：把每一頁畫成縮圖讓人點（不要叫使用者自己數第幾頁）。
   pdf.js 是非同步載入的，沒載成功時退回「自己輸入頁碼」，不要讓人完全挑不了。 */
var PDFJS_BASE = '../../resource/js/pdfjs/';
var pdfjsLoading = null;
function loadPdfJs(){
    if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
    if (pdfjsLoading) return pdfjsLoading;
    pdfjsLoading = new Promise(function(resolve, reject){
        var s = document.createElement('script');
        s.src = PDFJS_BASE + 'pdf.min.js';
        s.onload = function(){
            if (!window.pdfjsLib) { pdfjsLoading = null; reject(new Error('pdfjsLib 未載入')); return; }
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_BASE + 'pdf.worker.min.js';
            resolve(window.pdfjsLib);
        };
        s.onerror = function(){ pdfjsLoading = null; reject(new Error('pdf.min.js 載入失敗')); };
        document.head.appendChild(s);
    });
    return pdfjsLoading;
}
function renderPdfPages(url, attachId){
    loadPdfJs().then(function(lib){
        return lib.getDocument(url).promise;
    }).then(function(pdf){
        var total = pdf.numPages;
        $('#attPages').empty();
        var chain = Promise.resolve();
        for (var p = 1; p <= total; p++) (function(pn){
            chain = chain.then(function(){
                return pdf.getPage(pn).then(function(page){
                    var vp0 = page.getViewport({scale:1});
                    var scale = 200 / vp0.width;               // 縮圖寬約 200px
                    var vp = page.getViewport({scale:scale});
                    var cv = document.createElement('canvas');
                    cv.width = Math.ceil(vp.width); cv.height = Math.ceil(vp.height);
                    var $d = $('<div class="att-pg"></div>')
                        .append(cv).append('<div class="pn">第 ' + pn + ' 頁</div>')
                        .on('click', function(){ saveAttach(attachId, pn, total); });
                    $('#attPages').append($d);
                    return page.render({canvasContext:cv.getContext('2d'), viewport:vp}).promise;
                });
            });
        })(p);
        return chain;
    }).catch(function(e){
        // 退路：至少讓人自己輸入頁碼，不要卡在這裡什麼都做不了
        $('#attPages').html('<div style="color:#DD5138;font-size:12px;">PDF 縮圖產生失敗（' + esc(e.message || '')
            + '）。請直接輸入要附上的頁碼：</div>'
            + '<div style="margin-top:6px;"><input type="number" id="attManualPage" class="form-control" '
            + 'style="width:120px;display:inline-block;" min="1" value="1"> '
            + '<button type="button" class="att-mini" id="btnAttManual">確定</button></div>');
        $('#btnAttManual').on('click', function(){
            var pn = parseInt($('#attManualPage').val(), 10) || 0;
            if (pn < 1) { alert('請輸入正確的頁碼'); return; }
            saveAttach(attachId, pn, 0);
        });
    });
}
function saveAttach(attachId, pageNo, pageCount){
    post({action:'attach_save', ec_id:CUR.ec_id, slot:ATT_SLOT, cat_id:ATT_CAT,
          attach_id:attachId, page_no:pageNo || 0, page_count:pageCount || 0}, function(r){
        if (!r.ok) return;
        CUR_ATT = r.rows || [];
        closeMask('attMask');
        renderAttach('apply'); renderAttach('design');
        $('#btnEcPrintAtt').toggle(CUR_ATT.length > 0)
            .html('<i class="fa fa-paperclip"></i> 列印所有附件（' + CUR_ATT.length + '）');
    });
}

/* ── 確認庫存：系統自動帶入讓倉管比對（使用者要求 2026-08-25）──────────────
   ① 每次開單都去算一次目前的庫存數量與已完工待入庫數量，顯示在欄位下方供比對
   ② 欄位還空著時（倉管還沒填過）就直接帶進去，倉管確認一下就好
   ③ 已經填過的不覆蓋——那是倉管實際清點的結果，不可以被系統值洗掉 */
var STOCK_SNAP = null;
function loadStockSnap(d){
    STOCK_SNAP = null;
    $('#e_stock_sys,#e_wip_sys').text('');
    $('#btnStockReload').hide();
    if (!d || !d.ec_id) return;
    $.getJSON(API, {action:'stock_snapshot', id:d.ec_id}, function(r){
        if (!r.ok) return;
        STOCK_SNAP = r.snap;
        showStockSnap();
        var canWH = (d.status === 'WH' && +d.can_sign === 1);
        $('#btnStockReload').toggle(canWH);
        if (canWH) {
            if (!$('#e_stock_qty').val().trim()) $('#e_stock_qty').val(String(r.snap.stock_qty));
            if (!$('#e_wip_qty').val().trim())   $('#e_wip_qty').val(String(r.snap.wip_qty));
        }
    });
}
function showStockSnap(){
    if (!STOCK_SNAP) return;
    $('#e_stock_sys').html('系統目前：<b>' + STOCK_SNAP.stock_qty + '</b>（庫存 ' + STOCK_SNAP.stock_rows + ' 筆）');
    $('#e_wip_sys').html('系統目前：<b>' + STOCK_SNAP.wip_qty + '</b>（未結案 BOM 中最後一道製程已完工 '
                       + STOCK_SNAP.wip_boms + ' 批）');
}
$('#btnStockReload').on('click', function(){
    if (!STOCK_SNAP) return;
    $('#e_stock_qty').val(String(STOCK_SNAP.stock_qty));
    $('#e_wip_qty').val(String(STOCK_SNAP.wip_qty));
});

/* ── 料號 → 客戶（客戶欄由綁定產生、反灰不可改）─────────────────────────
   ★不可以靠關鍵字查詢的前端快取來帶客戶：從清單選料號時 input 事件會先把快取清空
     再發非同步查詢，緊接著的 change 事件查到的必定是空的——這就是「綁了料號客戶沒出現」
     的原因。改成選定後向後端做一次**精確查詢**，拿到什麼就是什麼。 */
/* ★「選了料號還要再點一下客戶才出現」的根因（使用者回報 2026-09-23）：
     客戶原本只掛在 change/blur —— 從 <datalist> 點一個選項時瀏覽器只保證發 input，
     change 要等游標離開欄位才發，所以使用者一定要再點一下別的地方客戶才跑出來。
     改成 input 當下就判斷：**打的字正好等於清單裡的某一個料號**就立刻去查（等於「選到了」），
     還在打字的中間狀態則用 350ms 防抖，不會每打一個字就打一支 API。 */
var partTimer = null, partLast = '';
$('#e_part_kw').on('input', function(){
    var kw = $(this).val().trim();
    showBindTags(0, '', '');                        // 還沒確認前先把綁定標記收起來
    if (kw.length < 2) { clearTimeout(partTimer); return; }
    $.getJSON(API, {action:'parts', kw:kw}, function(r){
        if (!r.ok) return;
        var dl = $('#partList').empty(), exact = false;
        (r.rows || []).forEach(function(p){
            PART_CACHE[p.part_no] = p;                 // 只累加不清空
            if (p.part_no === kw) exact = true;
            dl.append('<option value="'+esc(p.part_no)+'">'+esc(p.customer_name)+'</option>');
        });
        // 打的字剛好就是一個完整料號＝從清單選到了，立刻帶客戶（不等 blur）
        if (exact && $('#e_part_kw').val().trim() === kw) pullPartCustomer(kw);
    });
    clearTimeout(partTimer);
    partTimer = setTimeout(function(){
        var v = $('#e_part_kw').val().trim();
        if (v && v !== partLast) pullPartCustomer(v);
    }, 350);
}).on('change blur', function(){
    pullPartCustomer($(this).val().trim());
});
function pullPartCustomer(pn){
    if (!pn) { partLast = ''; setCustomer(null); return; }
    if (pn === partLast && +($('#e_part_kw').data('did') || 0) > 0) return;   // 已經查過同一個就不重打
    partLast = pn;
    $.getJSON(API, {action:'part_one', part_no:pn}, function(r){
        if (!r.ok) return;
        setCustomer(r.row);
        markErr('#e_part_kw', r.row ? '' : '查無這個料號，請重新選擇');
    });
}
function setCustomer(row){
    // 客戶一律由料號綁定產生（使用者要求）：查不到就清空，不留上一個料號的客戶
    $('#e_part_kw').data('did', row ? row.d_id : 0);
    $('#e_customer').val(row ? (row.customer_name || '') : '').data('cid', row ? (row.customer_id || '') : '');
    if (row && !row.customer_name) markErr('#e_customer', '這個料號在主檔沒有綁定客戶，請先到料號主檔設定');
    else markErr('#e_customer', '');
    showBindTags(row ? row.d_id : 0, row ? (row.customer_name || '') : '', row ? (row.customer_id || '') : '');
}
/** 綁定圖示：綁上料號主檔的當下，料號與客戶兩格同時長出「已綁定」標記（使用者要求 2026-09-23） */
function showBindTags(dId, custName, custId){
    if (+dId > 0) $('#e_part_bind').show().removeClass('bad')
                    .html('<i class="fa fa-link"></i> 已綁定料號主檔 #' + (+dId));
    else          $('#e_part_bind').hide();
    if (+dId > 0 && custName) $('#e_cust_bind').show().removeClass('bad')
                    .html('<i class="fa fa-link"></i> 由料號帶入' + (custId ? '（' + esc(custId) + '）' : ''));
    else if (+dId > 0)        $('#e_cust_bind').show().addClass('bad')
                    .html('<i class="fa fa-unlink"></i> 此料號主檔未綁客戶');
    else                      $('#e_cust_bind').hide();
}

/* ── 申請人：**除管理員外一律固定是開單的人**，不可更改（使用者要求 2026-08-25）。
      有兼任職務時，可以選要用哪一個部門／職稱的身分來申請（另一個下拉）。 ── */
function loadPeople(date, pickId, pickDept){
    var isAdmin = !!(PERMS && PERMS.canAdmin);
    if (!isAdmin) {
        // ★顯示的一律是「這張單的申請人」，不是正在看單的人。
        //   之前寫成 ME.name，導致單位主管開別人的單要簽核時，申請人整格跳成他自己（使用者回報）。
        //   只有「新開的單／自己的草稿」才會等於自己——那是後端強制綁定的結果，不是畫面猜的。
        var who = pickId || ME.uid, whoName = (pickId && CUR && +CUR.applicant_id === +pickId)
                ? (CUR.applicant_name || '') : ME.name;
        $('#e_applicant').hide();
        $('#e_applicant_ro').show().val(whoName);
        $('#e_applicant_hint').text(+who === +ME.uid ? '（固定為你本人，不可更改）' : '（這張單的申請人）');
        loadPosts(who, date, pickDept);
        return;
    }
    $('#e_applicant_ro').hide();
    $('#e_applicant').show();
    $('#e_applicant_hint').text('（管理員可代其他人開單；依日期列出當時在職者）');
    $.getJSON(API, {action:'people', date:date}, function(r){
        if (!r.ok) return;
        // ★一人多職只列一次，但顯示文字要優先用「主職」——後端 people 清單是依部門／職稱
        //   排序，不是依主職優先，兼任的部門排在主職前面時，只取第一筆會把主職蓋掉
        //   （使用者回報：管理員補資料時申請人只顯示兼任職務）。
        //   idx 只當查找表用（get/set by key），不可以用 Object.keys() 迭代它來組清單——
        //   員工編號是數字字串，JS 規格會把數字鍵改成由小到大排序，後端排好的部門／
        //   職稱順序會被打散（ai-rules 記憶 js_object_key_numeric_reorder 同一個坑）。
        //   改成維持一個純陣列，主職出現時**原地覆蓋**第一次出現的那個位置，不重新排序。
        var list = [], idx = {};
        (r.rows || []).forEach(function(p){
            var key = String(p.id);
            if (idx.hasOwnProperty(key)) {
                if (p.is_main && !list[idx[key]].is_main) list[idx[key]] = p;
                return;
            }
            idx[key] = list.length;
            list.push(p);
        });
        var s = $('#e_applicant').empty().append('<option value="">請選擇…</option>');
        list.forEach(function(p){ s.append('<option value="'+p.id+'">'+esc(p.display)+'</option>'); });
        s.val(String(pickId || ME.uid));
        loadPosts(s.val(), date, pickDept);
    });
}
/** 該申請人在該日期當時的所有職務（含兼任）；只有一個就自動選起來 */
function loadPosts(userId, date, pickDept){
    var s = $('#e_post').empty();
    if (!userId) { s.append('<option value="">（請先選申請人）</option>'); return; }
    $.getJSON(API, {action:'my_posts', user_id:userId, date:date}, function(r){
        if (!r.ok) return;
        var rows = r.rows || [];
        // 一般使用者不能查別人的職務（後端會擋回自己）→ 看別人的單時顯示單上那個人的部門與職稱。
        // ★使用者回報 2026-09-23「申請職務沒有顯示出職稱」的根因就在這裡：原本只印 apply_dept_name
        //   （欄位裡本來就只有部門名稱、沒有職稱），所以主管開別人的單一定只看得到「業務課」。
        //   職稱由後端在 get 裡依本單日期回推好帶回來（applicant_post_label），前端不必也不能自己查。
        if (+r.user_id !== +userId) {
            var lb = (CUR && CUR.applicant_post_label) ? CUR.applicant_post_label
                   : ((CUR && CUR.apply_dept_name) || '（申請單位）');
            s.append('<option value="' + (CUR ? (CUR.apply_dept_id || 0) : 0) + '" data-deptname="'
                   + esc((CUR && CUR.apply_dept_name) || '') + '">' + esc(lb) + '</option>');
            s.prop('disabled', true);
            syncDept();
            return;
        }
        if (!rows.length) {
            s.append('<option value="">（這個日期查不到職務紀錄）</option>');
            markErr('#e_post', '這個人在該日期沒有職務紀錄，請確認日期或到員工管理補登異動');
            return;
        }
        markErr('#e_post', '');
        rows.forEach(function(p){
            s.append('<option value="'+p.dept_id+'" data-deptname="'+esc(p.dept_name)+'">'+esc(p.label)+'</option>');
        });
        var want = String(pickDept || '');
        if (want && s.find('option[value="'+want+'"]').length) s.val(want);
        // 沒指定就取主職（後端 ec_fix_applicant_post 也是同一套規則）
        else { var main = rows.filter(function(x){ return x.is_main; })[0] || rows[0]; s.val(String(main.dept_id)); }
        s.prop('disabled', rows.length <= 1);
        syncDept();
    });
}
function syncDept(){
    var o = $('#e_post').find('option:selected');
    $('#e_post').data('did', o.val() || 0).data('deptname', o.data('deptname') || '');
}
$('#e_applicant').on('change', function(){ loadPosts(this.value, $('#e_apply_date').val(), ''); });
$('#e_post').on('change', syncDept);
$('#e_apply_date').on('change', function(){
    // 日期一改，「當時的職務」就可能不一樣了（ai-rules/22）
    loadPeople($(this).val(), $('#e_applicant').val() || ME.uid, $('#e_post').val());
    // 單號要跟著自動變（使用者要求 2026-09-23）：草稿階段只是預覽，不寫入任何東西，
    // 真正佔號是在送出當下（ec_lock_doc_no_on_submit）才決定
    if (CUR && CUR.ec_id) {
        $.getJSON(API, {action:'doc_no_preview', id:CUR.ec_id, apply_date:$(this).val()}, function(r){
            if (r.ok) $('#e_doc_no').val(r.doc_no || '');
        });
    }
});
$('input[name=verdict]').on('change', function(){
    $('#e_verdict_other_wrap').toggle($('input[name=verdict]:checked').val() === 'other');
});
$(document).on('change', 'input[name=verdict]', function(){
    $('#e_verdict_other_wrap').toggle($('input[name=verdict]:checked').val() === 'other');
});
$(document).on('change', 'input[name=design]', function(){ syncReviewPick(); });
/* 變更方式一改：設變事由的反灰狀態要跟著變，附件規則（必選／可選／不可選）也跟著變 */
$(document).on('change', 'input[name=ctype]', function(){
    syncReasonLock();
    ATT_RULE = ATT_RULES_ALL[$('input[name=ctype]:checked').val() || ''] || 'optional';
    renderAttach('apply');
});
/** 目前這張單有沒有已經被勾為「需會審」的單位 */
function hasNeeded(){ return (CUR_REVIEWS || []).some(function(x){ return x.needed; }); }
/**
 * 單一製程（不需確認庫存）連動（使用者要求 2026-09-23）：勾了之後「庫存舊料」這一題
 * 也一併不需要判定——兩者是同一件事，既然不必經過倉管確認庫存，庫存舊料能不能修改
 * 也就無所謂。反灰＋顯示「不需確認」，後端 ec_validate_stage() 同規則再擋一次。
 * $canEdit 省略時沿用目前的 disabled 狀態（純粹重新整理「不需確認」提示文字用）。
 */
function syncSingleProcess(canEdit){
    var sp = $('#e_single_process').is(':checked');
    if (canEdit === undefined) canEdit = !$('#e_single_process').is(':disabled');
    $('#e_oldstock_na').toggle(sp);
    $('input[name=oldstock]').prop('disabled', !canEdit || sp);
    if (sp) $('#e_oldstock').css('opacity', '.5'); else $('#e_oldstock').css('opacity', '1');
}
$(document).on('change', '#e_single_process', function(){ syncSingleProcess(); });
/**
 * 會簽單位區塊的狀態（使用者回報「技術課人員開立單據但無法選擇需會簽單位」後改）：
 *   選「需修改圖面與會審」→ 可勾選
 *   選「僅修改圖面（修改後結案）」→ 反灰＋說明為什麼不用勾（不是整區消失）
 *   還沒選 → 反灰＋提示先選上面的設計分析結果
 */
function syncReviewPick(){
    var canEdit = CUR_CAN_TD;
    var v = $('input[name=design]:checked').val() || '';
    var on = canEdit && v === 'need_review';
    $('.rv-need').prop('disabled', !on);
    $('#e_review_pick').css('opacity', on ? '1' : '.6');
    $('#e_review_hint').text(
        !canEdit ? '（只有技術課的人員可以決定要找哪些單位會審）'
        : v === 'need_review' ? '請勾選這次要通知哪些單位會審——只有被勾的單位會收到通知並需要簽，沒勾的不必簽也不會卡住流程。'
        : v === 'drawing_only' ? '目前選的是「僅修改圖面（修改後結案）」，不需要任何單位會審。要會審請改選上面的「需修改圖面與會審」。'
        : '請先在上面選擇設計分析結果；選「需修改圖面與會審」才需要勾選會審單位。');
    // 改選「僅修改圖面」時把已勾的清掉，免得存下去變成「不需會審卻留著一堆勾選」
    if (v !== 'need_review') $('.rv-need').prop('checked', false);
    $('#e_review_err').toggle(on && $('.rv-need:checked').length === 0);
}
$(document).on('change', '.rv-need', function(){
    $('#e_review_err').toggle($('.rv-need:checked').length === 0);
});

/* ── 前端即時驗證（後端 ec_validate 會用同一套規則再擋一次＝鐵律8） ── */
function markErr(sel, msg){
    var $f = $(sel).closest('.fld');
    if (msg) { $f.addClass('bad').find('.err').text(msg); } else { $f.removeClass('bad'); }
    return !msg;
}
function validateHead(){
    var ok = true;
    ok &= markErr('#e_apply_date', $('#e_apply_date').val() ? '' : '請填寫日期');
    ok &= markErr('#e_part_kw',    $('#e_part_kw').val().trim() ? '' : '請填寫料號');
    ok &= markErr('#e_customer',   $('#e_customer').val().trim() ? '' : '請填寫客戶名稱');
    ok &= markErr('#e_post', $('#e_post').val() ? '' : '請選擇申請職務（部門／職稱）');
    var ct = $('input[name=ctype]:checked').val() || '';
    ok &= markErr('#e_ctype',      ct ? '' : '請選擇變更方式');
    ok &= markErr('#e_reason', (ct === 'other' && !$('#e_reason').val().trim())
                               ? '變更方式選「其他變更」時，必須在設變事由說明內詳述變更原因' : '');
    // 變更方式 × 附件規則（後端 ec_validate 同一套再擋一次＝鐵律8）
    var needAtt = (ATT_RULES_ALL[ct] || 'optional') === 'required';
    var hasAtt  = CUR_ATT.some(function(a){ return a.slot === 'apply'; });
    ok &= markErr('#attApplyBox', (needAtt && !hasAtt)
                                  ? '這個變更方式必須附上附件，請在右側挑選料號附件' : '');
    return !!ok;
}
function headPayload(){
    return {
        ec_id: CUR ? CUR.ec_id : 0,
        apply_date: $('#e_apply_date').val(),
        part_no: $('#e_part_kw').val().trim(), d_id: $('#e_part_kw').data('did') || 0,
        customer_name: $('#e_customer').val().trim(), customer_id: $('#e_customer').data('cid') || 0,
        applicant_id: (PERMS && PERMS.canAdmin) ? ($('#e_applicant').val() || 0) : ME.uid,
        applicant_name: (PERMS && PERMS.canAdmin)
            ? (($('#e_applicant').find('option:selected').text() || '').split('　').pop()) : ME.name,
        apply_dept_id: $('#e_post').val() || 0,
        apply_dept_name: $('#e_post').find('option:selected').data('deptname') || '',
        change_type: $('input[name=ctype]:checked').val() || '',
        change_reason: $('#e_reason').val()
    };
}

$('#btnNew').on('click', function(){
    post($.extend({action:'create'}, {
        apply_date: new Date().toISOString().substring(0,10),
        applicant_id: ME.uid, applicant_name: ME.name
        // 申請部門留空 → 後端 ec_fix_applicant_post() 會自動補成本人的主職
    }), function(r){
        if (!r.ok) return;
        loadList(function(){ openEc(r.ec_id); });
    });
});

/** 目前畫面上「這一關的欄位」值 */
function stageFields(stage){
    if (stage === 'WH')   return {stock_qty:$('#e_stock_qty').val(), wip_qty:$('#e_wip_qty').val()};
    if (stage === 'TD')   return {design_result:$('input[name=design]:checked').val()||'',
                                  old_stock:$('input[name=oldstock]:checked').val()||'',
                                  single_process:$('#e_single_process').is(':checked')?1:0,
                                  design_note:$('#e_design_note').val()};
    // 圖面固定 1（畫面上是 disabled 的勾選框，讀 :checked 也永遠是 true，這裡直接寫死比較清楚）
    if (stage === 'CTRL') return {ctrl_drawing:1,
                                  ctrl_bom:$('#e_ctrl_bom').is(':checked')?1:0,
                                  ctrl_manual:$('#e_ctrl_manual').is(':checked')?1:0};
    return {};
}
$('#btnEcSave').on('click', function(){
    if (!CUR) return;
    var pre = (CUR.prefill || []).slice();
    var doPrefill = function(i, done){
        if (i >= pre.length) { done(); return; }
        var st = pre[i];
        post($.extend({action:'save_stage_fields', ec_id:CUR.ec_id, stage:st}, stageFields(st)),
             function(){ doPrefill(i + 1, done); });
    };
    var finish = function(){
        // 技術課先勾好的會審單位也一起存（會審單位一律由技術課決定）
        if (pre.indexOf('TD') >= 0 && $('input[name=design]:checked').val() === 'need_review') {
            var units = $('.rv-need:checked').map(function(){ return this.value; }).get();
            post({action:'set_review_units', ec_id:CUR.ec_id, units:JSON.stringify(units)}, function(){
                alert('已儲存'); openEc(CUR.ec_id); loadList();
            });
            return;
        }
        alert('已儲存'); openEc(CUR.ec_id); loadList();
    };
    if (+CUR.can_edit === 1) {
        post($.extend({action:'save'}, headPayload()), function(r){
            if (!r.ok) return;
            $('#e_doc_no').val(r.doc_no || '');
            doPrefill(0, finish);
        });
    } else {
        doPrefill(0, finish);
    }
});

$('#btnEcSubmit').on('click', function(){
    if (!CUR) return;
    if (!validateHead()) { alert('還有必填欄位沒填完，請看紅色提示'); return; }
    var resub = CUR.status === 'REJECTED';
    if (!confirm(resub ? '確定重新送出？會從第一關（單位主管）重新跑一次簽核。'
                       : '送出後這張單就正式成立，並通知單位主管簽核。確定送出？')) return;
    // 先把畫面上的內容存起來再送出，避免使用者改完直接按送出而漏存
    post($.extend({action:'save'}, headPayload()), function(r){
        if (!r.ok) return;
        post({action: resub ? 'resubmit' : 'submit', ec_id:CUR.ec_id}, function(s){
            if (!s.ok) return;
            alert('已送出，接下來由「' + (DICT.stages[s.status] || '') + '」簽核');
            closeMask('ecMask'); loadList();
        });
    });
});

$('#btnEcSign').on('click', function(){
    if (!CUR) return;
    var st = CUR.status, fields = {action:'sign_stage', ec_id:CUR.ec_id, stage:st};
    if (st === 'WH') {
        fields.stock_qty = $('#e_stock_qty').val(); fields.wip_qty = $('#e_wip_qty').val();
        if (!fields.stock_qty.trim() || !fields.wip_qty.trim()) { alert('請先填寫庫存數量與已完工待入庫數量'); return; }
    } else if (st === 'TD') {
        fields.design_result   = $('input[name=design]:checked').val() || '';
        fields.old_stock       = $('input[name=oldstock]:checked').val() || '';
        fields.single_process  = $('#e_single_process').is(':checked') ? 1 : 0;
        fields.design_note     = $('#e_design_note').val();
        if (!fields.design_result) { alert('請先選擇設計分析結果'); return; }
        // 單一製程時庫存舊料不需要判定（後端 ec_validate_stage 同規則）
        if (!fields.single_process && !fields.old_stock) { alert('請先選擇庫存舊料可否修改'); return; }
        // 選了「更新圖面需附上」的任一結果就一定要挑附件（後端同規則再擋一次）
        if (!CUR_ATT.some(function(a){ return a.slot === 'design'; })) {
            alert('「更新圖面需附上」選了結果就必須挑選附件，請在設計分析區塊挑一個料號附件');
            markErr('#attDesignBox', '請挑選附件'); return;
        }
        markErr('#attDesignBox', '');
        if (fields.design_result === 'need_review') {
            var units = $('.rv-need:checked').map(function(){ return this.value; }).get();
            if (!units.length) { alert('選了「需修改圖面與會審」就要勾選至少一個會審單位'); return; }
            // 會審單位要先存起來，簽核當下才算得出「接下來要通知誰」
            post({action:'set_review_units', ec_id:CUR.ec_id, units:JSON.stringify(units)}, function(){ doSign(fields); });
            return;
        }
    } else if (st === 'APPROVE') {
        fields.verdict       = $('input[name=verdict]:checked').val() || '';
        fields.verdict_other = $('#e_verdict_other').val();
        fields.verdict_note  = $('#e_verdict_note').val();
        if (!fields.verdict) { alert('請先選擇核示結果'); return; }
        if (fields.verdict === 'other' && !fields.verdict_other.trim()) { alert('核示選「其他」時請填寫內容'); return; }
    } else if (st === 'CTRL') {
        fields.ctrl_drawing = 1;                       // 固定勾選（使用者要求），不可取消
        fields.ctrl_bom     = $('#e_ctrl_bom').is(':checked') ? 1 : 0;
        fields.ctrl_manual  = $('#e_ctrl_manual').is(':checked') ? 1 : 0;
    }
    doSign(fields);
});
/**
 * 管理員代簽（使用者要求 2026-09-23）：操作者不在這一關的合格簽核人名單裡時，
 * 先問「要代誰簽」——名單只有一位就直接用那一位、有多位才跳出來選。
 * 章一律蓋原本該簽的那個人，下一關的「送出人員」也記成他。
 */
function doSign(fields){
    var list = (CUR_SIGNERS[fields.stage] || {}).list || [];
    var mine = list.some(function(x){ return +x.id === +ME.uid; });
    if (!mine && list.length >= 1) {
        var s = $('#proxyWho').empty();
        list.forEach(function(x){ s.append('<option value="'+x.id+'">'+esc(x.label)+'</option>'); });
        $('#proxyAt').val('');
        $('#btnProxyOk').off('click').on('click', function(){
            fields.sign_as = $('#proxyWho').val() || 0;
            // 管理員代簽時可以自己指定簽章時間（使用者要求 2026-09-23）；後端會擋「早於申請單日期」
            var at = $('#proxyAt').val();
            if (at) fields.sign_at = at;
            closeMask('proxyMask');
            sendSign(fields, '確定代「' + ($('#proxyWho option:selected').text() || '') + '」簽核這一關？');
        });
        openMask('proxyMask');
        return;
    }
    sendSign(fields, '確定簽核這一關？簽完會自動通知下一關的人。');
}
function sendSign(fields, ask){
    if (!confirm(ask)) return;
    post(fields, function(r){
        if (!r.ok) return;
        alert(r.status === 'CLOSED' ? '已簽核完成，本單結案' : ('已簽核，接下來由「' + (DICT.stages[r.status] || '') + '」處理'));
        closeMask('ecMask'); loadList();
    });
}

/* ══════════════════ 代簽（管理員）══════════════════
   使用者要求 2026-09-23：
     ① 一次代簽全部，**一欄一個簽章人員**（技術課有兩格＝可挑兩個不同的人）
     ② 簽章時間依正確順序每次隨機 +8~54 分鐘，且當天全部簽完（後端 ec_sign_time_series 算）
     ③ 當天請假的人列得出來但**不可選**，請改挑代理人或改日期
     ④ 代簽過的格子事後可以更正人員與時間（結案後也可以） */
<?php if ($P['canAdmin']): ?>
var BULK_SLOTS = [];
$('#btnEcBulk').on('click', function(){
    if (!CUR) return;
    $('#bulkDate').val(String(CUR.apply_date||'').substring(0,10));
    loadBulkSlots();
    openMask('bulkMask');
});
$('#bulkDate').on('change', loadBulkSlots);
function loadBulkSlots(){
    if (!CUR) return;
    $('#bulkSlots').html('<div style="color:#aaa;">載入中…</div>');
    $.getJSON(API, {action:'sign_slots', id:CUR.ec_id, date:$('#bulkDate').val()}, function(r){
        if (!r.ok) return;
        BULK_SLOTS = r.slots || [];
        var pend = 0, h = '';
        BULK_SLOTS.forEach(function(s){
            h += '<div class="att-row" style="align-items:flex-start;">'
              +  '<span class="att-cat" style="width:110px;">' + esc(s.label) + '</span>';
            if (s.signed) {
                h += '<span class="att-file"><span class="pill done">已簽　' + esc(s.signer_name) + '　'
                  +  esc(String(s.signed_at||'').substring(0,16)) + '</span>'
                  +  (s.proxy_name ? ' <span class="pill todo">管理員 '+esc(s.proxy_name)+' 代簽</span>' : '')
                  +  '</span>';
            } else {
                pend++;
                var opts = '<option value="">請選擇…</option>';
                (s.candidates||[]).forEach(function(c){
                    opts += '<option value="'+c.id+'"'+(c.blocked?' disabled':'')+'>'
                         +  esc(c.label) + (c.blocked ? '　← 當天請假，不可選' : '') + '</option>';
                });
                var free = (s.candidates||[]).filter(function(c){ return !c.blocked; });
                // ★使用者要求 2026-09-23：這一關自己的選項（庫存數量、設計分析結果、核示、
                //   需修改文件資料…）要能在這裡一次選完，不必先跳出去用「提早填寫」補一次。
                h += '<span class="att-file">'
                  +  '<select class="form-control bulk-pick" data-k="'+esc(s.key)+'" '
                  +    'data-eg-filter="輸入姓名或部門篩選…" style="height:28px;font-size:12px;">'+opts+'</select>'
                  +  (free.length ? '' : '<div style="color:#DD5138;font-size:11px;margin-top:2px;">'
                       + '這一格當天沒有任何人可以簽（都請假了），請改簽章日期。</div>')
                  +  renderBulkStageFields(s.key)
                  +  '</span>';
            }
            h += '</div>';
        });
        $('#bulkSlots').html(h);
        // 預設選「本關卡簽核人」裡第一個沒請假的
        BULK_SLOTS.forEach(function(s){
            if (s.signed) return;
            var pick = (s.candidates||[]).filter(function(c){ return c.is_pool && !c.blocked; })[0];
            if (pick) $('.bulk-pick[data-k="'+s.key+'"]').val(String(pick.id));
        });
        syncBulkFieldState();
        $('#bulkHint').text(pend ? ('還有 ' + pend + ' 格沒簽') : '這張單所有簽章格都已經簽過了');
        $('#btnBulkOk').prop('disabled', pend === 0);
    });
}
/**
 * 一次代簽跳窗內、各關卡自己的選項（使用者要求 2026-09-23）。
 * name 一律加上 `bulk_` 前綴＋關卡代碼，避免跟主表單同名的 radio 群組互相干擾
 * （這頁沒有 `<form>` 包住欄位，同名 radio 在同一頁會被瀏覽器當成同一群組）。
 * 預設值沿用畫面上主表單目前的內容（多半是先前用「提早填寫」存過的）。
 */
function renderBulkStageFields(key){
    var d = CUR || {};
    var rd = function(name, map, val){
        var h = '';
        $.each(map, function(k, label){
            h += '<label class="chk" style="display:inline-block;margin-right:8px;">'
              +  '<input type="radio" name="'+name+'" value="'+k+'"'+(val===k?' checked':'')+'> '+esc(label)+'</label>';
        });
        return h;
    };
    if (key === 'WH') {
        return '<div class="bulk-fields" data-stage="WH" style="margin-top:4px;">'
          + '<input type="text" class="form-control bulk-f" data-f="stock_qty" placeholder="庫存數量" '
          +   'value="'+esc(d.stock_qty||'')+'" style="width:110px;display:inline-block;height:26px;font-size:12px;"> '
          + '<input type="text" class="form-control bulk-f" data-f="wip_qty" placeholder="已完工待入庫數量" '
          +   'value="'+esc(d.wip_qty||'')+'" style="width:150px;display:inline-block;height:26px;font-size:12px;">'
          + '</div>';
    }
    if (key === 'TD') {
        var sp = +d.single_process === 1;
        return '<div class="bulk-fields" data-stage="TD" style="margin-top:4px;font-size:12px;">'
          + '<div>更新圖面需附上：' + rd('bulk_design_TD', DICT.design_results, d.design_result||'') + '</div>'
          + '<div style="margin-top:2px;">'
          +   '<label class="chk"><input type="checkbox" class="bulk-f" data-f="single_process" id="bulk_sp"'
          +     (sp?' checked':'')+'> 單一製程（不需確認庫存）</label></div>'
          + '<div style="margin-top:2px;" id="bulk_oldstock_wrap">庫存舊料：'
          +   rd('bulk_oldstock_TD', DICT.old_stock, d.old_stock||'') + '</div>'
          + '</div>';
    }
    if (key === 'APPROVE') {
        return '<div class="bulk-fields" data-stage="APPROVE" style="margin-top:4px;font-size:12px;">'
          + '核示：' + rd('bulk_verdict', DICT.verdicts, d.verdict||'')
          + '</div>';
    }
    if (key === 'CTRL') {
        return '<div class="bulk-fields" data-stage="CTRL" style="margin-top:4px;font-size:12px;">'
          + '需修改文件資料：<label class="chk" style="display:inline-block;">'
          +   '<input type="checkbox" checked disabled> 圖面（固定勾選）</label> '
          + '<label class="chk" style="display:inline-block;"><input type="checkbox" class="bulk-f" '
          +   'data-f="ctrl_bom" id="bulk_ctrl_bom"'+(+d.ctrl_bom===1?' checked':'')+'> BOM</label> '
          + '<label class="chk" style="display:inline-block;"><input type="checkbox" class="bulk-f" '
          +   'data-f="ctrl_manual" id="bulk_ctrl_manual"'+(+d.ctrl_manual===1?' checked':'')+'> 操作手冊</label>'
          + '</div>';
    }
    return '';
}
// 單一製程勾選時，這個跳窗裡的庫存舊料也要跟著反灰（跟主表單同一條規則）
$(document).on('change', '#bulk_sp', function(){ syncBulkFieldState(); });
function syncBulkFieldState(){
    var sp = $('#bulk_sp').is(':checked');
    $('#bulk_oldstock_wrap input[name=bulk_oldstock_TD]').prop('disabled', sp);
    $('#bulk_oldstock_wrap').css('opacity', sp ? '.5' : '1');
}
/** 收集某關卡在跳窗裡填的欄位值（radio 用 name、其餘用 data-f） */
function collectBulkFields(stageKey){
    var $wrap = $('.bulk-fields[data-stage="'+stageKey+'"]');
    if (!$wrap.length) return null;
    var out = {};
    $wrap.find('input.bulk-f').each(function(){
        var f = $(this).data('f');
        out[f] = (this.type === 'checkbox') ? (this.checked ? 1 : 0) : this.value;
    });
    if (stageKey === 'TD') out.design_result = $wrap.find('input[name=bulk_design_TD]:checked').val() || '';
    if (stageKey === 'TD') out.old_stock = out.single_process ? '' : ($wrap.find('input[name=bulk_oldstock_TD]:checked').val() || '');
    if (stageKey === 'APPROVE') out.verdict = $wrap.find('input[name=bulk_verdict]:checked').val() || '';
    return out;
}
$('#btnBulkOk').on('click', function(){
    if (!CUR) return;
    var picks = {}, fields = {}, missPick = '';
    $('.bulk-pick').each(function(){
        var k = $(this).data('k'), v = this.value;
        if (!v) { if (!missPick) missPick = k; return; }
        picks[k] = v;
    });
    if (missPick) {
        var lb = (BULK_SLOTS.filter(function(s){ return s.key === missPick; })[0]||{}).label || missPick;
        alert('「' + lb + '」還沒有選簽章人員'); return;
    }
    // 收集各關卡自己的欄位，順便用跟主表單一樣的規則先在前端擋一次（後端同規則再擋一次＝鐵律8）
    var fieldMiss = [];
    Object.keys(picks).forEach(function(k){
        var f = collectBulkFields(k);
        if (!f) return;
        fields[k] = f;
        var lb = (BULK_SLOTS.filter(function(s){ return s.key === k; })[0]||{}).label || k;
        if (k === 'WH' && (!f.stock_qty || !String(f.stock_qty).trim() || !String(f.wip_qty||'').trim()))
            fieldMiss.push('「' + lb + '」請填寫庫存數量與已完工待入庫數量');
        if (k === 'TD') {
            if (!f.design_result) fieldMiss.push('「' + lb + '」請選擇設計分析結果');
            if (!f.single_process && !f.old_stock) fieldMiss.push('「' + lb + '」請選擇庫存舊料可否修改');
        }
        if (k === 'APPROVE' && !f.verdict) fieldMiss.push('「' + lb + '」請選擇核示結果');
        // 管制員不強制要求勾選 BOM 或操作手冊（使用者要求 2026-09-23）：
        // 圖面固定勾選，BOM／操作手冊純粹依實際情況勾選，兩個都不勾也合法。
    });
    if (fieldMiss.length) { alert(fieldMiss.join('\n')); return; }
    if (!confirm('確定一次代簽 ' + Object.keys(picks).length + ' 個簽章格？\n'
               + '簽章時間會依簽核順序自動配（每格 +8~54 分鐘，同一天內簽完）。')) return;
    post({action:'bulk_sign', ec_id:CUR.ec_id, date:$('#bulkDate').val(),
          picks:JSON.stringify(picks), fields:JSON.stringify(fields)}, function(r){
        if (!r.ok) return;
        alert('已代簽 ' + r.signed + ' 格'
            + (r.status === 'CLOSED' ? '，本單結案' : ('，目前在「' + (DICT.stages[r.status] || r.status) + '」')));
        closeMask('bulkMask'); openEc(CUR.ec_id); loadList();
    });
});

/* 更正某一格的簽章（限管理員代簽過的格子；結案後也可以改） */
function openFixSign(slotKey){
    if (!CUR) return;
    FIX_SLOT = slotKey;
    var cur = (CUR_SLOTS||[]).filter(function(s){ return s.key === slotKey; })[0] || {};
    $('#fixTitle').text('更正簽章　' + (cur.label || slotKey));
    var at = String(cur.signed_at || '').replace(' ', 'T').substring(0,16);
    $('#fixAt').val(at);
    $('#fixWho').html('<option value="">載入中…</option>');
    openMask('fixMask');
    reloadFixCandidates();
}
function reloadFixCandidates(){
    var d = String($('#fixAt').val() || '').substring(0,10);
    $.getJSON(API, {action:'sign_slots', id:CUR.ec_id, date:d}, function(r){
        if (!r.ok) return;
        var s = (r.slots||[]).filter(function(x){ return x.key === FIX_SLOT; })[0];
        var cur = (CUR_SLOTS||[]).filter(function(x){ return x.key === FIX_SLOT; })[0] || {};
        var sel = $('#fixWho').empty().append('<option value="">請選擇…</option>');
        (s ? s.candidates : []).forEach(function(c){
            sel.append('<option value="'+c.id+'"'+(c.blocked?' disabled':'')+'>'
                     + esc(c.label) + (c.blocked ? '　← 當天請假，不可選' : '') + '</option>');
        });
        if (cur.signer_id) sel.val(String(cur.signer_id));
    });
}
$('#fixAt').on('change', reloadFixCandidates);
$('#btnFixOk').on('click', function(){
    var who = $('#fixWho').val(), at = $('#fixAt').val();
    if (!markErr('#fixWho', who ? '' : '請選擇簽章人員')) return;
    if (!markErr('#fixAt',  at  ? '' : '請填寫簽章時間')) return;
    post({action:'fix_sign', ec_id:CUR.ec_id, slot:FIX_SLOT, user_id:who, sign_at:at}, function(r){
        if (!r.ok) return;
        closeMask('fixMask'); alert('已更正為 ' + r.name + '（' + r.at.substring(0,16) + '）');
        openEc(CUR.ec_id); loadList();
    });
});
<?php endif; ?>

/* 刪除：管理員任何一張都可以；一般使用者只能刪自己建立且尚未送出的草稿（後端同規則再擋一次） */
$('#btnEcDel').on('click', function(){
    if (!CUR) return;
    var isDraft = CUR.status === 'DRAFT';
    if (!confirm(isDraft ? ('確定刪除草稿 ' + (CUR.doc_no || '') + '？此動作無法復原，也不會保留任何紀錄。')
                         : ('確定刪除 ' + (CUR.doc_no || '') + '？這張單已經進入簽核流程，刪除後相關通知也會一併關閉，無法復原。'))) return;
    post({action:'delete', ec_id:CUR.ec_id}, function(r){
        if (!r.ok) return;
        closeMask('ecMask'); loadList();
    });
});

$('#btnEcReject').on('click', function(){ $('#rejReason').val(''); $('#rejReason').closest('.fld').removeClass('bad'); openMask('rejMask'); });
$('#btnRejOk').on('click', function(){
    var why = $('#rejReason').val().trim();
    if (!why) { $('#rejReason').closest('.fld').addClass('bad'); return; }
    post({action:'reject', ec_id:CUR.ec_id, stage:CUR.status, reason:why}, function(r){
        if (!r.ok) return;
        closeMask('rejMask'); closeMask('ecMask'); loadList();
        alert('已退回，並通知申請人');
    });
});

/* ── 會審填寫 ── */
function openReview(unitKey){
    CUR_UNIT = unitKey;
    var def = DICT.review_units[unitKey] || {checks:{}, extras:{}};
    var rv = (CUR_REVIEWS || []).filter(function(x){ return x.unit_key === unitKey; })[0] || {checks:{}, extras:{}, opinion:''};
    $('#rvTitle').text('會審　' + def.label);
    var h = '';
    $.each(def.checks, function(k, label){
        h += '<label class="chk"><input type="checkbox" class="rv-chk" value="'+k+'"'+(rv.checks[k]?' checked':'')+'> '+esc(label)+'</label>';
    });
    $('#rvChecks').html(h);
    var e = '';
    $.each(def.extras, function(k, label){
        e += '<div class="fld" style="margin-bottom:6px;"><label>'+esc(label)+'</label>'
           + '<input type="text" class="form-control rv-ext" data-k="'+k+'" value="'+esc(rv.extras[k]||'')+'"></div>';
    });
    $('#rvExtras').html(e);
    $('#rvOpinion').val(rv.opinion || '');
    openMask('rvMask');
}
$('#btnRvOk').on('click', function(){
    var checks = {}, extras = {};
    $('.rv-chk').each(function(){ checks[this.value] = this.checked ? 1 : 0; });
    $('.rv-ext').each(function(){ extras[$(this).data('k')] = this.value; });
    post({action:'sign_review', ec_id:CUR.ec_id, unit_key:CUR_UNIT,
          checks:JSON.stringify(checks), extras:JSON.stringify(extras), opinion:$('#rvOpinion').val()}, function(r){
        if (!r.ok) return;
        closeMask('rvMask');
        alert(r.pending > 0 ? ('已簽，還有 ' + r.pending + ' 個單位尚未會審') : '所有會審單位都簽完了，已進入管制關卡');
        openEc(CUR.ec_id); loadList();
    });
});

/* ══════════════════ 設定 ══════════════════ */
<?php if ($P['canAdmin']): ?>
var STAGE_KEY = {SUP:'ec_sign_sup', WH:'ec_sign_wh', TD:'ec_sign_td', APPROVE:'ec_sign_appr', CTRL:'ec_sign_ctrl'};
var SET_DEPTS = null;
function buildSettings(){
    var h = '';
    $.each(DICT.stages, function(k, label){
        var key = STAGE_KEY[k];
        if (!key) return;
        h += '<div class="fld half"><label>' + esc(label) + '</label><select class="form-control set-sign" data-k="'+key+'">';
        $.each(DICT.sign_sources, function(v, t){
            h += '<option value="'+esc(v)+'"'+(SETTINGS[key] === v ? ' selected' : '')+'>'+esc(t)+'</option>';
        });
        h += '</select>'
          // 選「指定人員」才展開：先選課室、再從該課室（含子部門）勾人，可複選
          +  '<div class="pick-wrap" data-k="'+key+'" style="display:none;margin-top:4px;border:1px solid #E8D5B5;'
          +    'border-radius:4px;padding:6px;background:#FFF7E8;">'
          +    '<select class="form-control input-sm pick-dept" data-k="'+key+'" data-eg-filter="輸入課室名稱篩選…">'
          +      '<option value="">— 先選課室 —</option></select>'
          +    '<div class="pick-list" data-k="'+key+'" style="max-height:150px;overflow:auto;margin-top:4px;'
          +      'background:#fff;border:1px solid #EFE3D0;border-radius:3px;padding:4px;font-size:12px;">'
          +      '<span style="color:#aaa;">請先選課室</span></div>'
          +    '<div class="pick-sel" data-k="'+key+'" style="font-size:11px;color:#7A4A12;margin-top:3px;"></div>'
          +  '</div>'
          + '</div>';
    });
    $('#setSigns').html(h);
    // 記住每一關已勾選的人員（跨課室切換也不會掉）
    $('.pick-wrap').each(function(){
        var k = $(this).data('k');
        $(this).data('picked', String(SETTINGS[k + '_users'] || '').split(',').filter(Boolean));
    });
    withSetDepts(function(depts){
        $('.pick-dept').each(function(){
            var k = $(this).data('k'), $d = $(this);
            depts.forEach(function(d){ $d.append('<option value="'+d.id+'">'+esc(d.name)+'</option>'); });
            var cur = String(SETTINGS[k + '_dept'] || '');
            if (cur) { $d.val(cur); loadPickPeople(k, cur); }
            renderPickSel(k);
        });
    });
    $('.set-sign').each(function(){ togglePick($(this).data('k'), this.value); });
    $('#set_auto').prop('checked', +SETTINGS.ec_auto_from_dwg === 1);
    buildAttachSettings();
    showAsDoc();
    loadStampTemplates();
}

/* 附件設定：可挑選的標籤（逐段勾選）＋ 各變更方式的附件規則 ＋ 提示文字 */
function buildAttachSettings(){
    $('#set_hint_apply').val(SETTINGS.ec_attach_hint_apply || '');
    $('#set_hint_design').val(SETTINGS.ec_attach_hint_design || '');
    var rh = '';
    $.each(DICT.change_types, function(ct, label){
        var cur = SETTINGS['ec_attach_rule_' + ct] || 'optional';
        rh += '<div class="att-row" style="border-bottom:1px dashed #EFE3D0;">'
           +  '<span class="att-file">' + esc(label) + '</span>'
           +  '<select class="form-control set-attrule" data-ct="' + esc(ct) + '" data-eg-skip '
           +    'style="width:auto;height:28px;font-size:12px;">';
        $.each(DICT.attach_rules, function(v, t){
            rh += '<option value="'+esc(v)+'"'+(cur === v ? ' selected' : '')+'>'+esc(t)+'</option>';
        });
        rh += '</select></div>';
    });
    $('#setAttachRules').html(rh);
    // 標籤清單走 API 即時查（不在本頁寫死一份＝鐵律4）
    $.getJSON(API, {action:'attach_cat_list'}, function(r){
        if (!r.ok) return;
        [['apply', '#catPickApply', 'ec_attach_cats_apply'], ['design', '#catPickDesign', 'ec_attach_cats_design']]
        .forEach(function(p){
            var picked = String(SETTINGS[p[2]] || '').split(',').filter(Boolean);
            var h = '';
            (r.rows || []).forEach(function(c){
                h += '<label class="chk" style="margin:1px 0;"><input type="checkbox" class="cat-u" data-slot="'+p[0]+'" value="'+c.id+'"'
                  +  (picked.indexOf(String(c.id)) >= 0 ? ' checked' : '') + '> ' + esc(c.name) + '</label>';
            });
            $(p[1]).html(h || '<span style="color:#aaa;">目前沒有啟用中的附件標籤</span>');
        });
    });
}
function showAsDoc(){
    $('#setAsDoc').text(AS_DOC ? (AS_DOC.doc_no + '　' + AS_DOC.doc_name) : '（未綁定）');
}
function loadStampTemplates(){
    // 圖章模板清單直接讀圖章管理的 API（不在本頁另存一份清單＝鐵律4）
    $.getJSON('../../src/store/store_Stamp_API.php', {action:'tpl_list'}, function(r){
        // 只列啟用中的模板：停用的模板選了也印不出來（ec_stamp_template 會回 null 退成預設回墨印）
        var list = ((r && r.rows) || []).filter(function(t){ return +t.is_active === 1; });
        [['#set_stamp','ec_stamp_tpl_id'], ['#set_rv_stamp','ec_review_stamp_tpl_id']].forEach(function(p){
            var s = $(p[0]).empty().append('<option value="">（預設回墨印）</option>');
            list.forEach(function(t){
                s.append('<option value="'+t.id+'"'+(String(SETTINGS[p[1]]||'') === String(t.id) ? ' selected' : '')+'>'
                       + esc(t.tpl_name || t.name || ('#'+t.id)) + '</option>');
            });
        });
    }).fail(function(){ /* 圖章管理沒開放時就只留預設選項，不影響其他設定 */ });
}
function withSetDepts(cb){
    if (SET_DEPTS) return cb(SET_DEPTS);
    $.getJSON(API, {action:'departments'}, function(r){ if (r.ok) { SET_DEPTS = r.rows || []; cb(SET_DEPTS); } });
}
function togglePick(key, src){ $('.pick-wrap[data-k="'+key+'"]').toggle(src === 'users'); }
$(document).on('change', '.set-sign', function(){ togglePick($(this).data('k'), this.value); });
$(document).on('change', '.pick-dept', function(){ loadPickPeople($(this).data('k'), this.value); });
function loadPickPeople(key, deptId){
    var $box = $('.pick-list[data-k="'+key+'"]');
    if (!deptId) { $box.html('<span style="color:#aaa;">請先選課室</span>'); return; }
    $box.html('<span style="color:#aaa;">載入中…</span>');
    $.getJSON(API, {action:'dept_people', dept_id:deptId}, function(r){
        if (!r.ok) return;
        var picked = $('.pick-wrap[data-k="'+key+'"]').data('picked') || [];
        var h = '';
        (r.rows || []).forEach(function(u){
            var on = picked.indexOf(String(u.id)) >= 0;
            // 欄位順序固定「部門/職稱/姓名」（人員列表鐵則第 5 條）
            h += '<label class="chk" style="margin:1px 0;"><input type="checkbox" class="pick-u" data-k="'+key+'" value="'+u.id+'"'
              +  (on ? ' checked' : '') + '> '
              +  esc([u.dept_name || '', u.position_name || '', u.user_cname || ''].filter(Boolean).join('　'))
              +  (u.leave_note ? '<span style="color:#DD5138;">（'+esc(u.leave_note)+'）</span>' : '')
              +  '</label>';
        });
        $box.html(h || '<span style="color:#aaa;">這個課室底下沒有在職人員</span>');
    });
}
$(document).on('change', '.pick-u', function(){
    var key = $(this).data('k'), $w = $('.pick-wrap[data-k="'+key+'"]');
    var picked = ($w.data('picked') || []).slice();
    var v = String(this.value), i = picked.indexOf(v);
    if (this.checked) { if (i < 0) picked.push(v); } else if (i >= 0) picked.splice(i, 1);
    $w.data('picked', picked);
    renderPickSel(key);
});
function renderPickSel(key){
    var picked = $('.pick-wrap[data-k="'+key+'"]').data('picked') || [];
    var names = [];
    $('.pick-u[data-k="'+key+'"]').each(function(){
        if (picked.indexOf(String(this.value)) >= 0) names.push($(this).parent().text().trim());
    });
    $('.pick-sel[data-k="'+key+'"]').html(picked.length
        ? ('已選 ' + picked.length + ' 人：' + esc(names.join('、')) + '（其中任一人簽了就算過這一關）')
        : '<span style="color:#DD5138;">尚未勾選任何人——這一關會沒有人可以簽</span>');
}
$('#btnSetting').on('click', function(){ openMask('setMask'); });
var ASDOCS = null;
function withAsDocs(cb){
    if (ASDOCS) return cb(ASDOCS);
    $.getJSON(API, {action:'asdoc_list'}, function(r){ if (r.ok) { ASDOCS = r.docs || []; cb(ASDOCS); } });
}
$('#btnPickAsDoc').on('click', function(){
    // AS 文件挑選一律走共用挑選器（打編號即時篩選），禁止純下拉＝ai-rules/16 第一之三節
    if (!window.EGAsDoc || !EGAsDoc.open) { alert('AS 文件挑選器尚未載入，請重新整理頁面'); return; }
    withAsDocs(function(docs){
        EGAsDoc.open({docs:docs, current:(AS_DOC ? AS_DOC.id : 0), title:'工程變更申請單　綁定 AS 文件編號',
            onSave:function(id){
                post({action:'save_asdoc', doc_id:id}, function(r){
                    if (!r.ok) return;
                    AS_DOC = r.as_doc; showAsDoc();
                });
            }});
    });
});
$('#btnClearAsDoc').on('click', function(){
    if (!confirm('取消綁定後，列印的表頭名稱與頁尾編號會變成空白。確定？')) return;
    post({action:'save_asdoc', doc_id:0}, function(r){ if (r.ok) { AS_DOC = r.as_doc; showAsDoc(); } });
});
$('#btnSetSave').on('click', function(){
    var p = {action:'save_setting', ec_auto_from_dwg: $('#set_auto').is(':checked') ? 1 : 0,
             ec_stamp_tpl_id: $('#set_stamp').val() || '', ec_review_stamp_tpl_id: $('#set_rv_stamp').val() || '',
             ec_attach_hint_apply: $('#set_hint_apply').val() || '',
             ec_attach_hint_design: $('#set_hint_design').val() || ''};
    ['apply','design'].forEach(function(slot){
        p['ec_attach_cats_' + slot] = $('.cat-u[data-slot="'+slot+'"]:checked')
            .map(function(){ return this.value; }).get().join(',');
    });
    $('.set-attrule').each(function(){ p['ec_attach_rule_' + $(this).data('ct')] = this.value; });
    // 設成「必選附件」卻一個標籤都沒開放＝申請人永遠挑不到附件、也永遠送不出去，先擋下來
    var badRule = '';
    $('.set-attrule').each(function(){
        if (this.value === 'required' && !p.ec_attach_cats_apply)
            badRule = $(this).closest('.att-row').find('.att-file').text();
    });
    if (badRule) { alert('「' + badRule + '」設成必選附件，但「申請內容可挑的標籤」一個都沒有勾——'
                       + '這樣申請人永遠挑不到附件、也永遠送不出單。請先勾選至少一個標籤。'); return; }
    $('.set-sign').each(function(){ p[$(this).data('k')] = this.value; });
    var bad = '';
    $('.pick-wrap').each(function(){
        var k = $(this).data('k'), picked = $(this).data('picked') || [];
        p[k + '_users'] = picked.join(',');
        p[k + '_dept']  = $('.pick-dept[data-k="'+k+'"]').val() || '';
        // 選了「指定人員」卻一個都沒勾＝那一關永遠沒人簽得下去，先擋下來
        if ($('.set-sign[data-k="'+k+'"]').val() === 'users' && !picked.length) bad = k;
    });
    if (bad) { alert('有關卡選了「指定人員」卻沒有勾選任何人，那一關會沒有人可以簽。請先勾選人員。'); return; }
    post(p, function(r){ if (!r.ok) return; SETTINGS = r.settings; alert('已儲存設定'); closeMask('setMask'); });
});
<?php else: ?>
function buildSettings(){}
<?php endif; ?>

/* ══════════════════ 刪除 ══════════════════ */
<?php if ($P['canAdmin']): ?>
$('#btnDelSel').on('click', function(){
    var ids = selIds();
    if (!ids.length) { alert('請先勾選要刪除的申請單'); return; }
    if (!confirm('確定刪除勾選的 ' + ids.length + ' 張申請單？此動作無法復原。')) return;
    var i = 0;
    (function next(){
        if (i >= ids.length) { loadList(); return; }
        post({action:'delete', ec_id:ids[i++]}, function(){ next(); });
    })();
});
<?php endif; ?>

/* ══════════════════ 列印（A4 直式 1:1） ══════════════════ */
$('#btnEcPrint').on('click', function(){ if (CUR) printQueue([CUR.ec_id], 0); });
$('#btnPrintSel').on('click', function(){
    var ids = selIds();
    if (!ids.length) { alert('請先勾選要列印的申請單'); return; }
    if (ids.length > 15 && !confirm('要列印 ' + ids.length + ' 份，會逐份開視窗排隊。確定？')) return;
    printQueue(ids, 0);
});
/* 批次列印＝依序各自開視窗排隊（ai-rules/16 第三之五節），上一份關閉才開下一份；
   一次列印只記一筆列印紀錄（ai-rules/23）。 */
function printQueue(ids, i){
    if (i >= ids.length) return;
    $.getJSON(API, {action:'print_meta', id:ids[i]}, function(res){
        if (!res.ok) { printQueue(ids, i+1); return; }
        // 圖章模板的 {公司} token 取自這個全域值，蓋章前要先設好
        window.__ownCompany = (res.meta && res.meta.company) || window.__ownCompany || '';
        // 掃描實體章對照表是非同步載入的，沒等它的話有實體章的人會印成預設 SVG 章
        var go = function(){
            var w = window.open('', '_blank');
            if (!w) { alert('請允許彈出視窗'); return; }
            w.document.write(printHtml(res));
            w.document.close();
            post({action:'log_print', ids:JSON.stringify([ids[i]])}, function(){});
            var t = setInterval(function(){ if (w.closed) { clearInterval(t); printQueue(ids, i+1); } }, 700);
        };
        if (window.EGStamp && EGStamp.whenReady) EGStamp.whenReady(go); else go();
    });
}

function printHtml(res){
    var d = res.row, m = res.meta, rvs = res.reviews || [];
    var box = function(on){ return on ? '☑' : '☐'; };
    var sg = function(key){
        var s = m.signs[key];
        if (!s || !s.name) return '';
        return stampHtml(s.name, dispDate(s.date), s.is_agent, m.stamp_tpl, s.dept, s.position);
    };
    var rvSg = function(key){
        var s = m.review_signs[key];
        if (!s || !s.name) return '';
        return stampHtml(s.name, dispDate(s.date), s.is_agent, m.review_stamp_tpl, s.dept, s.position);
    };
    var ct = d.change_type || '';
    var dr = d.design_result || '';
    var os = d.old_stock || '';
    var vd = d.verdict || '';

    /* 附件（使用者指定的格式 2026-09-23）：編號＋一個空白＋標籤名稱＋附件備註。
       編號由後端算好（print_text），畫面、這裡、以及「列印所有附件」右上角都用同一份，
       三個地方各自重排遲早對不起來。 */
    var attOfSlot = function(slot){
        return (m.attachments || []).filter(function(a){ return a.slot === slot; });
    };
    var attLines = function(slot){
        var list = attOfSlot(slot);
        if (!list.length) return '';
        return '<br>附件：' + list.map(function(a){
            return esc(a.print_text) + (a.page_no ? '（第 ' + a.page_no + ' 頁）' : '');
        }).join('　');
    };

    // 會審六列（紙本固定全部列出，不需會審的也印出來留白）
    var rvRows = rvs.map(function(rv){
        var def = DICT.review_units[rv.unit_key] || {checks:{}, extras:{}};
        var lines = '';
        $.each(def.checks, function(k, label){
            lines += box(rv.needed && rv.checks[k]) + ' ' + label + '<br>';
        });
        // 額外欄位併成同一行（生管組有 3 個，各佔一行會把單頁撐破）
        var ex = [];
        $.each(def.extras, function(k, label){ ex.push(label + '：' + (rv.extras[k] || '')); });
        if (ex.length) lines += ex.join('　') + '<br>';
        if (rv.opinion) lines += '<span class="op">' + esc(rv.opinion) + '</span>';
        return '<tr><td class="rvbody">' + lines + '</td>'
             + '<td class="rvname">' + box(rv.needed) + esc(rv.label) + '</td>'
             + '<td class="rvsig">' + rvSg(rv.unit_key) + '</td></tr>';
    }).join('');

    /* A4 直式 1:1：@page size A4 portrait margin 0，版面全部以 mm 定寸，
       瀏覽器不做縮放 → 圖章實際大小＝設計大小，不會失真。 */
    var css = '@page{size:A4 portrait;margin:0;'
        + '@bottom-right{content:"' + (m.as_doc_no || '') + '";font-size:8pt;}}'
        + 'html,body{margin:0;padding:0;}'
        + 'body{width:210mm;font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;'
        +   'padding:6mm 6mm 5mm;box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        + '.co{font-size:14pt;font-weight:bold;text-align:center;letter-spacing:3px;}'
        + '.co small{display:block;font-size:8pt;font-weight:normal;letter-spacing:0;}'
        + '.tt{font-size:19pt;font-weight:bold;text-align:center;letter-spacing:8px;margin:0.8mm 0 0.4mm;}'
        /* 文件編號靠左、日期靠右（使用者要求 2026-09-23） */
        + '.ymd{display:flex;justify-content:space-between;font-size:9pt;margin-bottom:0.6mm;}'
        + 'table{border-collapse:collapse;width:194mm;table-layout:fixed;}'
        + 'td,th{border:0.4mm solid #000;padding:0.6mm 1.2mm;font-size:9.5pt;vertical-align:middle;word-break:break-all;}'
        + '.lb{background:#F2F2F2;text-align:center;font-weight:bold;white-space:nowrap;}'
        + '.lbs{background:#F2F2F2;text-align:center;font-weight:bold;font-size:8.5pt;line-height:1.25;'
        /* 標籤只在我們自己放的 <br> 斷行，不讓瀏覽器把「(更新圖面需附上)」攔腰折成「…需附／上)」 */
        +   'white-space:nowrap;word-break:keep-all;padding:0.6mm 0.4mm;}'
        + '.lbs .sub{display:block;font-size:6.5pt;font-weight:normal;line-height:1.15;'
        /* 附註是括號說明，允許自然折行；主標題那幾個字才維持 nowrap 不被攔腰折斷 */
        +   'white-space:normal;word-break:break-all;margin-bottom:0.4mm;}'
        + '.sig{height:18mm;text-align:center;vertical-align:middle;padding:0.5mm;}'
        + '.opt{font-size:9pt;line-height:1.35;text-align:left;}'
        /* 內容少的格子一律靠上，不要浮在儲存格中間（使用者要求 2026-09-23） */
        + '.optT{vertical-align:top;}'
        /* 設變事由說明：縮排掛在「其他變更」底下 */
        + '.rsn{margin-left:5mm;margin-top:0.6mm;}'
        + '.rsn .h{font-size:8pt;color:#333;}'
        + '.rsn .b{min-height:8mm;border:0.25mm dashed #999;padding:0.6mm 1mm;font-size:9pt;}'
        + '.rvbody{font-size:7.5pt;line-height:1.28;text-align:left;height:9mm;vertical-align:top;padding:0.5mm 1.2mm;}'
        + '.rvname{width:22mm;text-align:center;font-size:9pt;background:#F2F2F2;font-weight:bold;}'
        + '.rvsig{width:34mm;height:9mm;text-align:center;padding:0.5mm;}'
        + '.op{display:block;margin-top:0.6mm;}'
        /* 流程註記改放頁尾附註**上方**、橫排一整行（使用者要求 2026-09-23：
           原本放在申請單位表格右下角，改成顯示在「※此表單底稿由技術課存查…」上方）。 */
        + '.flowline{font-size:8pt;text-align:center;letter-spacing:0.5px;margin-top:1.5mm;}'
        + '.ft{font-size:8pt;margin-top:0.8mm;}'
        /* 頁尾附註下方的簽核紀錄（可設定是否列印） */
        + 'table.siglog{margin-top:1.5mm;}'
        + 'table.siglog td{font-size:8pt;padding:0.4mm 1.2mm;}'
        /* AS 文件編號固定在頁面實際右下角（ai-rules/16 第三節：不可用 inline，內容短時會離右下角很遠） */
        + '.as-doc-fixed{position:fixed;right:6mm;bottom:5mm;font-size:8pt;}'
        + '.divider{text-align:center;font-size:8.5pt;font-weight:bold;margin:0.8mm 0 0.4mm;}'
        /* 一列會審不要被切成兩半跨頁（內容特別多時的保險） */
        + 'tr{page-break-inside:avoid;}';

    var h = '<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="utf-8">'
        + '<title>' + esc(d.doc_no || '工程變更申請單') + '</title><style>' + css + '</style></head><body>'
        + '<div class="co">' + esc(m.company || '') + '</div>'
        + '<div class="tt">' + esc(m.as_doc_name || '工程變更申請/審查/通知單') + '</div>'
        + '<div class="ymd"><span>文件編號：' + esc(d.doc_no || '') + '</span>'
        +   '<span>日期：' + dispDate(d.apply_date) + '</span></div>'

        // 表頭
        + '<table><colgroup><col style="width:22mm"><col style="width:52mm"><col style="width:20mm">'
        +   '<col style="width:44mm"><col style="width:22mm"><col style="width:34mm"></colgroup>'
        + '<tr><td class="lb">客戶名稱</td><td>' + esc(d.customer_name || '') + '</td>'
        +     '<td class="lb">料　號</td><td>' + esc(d.part_no || '') + '</td>'
        +     '<td class="lb">申請單位</td><td>' + esc(d.apply_dept_name || '') + '</td></tr>'
        + '</table>'

        /* 變更方式 ＋ 申請人/單位主管簽章。使用者要求 2026-09-23：
             設變事由說明掛在「其他變更」底下（不再自成一列）。
             流程註記已改移到頁尾附註上方（見下方 .flowline），這裡不再佔一欄。 */
        + '<table><colgroup><col style="width:22mm"><col style="width:116mm"><col style="width:22mm">'
        +   '<col style="width:34mm"></colgroup>'
        + '<tr><td class="lb" rowspan="2">變更方式</td>'
        +     '<td class="opt optT" rowspan="2">'
        +       box(ct === 'customer_notify') + ' 客戶通知變更(包含新訂單版次變更)<br>'
        +       box(ct === 'blueprint_error') + ' 客戶藍圖有誤，通知客戶之建議變更(客戶同意後需附上新版客戶藍圖)<br>'
        +       box(ct === 'other') + ' 其他變更(請於設變事由說明內詳述)'
        +       '<div class="rsn"><div class="h">設變事由說明（僅其他變更須填寫）</div>'
        +         '<div class="b">' + esc(d.change_reason || '').replace(/\n/g, '<br>') + '</div></div>'
        +       attLines('apply') + '</td>'
        +     '<td class="lbs">申請人</td><td class="sig">' + sg('applicant') + '</td></tr>'
        + '<tr><td class="lbs">單位主管</td><td class="sig">' + sg('sup') + '</td></tr>'
        + '</table>'

        // 確認庫存（倉管組）
        + '<table><colgroup><col style="width:22mm"><col style="width:116mm"><col style="width:22mm"><col style="width:34mm"></colgroup>'
        + '<tr><td class="lbs">確認庫存</td>'
        +     '<td class="opt optT">' + (+d.single_process === 1
                ? '☑ 單一製程，不需確認庫存'
                : ('庫存數量：' + esc(d.stock_qty || '') + '<br>已完工待入庫數量：' + esc(d.wip_qty || ''))) + '</td>'
        +     '<td class="lbs">倉管組</td><td class="sig">' + sg('wh') + '</td></tr>'
        + '</table>'

        // 設計分析（技術課）＋ 庫存舊料
        + '<table><colgroup><col style="width:22mm"><col style="width:116mm"><col style="width:22mm"><col style="width:34mm"></colgroup>'
        + '<tr><td class="lbs"><span class="sub">(更新圖面需附上)</span>設計分析</td>'
        // 單一製程只印在「確認庫存」那一格，這裡不重複（使用者要求 2026-09-23）
        +     '<td class="opt optT">' + box(dr === 'drawing_only') + ' 僅修改圖面(修改後結案)<br>'
        +       box(dr === 'need_review') + ' 需修改圖面與會審(單據續跑，下方需勾選)'
        +       attLines('design')
        +       (d.design_note ? '<br>' + esc(d.design_note) : '') + '</td>'
        +     '<td class="lbs">技術課</td><td class="sig">' + sg('td') + '</td></tr>'
        // 單一製程時庫存舊料不需要判定，直接印「不需確認」（使用者要求 2026-09-23）
        + '<tr><td colspan="4" class="opt">庫存舊料：' + (+d.single_process === 1
                ? '不需確認'
                : (box(os === 'can') + ' 可修改　' + box(os === 'cannot') + ' 無法修改(轉業務確認客戶收貨或報廢)'))
        +       '</td></tr>'
        + '</table>'

        // 核示
        + '<table><colgroup><col style="width:22mm"><col style="width:116mm"><col style="width:22mm"><col style="width:34mm"></colgroup>'
        + '<tr><td class="lb" rowspan="2">核示</td>'
        +     '<td class="opt optT">' + box(vd === 'approve') + ' 准予變更　' + box(vd === 'hold') + ' 暫緩變更　'
        +       box(vd === 'other') + ' 其他　' + esc(d.verdict_other || '') + '</td>'
        +     '<td class="lbs" rowspan="2">核准</td><td class="sig" rowspan="2">' + sg('appr') + '</td></tr>'
        + '<tr><td class="opt" style="height:7mm;vertical-align:top;">補充意見：'
        +       esc(d.verdict_note || '').replace(/\n/g, '<br>') + '</td></tr>'
        + '</table>'

        + '<div class="divider">↓以下僅技術課判定需會審才填寫↓</div>'

        // 相關單位會審（使用者要求 2026-09-23：分隔線下方要有表頭「單位會簽」）
        + '<table><colgroup><col style="width:138mm"><col style="width:22mm"><col style="width:34mm"></colgroup>'
        + '<tr><td class="lb">單位會簽</td><td class="lb">單　位</td><td class="lb">簽　章</td></tr>'
        + rvRows
        + '</table>'

        // 管制
        + '<table style="margin-top:1mm;"><colgroup><col style="width:22mm"><col style="width:116mm"><col style="width:22mm"><col style="width:34mm"></colgroup>'
        + '<tr><td class="lb">管制</td>'
        // 圖面固定勾選（使用者要求 2026-09-23）：工程變更一定會動到圖面，紙本上那一格永遠是打勾的
        +     '<td class="opt optT">需修改文件資料：' + box(true) + ' 圖面　'
        +       box(+d.ctrl_bom === 1) + ' BOM　' + box(+d.ctrl_manual === 1) + ' 操作手冊</td>'
        +     '<td class="lbs">管制員</td><td class="sig">' + sg('ctrl') + '</td></tr>'
        + '</table>'

        + '<div class="flowline">流程：申請單位↓倉管↓技術↓其他單位(僅需會審者)↓技術</div>'
        + '<div class="ft">※此表單底稿由技術課存查　※文件編號以西元年月日加流水號，例如：20220101001</div>'
        /* 簽核紀錄（管理員可設定是否列印）。
           ★這一塊**絕對不可以出現「管理員○○○代簽」字樣**（使用者明確要求）——
             資料來自 meta.sign_log，後端組的時候就只放 關卡／簽核人／部門職稱／日期，
             proxy 欄位一個都不帶出來，所以前端這裡不可能印得出來。 */
        + (( +m.print_sign_log === 1 && (m.sign_log || []).length)
            ? ('<table class="siglog"><colgroup><col style="width:34mm"><col style="width:46mm">'
              +  '<col style="width:76mm"><col style="width:38mm"></colgroup>'
              +  '<tr><td class="lb" colspan="4">簽核紀錄</td></tr>'
              +  m.sign_log.map(function(x){
                    return '<tr><td class="lb">' + esc(x.label) + '</td>'
                         + '<td>' + esc(x.name) + '</td>'
                         + '<td>' + esc([x.dept, x.position].filter(Boolean).join('　')) + '</td>'
                         + '<td>' + dispDate(x.date) + '</td></tr>'; }).join('')
              + '</table>')
            : '')
        + (m.as_doc_no ? '<div class="as-doc-fixed">' + esc(m.as_doc_no) + '</div>' : '')
        // 頁碼左下角、且「多頁才顯示」（ai-rules/16）：CSS 無法依 counter(pages) 條件顯示，
        // 改由列印視窗自己量高度，真的超過一頁才注入 @bottom-left。
        + '<script>window.onload=function(){'
        +   'var onePage=Math.round(297/25.4*96);'
        +   'if(document.body.scrollHeight>onePage+4){'
        +     'var st=document.createElement("style");'
        +     'st.textContent=\'@page{@bottom-left{content:"第 " counter(page) " 頁／共 " counter(pages) " 頁";font-size:8pt;}}\';'
        +     'document.head.appendChild(st);}'
        +   'window.print();};<\/script>'
        + '</body></html>';
    return h;
}

/* ══════════════════ 一鍵列印所有附件（使用者要求 2026-09-23）══════════════════
   ★每一張附件的**右上角固定印該附件的編號**（附件1、附件2…），與表單上那一行的編號是同一份。
   PDF 一律用 pdf.js 畫成圖再放進列印視窗——直接用 <embed>／<iframe> 塞 PDF，
   瀏覽器的列印只會印外層那張空白頁（不同瀏覽器行為還不一樣），這條路走不通。
   指定了頁的只印那一頁，沒指定的整份逐頁印。畫不出來的檔（Word／Excel）印一張說明頁，
   不要安靜地跳過——少印一張附件現場不會發現。 */
$('#btnEcPrintAtt').on('click', function(){
    if (!CUR || !CUR_ATT.length) { alert('這張單目前沒有選定任何附件'); return; }
    printAttachments(CUR_ATT.slice(), CUR);
});
function attExt(name){ return String(name || '').split('.').pop().toLowerCase(); }
function printAttachments(list, row){
    var w = window.open('', '_blank');
    if (!w) { alert('請允許彈出視窗'); return; }
    w.document.write('<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="utf-8">'
        + '<title>' + esc(row.doc_no || '') + ' 附件</title><style>'
        + '@page{size:A4 portrait;margin:8mm;}'
        + 'html,body{margin:0;padding:0;font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;}'
        + '.pg{page-break-after:always;position:relative;text-align:center;}'
        + '.pg:last-child{page-break-after:auto;}'
        + '.no{position:absolute;top:0;right:0;font-size:12pt;font-weight:bold;letter-spacing:1px;}'
        + '.cap{font-size:9pt;color:#333;text-align:left;margin:0 0 2mm;padding-right:28mm;}'
        + '.im{max-width:100%;max-height:262mm;}'
        + '.na{border:0.4mm dashed #999;padding:20mm 10mm;font-size:11pt;color:#333;margin-top:20mm;}'
        + '</style></head><body><div id="bd"><div style="padding:20mm;color:#888;">正在準備附件，請稍候…</div></div></body></html>');
    w.document.close();

    var done = [];
    var step = function(i){
        if (i >= list.length) {
            var bd = w.document.getElementById('bd');
            bd.innerHTML = done.join('');
            // 圖片要真的解碼完才印得出來，否則會印出一片空白
            var imgs = bd.getElementsByTagName('img'), left = imgs.length;
            var go = function(){ if (--left <= 0) setTimeout(function(){ w.focus(); w.print(); }, 250); };
            if (!imgs.length) { setTimeout(function(){ w.focus(); w.print(); }, 250); }
            else for (var k = 0; k < imgs.length; k++) {
                if (imgs[k].complete) go(); else { imgs[k].onload = go; imgs[k].onerror = go; }
            }
            post({action:'log_print', ids:JSON.stringify([row.ec_id])}, function(){});
            return;
        }
        var a = list[i];
        var url = '../../src/store/Part_Attachment_API.php?action=download&id=' + a.attach_id;
        var cap = '<div class="no">' + esc(a.seq_label) + '</div>'
                + '<div class="cap">' + esc(a.print_text)
                + '　' + esc(a.orig_name) + (a.page_no ? '（第 ' + a.page_no + ' 頁）' : '') + '</div>';
        var ext = attExt(a.file_name || a.orig_name);
        if (['jpg','jpeg','png','gif','webp','bmp'].indexOf(ext) >= 0) {
            done.push('<div class="pg">' + cap + '<img class="im" src="' + url + '"></div>');
            step(i + 1); return;
        }
        if (ext !== 'pdf') {
            done.push('<div class="pg">' + cap + '<div class="na">這個附件是 <b>.' + esc(ext)
                + '</b> 檔，系統無法直接轉成可列印的頁面。<br>請自行開啟該檔列印，並在紙本右上角註明「'
                + esc(a.seq_label) + '」。</div></div>');
            step(i + 1); return;
        }
        // PDF：指定頁只畫那一頁，沒指定就整份逐頁
        loadPdfJs().then(function(lib){ return lib.getDocument(url).promise; }).then(function(pdf){
            var from = a.page_no ? a.page_no : 1, to = a.page_no ? a.page_no : pdf.numPages;
            if (from > pdf.numPages) { from = to = pdf.numPages; }
            var chain = Promise.resolve();
            for (var p = from; p <= to; p++) (function(pn){
                chain = chain.then(function(){
                    return pdf.getPage(pn).then(function(page){
                        var vp0 = page.getViewport({scale:1});
                        var scale = Math.min(1600 / vp0.width, 3);   // 1600px 寬足夠 A4 列印又不會讓檔案爆掉
                        var vp = page.getViewport({scale:scale});
                        var cv = w.document.createElement('canvas');
                        cv.width = Math.ceil(vp.width); cv.height = Math.ceil(vp.height);
                        return page.render({canvasContext:cv.getContext('2d'), viewport:vp}).promise.then(function(){
                            done.push('<div class="pg">' + cap.replace('</div><div class="cap">',
                                        '</div><div class="cap">')
                                   + '<img class="im" src="' + cv.toDataURL('image/jpeg', 0.9) + '"></div>');
                        });
                    });
                });
            })(p);
            return chain;
        }).then(function(){ step(i + 1); })
          .catch(function(e){
            done.push('<div class="pg">' + cap + '<div class="na">這個 PDF 讀不出來（' + esc(e.message || '')
                + '），請自行開啟列印，並在紙本右上角註明「' + esc(a.seq_label) + '」。</div></div>');
            step(i + 1);
          });
    };
    step(0);
}

$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
$('#btnRoleHelp').on('click', function(){ openMask('roleMask'); });
</script>
</body>
</html>
