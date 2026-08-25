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
                        <small style="font-weight:normal;color:#aaa;">（選了自動帶客戶）</small></label>
                    <input type="text" id="e_part_kw" class="form-control" placeholder="輸入料號或客戶關鍵字後選擇…" list="partList">
                    <datalist id="partList"></datalist><div class="err"></div></div>
                <div class="fld half"><label>客戶名稱 <span style="color:#DD5138">*</span>
                        <small style="font-weight:normal;color:#aaa;">（由料號綁定產生，不可修改）</small></label>
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
                <div class="fld full"><label>變更方式 <span style="color:#DD5138">*</span></label>
                    <div id="e_ctype"></div><div class="err"></div></div>
                <div class="fld full"><label>設變事由說明
                        <small style="font-weight:normal;color:#aaa;">（僅「其他變更」須填寫；請簡述變更原因，例：生產課因架機需求提出變更…）</small></label>
                    <textarea id="e_reason" class="form-control" rows="3"></textarea><div class="err"></div></div>
            </div>
        </div></div>

        <!-- 倉管：確認庫存 -->
        <div class="sec"><div class="sh">確認庫存（倉管組）</div><div class="sb">
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
        <div class="sec"><div class="sh">設計分析（技術課）</div><div class="sb">
            <div class="fld"><label>更新圖面需附上</label><div id="e_design"></div><div class="err"></div></div>
            <div class="fld" style="margin-top:6px;"><label>1. 庫存舊料</label><div id="e_oldstock"></div><div class="err"></div></div>
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
        <div class="sec"><div class="sh">核示</div><div class="sb">
            <div class="fld"><label>核示結果</label><div id="e_verdict"></div><div class="err"></div></div>
            <div class="fld" style="margin-top:6px;display:none;" id="e_verdict_other_wrap"><label>其他（請說明）</label>
                <input type="text" id="e_verdict_other" class="form-control"><div class="err"></div></div>
            <div class="fld" style="margin-top:6px;"><label>補充意見</label>
                <textarea id="e_verdict_note" class="form-control" rows="2"></textarea></div>
        </div></div>

        <!-- 會審 -->
        <div class="sec" id="reviewSec"><div class="sh">相關單位會審</div><div class="sb" id="reviewBody"></div></div>

        <!-- 管制 -->
        <div class="sec"><div class="sh">管制（技術課）</div><div class="sb">
            <div class="fld"><label>需修改文件資料</label>
                <label class="chk"><input type="checkbox" id="e_ctrl_drawing"> 圖面</label>
                <label class="chk"><input type="checkbox" id="e_ctrl_bom"> BOM</label>
                <label class="chk"><input type="checkbox" id="e_ctrl_manual"> 操作手冊</label>
                <div class="err"></div></div>
        </div></div>

        <div class="sec"><div class="sh">簽核紀錄</div><div class="sb">
            <table class="hist"><thead><tr><th>關卡</th><th>狀態</th><th>送出</th><th>簽核人</th><th>簽核時間</th><th>意見</th></tr></thead>
            <tbody id="histBody"></tbody></table>
        </div></div>
    </div>
    <div class="m-foot" style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;">
        <span id="ecHint" style="flex:1;text-align:left;font-size:12px;color:#8a6d45;align-self:center;"></span>
        <button class="ec-btn ghost" id="btnEcPrint"><i class="fa fa-print"></i> 列印</button>
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
        <div class="sec"><div class="sh">圖章模板</div><div class="sb">
            <div class="fgrid">
                <div class="fld half"><label>各關卡簽章</label>
                    <select id="set_stamp" class="form-control" data-eg-filter="輸入模板名稱篩選…"></select></div>
                <div class="fld half"><label>會審簽章</label>
                    <select id="set_rv_stamp" class="form-control" data-eg-filter="輸入模板名稱篩選…"></select></div>
            </div>
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
            <li><b>申請人</b>：填客戶、料號、變更方式與設變事由，按「送出」正式成立。</li>
            <li><b>單位主管</b>：申請部門的主管簽核。</li>
            <li><b>倉管組</b>：填「庫存數量」「已完工待入庫數量」後簽核。這兩個數字系統會<b>自動帶入讓你確認是否相同</b>——
                庫存數量取自庫存管理、已完工待入庫取自「未結案 BOM 中最後一道製程已完工」的批次；
                <b>實際以清點為準，可直接改</b>，也可以按「重新帶入系統數量」還原。</li>
            <li><b>技術課</b>：做設計分析——勾<b>「僅修改圖面（修改後結案）」</b>就不跑會審；
                勾<b>「需修改圖面與會審」</b>要一併勾選需要哪些單位會審。同時判定庫存舊料可否修改。</li>
            <li><b>核准</b>：核示「准予變更／暫緩變更／其他」，可填補充意見。</li>
            <li><b>相關單位會審</b>：<b>由技術課那一關的填寫人員決定哪些單位需要會審</b>，不是六個單位一律都會簽——
                沒有被勾選的單位不會收到通知、也不必簽。被勾選的單位<b>各自獨立、不分先後</b>，
                全部簽完才會往下一關（管制員）。</li>
            <li><b>管制員</b>：勾選需修改的文件資料（圖面／BOM／操作手冊），簽完即<b>結案</b>。
                管制員可以在<b>設定</b>裡指定某個課室底下的<b>特定幾個人（複選）</b>，其中任何一位簽了就算過這一關。</li>
        </ul>
        <div class="tip">任何一關都可以<b>退回</b>，退回<b>必須填原因</b>，系統會通知申請人；
            申請人修正後按「重新送出」會從第一關重跑。</div>

        <h4>誰可以簽？</h4>
        <ul>
            <li>簽核權<b>不看角色</b>：系統依「本單日期<b>當時</b>的職務」解析出各關卡該簽的人，是那個人才簽得下去。</li>
            <li>該簽的人請假／不在時，自動換成他的<b>代理人</b>，圖章右下角會多一個「代」字。</li>
            <li>各關卡要找誰簽可以在<b>設定</b>裡改（申請部門主管／各部門主管／最高核准人員…），一律即時解析，不寫死人名。</li>
            <li>管理員可以代簽任何一關（用於補歷史紙本，或當事人長期不在時把單子推動）。</li>
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
        <div class="tip">設定入口：右上工具列的「設定」（限管理員）——AS 文件綁定、各關卡簽章人來源、圖章模板、自動產生開關。</div>
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
        if (openId) openEc(openId);
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
function openEc(id){
    $.getJSON(API, {action:'get', id:id}, function(r){
        if (!r.ok) return;
        CUR = r.row; CUR_REVIEWS = r.reviews || [];
        fillEc(r);
        openMask('ecMask');
    });
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
    $('#e_ctrl_drawing').prop('checked', +d.ctrl_drawing === 1);
    $('#e_ctrl_bom').prop('checked', +d.ctrl_bom === 1);
    $('#e_ctrl_manual').prop('checked', +d.ctrl_manual === 1);
    $('.rv-need').prop('checked', false);
    CUR_REVIEWS.forEach(function(rv){ if (rv.needed) $('.rv-need[value="'+rv.unit_key+'"]').prop('checked', true); });

    loadPeople(String(d.apply_date||'').substring(0,10), d.applicant_id, d.apply_dept_id);
    loadStockSnap(d);
    renderReviews(r);
    renderHist(r.approvals || []);
    applyStageUI(d, r.signers || {});
}

/** 依關卡決定「哪一段可以填、哪些按鈕出現」——不是這一關的人一律唯讀（後端也會再擋一次） */
function applyStageUI(d, signers){
    var editHead = +d.can_edit === 1;
    $('#e_apply_date,#e_part_kw,#e_reason').prop('disabled', !editHead);
    $('#e_applicant,#e_post').prop('disabled', !editHead);
    if (!editHead) $('#e_post').prop('disabled', true);
    $('input[name=ctype]').prop('disabled', !editHead);

    var st = d.status, mine = +d.can_sign === 1;
    // 「提早填寫」：本身就在該課室的人可以先把自己那一段填好（填但不簽，使用者要求 2026-08-25）。
    // 輪到自己那一關時當然也能填，所以兩者取聯集。
    var pre = d.prefill || [];
    var may = function(stage){ return pre.indexOf(stage) >= 0 || (mine && st === stage); };
    var canWH = may('WH'), canTD = may('TD'), canCT = may('CTRL');
    var canAP = mine && st === 'APPROVE';      // 核示沒有「本身部門」的概念，仍只有核准人能填

    $('#e_stock_qty,#e_wip_qty').prop('disabled', !canWH);
    $('input[name=design],input[name=oldstock],.rv-need').prop('disabled', !canTD);
    $('#e_design_note').prop('disabled', !canTD);
    // 這一區一律顯示給「填得了技術課那一段」的人，不隨 radio 開開關關——
    // 整區消失會讓人以為系統沒有這個功能（使用者實際回報過）。
    CUR_CAN_TD = canTD;
    $('#e_review_pick').toggle(canTD || d.design_result === 'need_review' || hasNeeded());
    syncReviewPick();
    $('input[name=verdict]').prop('disabled', !canAP);
    $('#e_verdict_other,#e_verdict_note').prop('disabled', !canAP);
    $('#e_ctrl_drawing,#e_ctrl_bom,#e_ctrl_manual').prop('disabled', !canCT);
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

    var who = signers[st];
    $('#ecHint').text(
        st === 'CLOSED'  ? '已結案' :
        st === 'REJECTED'? '已退回，請修正後重新送出' :
        st === 'DRAFT'   ? '草稿（尚未送出，不會通知任何人）' :
        st === 'REVIEW'  ? '會審中，需會審的單位全部簽完才會進入管制關卡'
                         : ('目前等待：' + (DICT.stages[st] || '') + (who && who.name ? '　' + who.name : '（解析不到簽核人，請檢查組織角色綁定）')
                            + (who && who.for_name ? '（代理 ' + who.for_name + '）' : ''))
    );
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
            : '<span class="pill wait">待簽'+(rv.expect_name ? '：'+esc(rv.expect_name) : '')+'</span>';
        var btn = rv.can_sign
            ? ' <button class="ec-btn" style="height:24px;padding:0 10px;font-size:12px;" onclick="openReview(\''+rv.unit_key+'\')">填寫並簽名</button>' : '';
        h += '<div class="rv-card"><div class="rh">'+esc(rv.label)+right+btn+'</div>'+lines+'</div>';
    });
    $('#reviewBody').html(h || '<div style="color:#aaa;font-size:12px;">技術課尚未判定是否需要會審</div>');
}

function renderHist(rows){
    var h = '';
    rows.forEach(function(a){
        var stx = a.status === 'approved' ? '已簽核' : (a.status === 'rejected' ? '退回' : '待簽核');
        h += '<tr><td>'+esc(a.label)+'</td><td>'+stx+'</td><td>'+esc(a.submitted_by)+'</td>'
           + '<td>'+esc(a.approved_by)+'</td><td>'+esc(String(a.approved_at||'').substring(0,16))+'</td>'
           + '<td>'+esc(a.note||'')+'</td></tr>';
    });
    $('#histBody').html(h || '<tr><td colspan="6" style="color:#aaa;text-align:center;">尚無簽核紀錄</td></tr>');
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
$('#e_part_kw').on('input', function(){
    var kw = $(this).val().trim();
    if (kw.length < 2) return;
    $.getJSON(API, {action:'parts', kw:kw}, function(r){
        if (!r.ok) return;
        var dl = $('#partList').empty();
        (r.rows || []).forEach(function(p){
            PART_CACHE[p.part_no] = p;                 // 只累加不清空
            dl.append('<option value="'+esc(p.part_no)+'">'+esc(p.customer_name)+'</option>');
        });
    });
}).on('change blur', function(){
    pullPartCustomer($(this).val().trim());
});
function pullPartCustomer(pn){
    if (!pn) { setCustomer(null); return; }
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
}

/* ── 申請人：**除管理員外一律固定是開單的人**，不可更改（使用者要求 2026-08-25）。
      有兼任職務時，可以選要用哪一個部門／職稱的身分來申請（另一個下拉）。 ── */
function loadPeople(date, pickId, pickDept){
    var isAdmin = !!(PERMS && PERMS.canAdmin);
    if (!isAdmin) {
        // 一般使用者：人固定是自己，只顯示不給選（後端 create/save 也會強制綁回本人）
        $('#e_applicant').hide();
        $('#e_applicant_ro').show().val(ME.name);
        $('#e_applicant_hint').text('（固定為你本人，不可更改）');
        loadPosts(ME.uid, date, pickDept);
        return;
    }
    $('#e_applicant_ro').hide();
    $('#e_applicant').show();
    $('#e_applicant_hint').text('（管理員可代其他人開單；依日期列出當時在職者）');
    $.getJSON(API, {action:'people', date:date}, function(r){
        if (!r.ok) return;
        var seen = {}, s = $('#e_applicant').empty().append('<option value="">請選擇…</option>');
        (r.rows || []).forEach(function(p){
            if (seen[p.id]) return;                    // 一人多職只列一次，職務在另一個下拉選
            seen[p.id] = 1;
            s.append('<option value="'+p.id+'">'+esc(p.display)+'</option>');
        });
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
});
$('input[name=verdict]').on('change', function(){
    $('#e_verdict_other_wrap').toggle($('input[name=verdict]:checked').val() === 'other');
});
$(document).on('change', 'input[name=verdict]', function(){
    $('#e_verdict_other_wrap').toggle($('input[name=verdict]:checked').val() === 'other');
});
$(document).on('change', 'input[name=design]', function(){ syncReviewPick(); });
/** 目前這張單有沒有已經被勾為「需會審」的單位 */
function hasNeeded(){ return (CUR_REVIEWS || []).some(function(x){ return x.needed; }); }
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
                                  design_note:$('#e_design_note').val()};
    if (stage === 'CTRL') return {ctrl_drawing:$('#e_ctrl_drawing').is(':checked')?1:0,
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
        fields.design_result = $('input[name=design]:checked').val() || '';
        fields.old_stock     = $('input[name=oldstock]:checked').val() || '';
        fields.design_note   = $('#e_design_note').val();
        if (!fields.design_result) { alert('請先選擇設計分析結果'); return; }
        if (!fields.old_stock)     { alert('請先選擇庫存舊料可否修改'); return; }
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
        fields.ctrl_drawing = $('#e_ctrl_drawing').is(':checked') ? 1 : 0;
        fields.ctrl_bom     = $('#e_ctrl_bom').is(':checked') ? 1 : 0;
        fields.ctrl_manual  = $('#e_ctrl_manual').is(':checked') ? 1 : 0;
        if (!fields.ctrl_drawing && !fields.ctrl_bom && !fields.ctrl_manual) {
            alert('請至少勾選一項需修改的文件資料（圖面／BOM／操作手冊）'); return;
        }
    }
    doSign(fields);
});
function doSign(fields){
    if (!confirm('確定簽核這一關？簽完會自動通知下一關的人。')) return;
    post(fields, function(r){
        if (!r.ok) return;
        alert(r.status === 'CLOSED' ? '已簽核完成，本單結案' : ('已簽核，接下來由「' + (DICT.stages[r.status] || '') + '」處理'));
        closeMask('ecMask'); loadList();
    });
}

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
    showAsDoc();
    loadStampTemplates();
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
             ec_stamp_tpl_id: $('#set_stamp').val() || '', ec_review_stamp_tpl_id: $('#set_rv_stamp').val() || ''};
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
        + '.co{font-size:11pt;font-weight:bold;text-align:center;letter-spacing:2px;}'
        + '.co small{display:block;font-size:8pt;font-weight:normal;letter-spacing:0;}'
        + '.tt{font-size:13.5pt;font-weight:bold;text-align:center;letter-spacing:6px;margin:1mm 0 0.5mm;}'
        + '.ymd{text-align:right;font-size:9pt;margin-bottom:1mm;}'
        + 'table{border-collapse:collapse;width:194mm;table-layout:fixed;}'
        + 'td,th{border:0.4mm solid #000;padding:0.6mm 1.2mm;font-size:9.5pt;vertical-align:middle;word-break:break-all;}'
        + '.lb{background:#F2F2F2;text-align:center;font-weight:bold;white-space:nowrap;}'
        + '.lbs{background:#F2F2F2;text-align:center;font-weight:bold;font-size:8.5pt;line-height:1.2;}'
        + '.sig{height:18mm;text-align:center;vertical-align:middle;padding:0.5mm;}'
        + '.opt{font-size:9pt;line-height:1.35;text-align:left;}'
        + '.rvbody{font-size:7.5pt;line-height:1.3;text-align:left;height:10mm;vertical-align:top;padding:0.6mm 1.2mm;}'
        + '.rvname{width:22mm;text-align:center;font-size:9pt;background:#F2F2F2;font-weight:bold;}'
        + '.rvsig{width:34mm;height:10mm;text-align:center;padding:0.5mm;}'
        + '.op{display:block;margin-top:0.6mm;}'
        + '.flow{font-size:8pt;text-align:left;line-height:1.3;}'
        + '.ft{display:flex;justify-content:space-between;font-size:8pt;margin-top:1.5mm;}'
        + '.divider{text-align:center;font-size:8.5pt;font-weight:bold;margin:0.8mm 0 0.4mm;}'
        /* 一列會審不要被切成兩半跨頁（內容特別多時的保險） */
        + 'tr{page-break-inside:avoid;}';

    var h = '<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="utf-8">'
        + '<title>' + esc(d.doc_no || '工程變更申請單') + '</title><style>' + css + '</style></head><body>'
        + '<div class="co">' + esc(m.company || '') + '</div>'
        + '<div class="tt">' + esc(m.as_doc_name || '工程變更申請/審查/通知單') + '</div>'
        + '<div class="ymd">日期：' + dispDate(d.apply_date) + '　　文件編號：' + esc(d.doc_no || '') + '</div>'

        // 表頭
        + '<table><colgroup><col style="width:22mm"><col style="width:52mm"><col style="width:20mm">'
        +   '<col style="width:44mm"><col style="width:22mm"><col style="width:34mm"></colgroup>'
        + '<tr><td class="lb">客戶名稱</td><td>' + esc(d.customer_name || '') + '</td>'
        +     '<td class="lb">料　號</td><td>' + esc(d.part_no || '') + '</td>'
        +     '<td class="lb">申請單位</td><td>' + esc(d.apply_dept_name || '') + '</td></tr>'
        + '</table>'

        // 變更方式 ＋ 申請人/單位主管簽章
        + '<table><colgroup><col style="width:22mm"><col style="width:116mm"><col style="width:22mm"><col style="width:34mm"></colgroup>'
        + '<tr><td class="lb" rowspan="2">變更方式</td>'
        +     '<td class="opt" rowspan="2">'
        +       box(ct === 'customer_notify') + ' 客戶通知變更(包含新訂單版次變更)<br>'
        +       box(ct === 'blueprint_error') + ' 客戶藍圖有誤，通知客戶之建議變更(客戶同意後需附上新版客戶藍圖)<br>'
        +       box(ct === 'other') + ' 其他變更(請於設變事由說明內詳述)</td>'
        +     '<td class="lbs">申請人</td><td class="sig">' + sg('applicant') + '</td></tr>'
        + '<tr><td class="lbs">單位主管</td><td class="sig">' + sg('sup') + '</td></tr>'
        + '<tr><td class="lbs">(僅其他變更須填寫)<br>設變事由說明</td>'
        +     '<td class="opt" style="height:12mm;vertical-align:top;">' + esc(d.change_reason || '').replace(/\n/g, '<br>') + '</td>'
        +     '<td class="lbs" colspan="2"><span class="flow">流程：<br>申請單位↓倉管↓技術<br>↓其他單位(僅需會審者)↓技術</span></td></tr>'
        + '</table>'

        // 確認庫存（倉管組）
        + '<table><colgroup><col style="width:22mm"><col style="width:116mm"><col style="width:22mm"><col style="width:34mm"></colgroup>'
        + '<tr><td class="lbs">確認<br>庫存</td>'
        +     '<td class="opt">庫存數量：' + esc(d.stock_qty || '') + '<br>已完工待入庫數量：' + esc(d.wip_qty || '') + '</td>'
        +     '<td class="lbs">倉管組</td><td class="sig">' + sg('wh') + '</td></tr>'
        + '</table>'

        // 設計分析（技術課）＋ 庫存舊料
        + '<table><colgroup><col style="width:22mm"><col style="width:116mm"><col style="width:22mm"><col style="width:34mm"></colgroup>'
        + '<tr><td class="lbs">(更新圖面需附上)<br>設計分析</td>'
        +     '<td class="opt">' + box(dr === 'drawing_only') + ' 僅修改圖面(修改後結案)<br>'
        +       box(dr === 'need_review') + ' 需修改圖面與會審(單據續跑，下方需勾選)'
        +       (d.design_note ? '<br>' + esc(d.design_note) : '') + '</td>'
        +     '<td class="lbs">技術課</td><td class="sig">' + sg('td') + '</td></tr>'
        + '<tr><td colspan="4" class="opt">1.庫存舊料：'
        +       box(os === 'can') + ' 可修改　' + box(os === 'cannot') + ' 無法修改(轉業務確認客戶收貨或報廢)</td></tr>'
        + '</table>'

        // 核示
        + '<table><colgroup><col style="width:22mm"><col style="width:116mm"><col style="width:22mm"><col style="width:34mm"></colgroup>'
        + '<tr><td class="lb" rowspan="2">核示</td>'
        +     '<td class="opt">' + box(vd === 'approve') + ' 准予變更　' + box(vd === 'hold') + ' 暫緩變更　'
        +       box(vd === 'other') + ' 其他　' + esc(d.verdict_other || '') + '</td>'
        +     '<td class="lbs" rowspan="2">核准</td><td class="sig" rowspan="2">' + sg('appr') + '</td></tr>'
        + '<tr><td class="opt" style="height:7mm;vertical-align:top;">補充意見：'
        +       esc(d.verdict_note || '').replace(/\n/g, '<br>') + '</td></tr>'
        + '</table>'

        + '<div class="divider">↓以下僅技術課判定需會審才填寫↓</div>'

        // 相關單位會審
        + '<table><colgroup><col style="width:138mm"><col style="width:22mm"><col style="width:34mm"></colgroup>'
        + rvRows
        + '</table>'

        // 管制
        + '<table style="margin-top:1mm;"><colgroup><col style="width:22mm"><col style="width:116mm"><col style="width:22mm"><col style="width:34mm"></colgroup>'
        + '<tr><td class="lb">管制</td>'
        +     '<td class="opt">需修改文件資料：' + box(+d.ctrl_drawing === 1) + ' 圖面　'
        +       box(+d.ctrl_bom === 1) + ' BOM　' + box(+d.ctrl_manual === 1) + ' 操作手冊</td>'
        +     '<td class="lbs">管制員</td><td class="sig">' + sg('ctrl') + '</td></tr>'
        + '</table>'

        + '<div class="ft"><span>※此表單底稿由技術課存查　※文件編號以西元年月日加流水號，例如：20220101001</span>'
        +   '<span>' + esc(m.as_doc_no || '') + '</span></div>'
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

$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
$('#btnRoleHelp').on('click', function(){ openMask('roleMask'); });
</script>
</body>
</html>
