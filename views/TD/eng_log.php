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
        /* 側欄預設隱藏、由下方 JS 恢復——只抄 CSS 不抄 JS 側欄會整片消失（鐵律6） */
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; }
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px;
            background:#F0A24B; color:#fff; cursor:pointer; }
        .page-help-btn:hover { background:#d98a33; }
        @media print { .page-help-btn, .el-toolbar, .el-quick { display:none !important; } }

        /* 配色一律暖色系（ai-rules/10）；狀態沿用急件燈號三色 */
        .el-toolbar { background:#faf6f0; border:1px solid #e4d8c6; padding:10px 12px; margin-bottom:10px; }
        .el-toolbar .form-control, .el-toolbar .btn { height:30px; font-size:13px; }
        .el-toolbar label { font-size:12px; color:#8a7358; margin:0 4px 0 0; font-weight:normal; }
        .el-fld { display:inline-flex; align-items:center; margin:0 10px 6px 0; }
        .el-quick { margin-bottom:10px; }
        .el-quick .qb { display:inline-block; font-size:12.5px; padding:3px 11px; margin:0 6px 6px 0; cursor:pointer;
            border:1px solid #cdb99c; background:#fff; color:#3a2c1a; border-radius:2px; }
        .el-quick .qb:hover { background:#f5ecdf; }
        .el-quick .qb.on { background:#8a5a2b; border-color:#8a5a2b; color:#fff; font-weight:bold; }
        .el-quick .qb.alert { border-color:#be3c25; color:#dd5138; font-weight:bold; }
        .el-quick .qb.alert.on { background:#dd5138; border-color:#be3c25; color:#fff; }
        .el-quick .qb .n { font-size:11.5px; opacity:.75; margin-left:5px; }

        table.el-tbl { width:100%; border-collapse:collapse; background:#fff; }
        table.el-tbl th { background:#f5ecdf; color:#241a0f; font-size:12.5px; padding:8px 10px; text-align:left;
            border-bottom:1px solid #cdb99c; white-space:nowrap; }
        table.el-tbl td { padding:8px 10px; font-size:13px; border-bottom:1px solid #efe7db; vertical-align:top; }
        table.el-tbl tr.clickable:hover { background:#fdf9f3; cursor:pointer; }
        .el-no { font-family:Consolas,monospace; font-size:12px; color:#8a7358; white-space:nowrap; }
        .el-title { font-weight:bold; color:#241a0f; }

        .bind-badge { display:inline-block; padding:0 6px; border-radius:2px; font-size:11px; line-height:1.7;
            border:1px solid #d8c7b0; background:#fff; color:#3a2c1a; margin:2px 4px 0 0; white-space:nowrap; }
        .bind-badge b { color:#8a5a2b; margin-right:4px; }
        .bind-badge.manual { border-color:#be3c25; }
        .bind-badge.manual b { color:#dd5138; }

        .chip { display:inline-block; padding:1px 8px; border-radius:2px; font-size:11.5px; font-weight:bold;
            line-height:1.7; white-space:nowrap; border:1px solid transparent; }
        .chip-wait  { background:#F0A24B; border-color:#D6851F; color:#4E2C0B; }
        .chip-over  { background:#DD5138; border-color:#BE3C25; color:#fff; }
        .chip-done  { background:#F7E0BD; border-color:#E4C293; color:#4E2C0B; }
        .chip-close { background:transparent; border-color:#cdb99c; color:#8a7358; }
        .chip-warn  { background:transparent; border-color:#BE3C25; color:#DD5138; font-weight:bold; }

        /* 問題項一條一個區塊（不是表格列）：回覆會越積越多，表格列裝不下 */
        .qitem { border-top:1px solid #efe7db; padding:11px 12px; }
        .qitem:first-child { border-top:none; }
        .qitem.done { background:#fcfaf7; }
        .qhead { display:grid; grid-template-columns:2rem minmax(0,1fr) 10rem 4.2rem 7rem 5.5rem; gap:0 10px; align-items:start; }
        .qno { font-family:Consolas,monospace; font-size:13px; color:#8a7358; padding-top:1px; }
        .qtext { font-size:13.5px; line-height:1.7; color:#241a0f; min-width:0; word-break:break-word; }
        .qmeta { font-size:12px; color:#8a7358; line-height:1.5; min-width:0; word-break:break-word; }
        .qmeta b { display:block; color:#3a2c1a; font-size:12.5px; font-weight:500; }
        .qdate { font-family:Consolas,monospace; font-size:12px; color:#8a7358; }
        .qact { text-align:right; }
        .qact .btn { padding:1px 7px; font-size:11.5px; }
        .qreplies { margin:8px 0 0 calc(2rem + 10px); }
        .rep { display:grid; grid-template-columns:5.6rem minmax(0,1fr) 2rem; gap:0 10px; background:#f9f4ec;
            border-left:2px solid #e4c293; padding:6px 10px; font-size:12.5px; line-height:1.65; margin-bottom:5px; }
        .rep .rdate { font-family:Consolas,monospace; font-size:11.5px; color:#8a5a2b; font-weight:bold; }
        .rep .rwho { display:block; color:#8a7358; font-weight:normal; font-size:11px; }
        .rep .rbody { min-width:0; color:#3a2c1a; white-space:pre-wrap; word-break:break-word; }
        .rep .rdel { text-align:right; }
        .rep .rdel a { color:#b08; color:#a2703a; font-size:11px; }
        .att { display:inline-block; font-size:11px; border:1px solid #d8c7b0; padding:0 6px; border-radius:2px;
            color:#8a5a2b; margin:2px 4px 0 0; background:#fff; }
        @media (max-width:820px) {
            .qhead { grid-template-columns:2rem minmax(0,1fr); }
            .qhead .qmeta, .qhead .qdate, .qhead .qstat, .qhead .qact { grid-column:2; text-align:left; }
        }

        /* 跳窗：寬度一律固定像素，禁用 vw（會蓋過側邊選單） */
        .el-mask { display:none; position:fixed; left:0; top:0; right:0; bottom:0; background:rgba(40,26,12,.45); z-index:10050; }
        .el-mask.on { display:block; }
        .el-modal { background:#fff; margin:26px auto; max-width:1040px; width:96%; border-radius:3px;
            box-shadow:0 10px 40px rgba(0,0,0,.3); max-height:calc(100vh - 52px); display:flex; flex-direction:column; }
        .el-modal.sm { max-width:620px; }
        .el-modal.md { max-width:800px; }
        .m-head { background:#8a5a2b; color:#fff; padding:9px 14px; font-size:14px; font-weight:bold;
            display:flex; align-items:center; gap:10px; flex-shrink:0; }
        .m-head .sub { font-weight:normal; font-size:12px; opacity:.85; }
        .m-head .x { margin-left:auto; cursor:pointer; font-size:18px; line-height:1; }
        .m-body { padding:14px; overflow:auto; flex:1 1 auto; }
        .m-foot { padding:10px 14px; border-top:1px solid #e4d8c6; text-align:right; flex-shrink:0; background:#faf6f0; }
        .m-foot .btn { margin-left:6px; }
        .sec-title { font-size:13px; font-weight:bold; color:#8a5a2b; border-bottom:1px solid #e4d8c6;
            padding-bottom:4px; margin:16px 0 8px; }
        .sec-title:first-child { margin-top:0; }
        .fld { display:inline-block; margin:0 14px 10px 0; vertical-align:top; }
        .fld > label { display:block; font-size:12px; color:#8a7358; margin-bottom:2px; font-weight:normal; }
        .fld .form-control { height:30px; font-size:13px; }
        .err { color:#DD5138; font-size:12px; margin-top:2px; display:none; }
        .has-err .form-control { border-color:#DD5138; }
        .has-err .err { display:block; }
        .tip { font-size:12px; color:#8a7358; margin:4px 0 8px; line-height:1.7; }
        .tip-warn { background:#fdf3f0; border:1px solid #e9b8ac; border-left-width:3px; color:#6B471A;
            padding:9px 12px; font-size:12.5px; line-height:1.75; margin:6px 0 10px; }
        .tip-warn b { color:#A34E2A; }

        .bind-row { display:flex; flex-wrap:wrap; gap:6px; align-items:flex-end; margin-bottom:8px; }
        .bind-box { border:1px solid #e4d8c6; background:#faf6f0; padding:8px 10px; min-height:40px; }
        .bind-box .bind-badge { cursor:default; }
        .bind-box .bind-badge a { color:#a2703a; margin-left:5px; text-decoration:none; }
        .ac-wrap { position:relative; }
        .ac-list { position:absolute; left:0; top:100%; z-index:20; background:#fff; border:1px solid #cdb99c;
            width:340px; max-height:260px; overflow:auto; display:none; box-shadow:0 4px 14px rgba(0,0,0,.15); }
        .ac-list.on { display:block; }
        .ac-list div { padding:5px 9px; font-size:12.5px; cursor:pointer; border-bottom:1px solid #f2ece2; }
        .ac-list div:hover, .ac-list div.sel { background:#f5ecdf; }
        .ac-list div .s { color:#8a7358; font-size:11.5px; margin-left:6px; }

        table.q-input { width:100%; border-collapse:collapse; }
        table.q-input th { background:#f5ecdf; font-size:12px; padding:5px 7px; text-align:left; border:1px solid #e4d8c6; }
        table.q-input td { border:1px solid #efe7db; padding:3px 4px; }
        table.q-input .form-control { height:28px; font-size:12.5px; }
        .pick-row { display:grid; grid-template-columns:1.6rem minmax(0,1fr) 6rem; gap:0 10px; padding:6px 10px;
            border-bottom:1px solid #efe7db; font-size:13px; align-items:baseline; }
        .pick-row:last-child { border-bottom:none; }
        .pager { text-align:right; margin-bottom:6px; font-size:13px; }
        .pager .btn { padding:2px 9px; font-size:12.5px; margin-left:3px; }
        .help-doc h4 { font-size:14px; color:#8a5a2b; margin:14px 0 6px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc p, .help-doc li { font-size:13px; line-height:1.85; color:#3a2c1a; }
        .help-doc ul { padding-left:20px; }
        .to-top { position:fixed; right:22px; bottom:22px; z-index:900; display:none; }
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
            <div class="alert alert-warning" style="margin-top:14px;">
                您目前沒有「工程處理紀錄」的使用權限，請洽管理員在「使用者權限設定」指派角色。
            </div>
        <?php else: ?>

        <div class="el-quick" id="quickBar"></div>

        <div class="el-toolbar">
            <div class="el-fld"><label>關鍵字</label>
                <input type="text" id="fKw" class="form-control" style="width:190px;" placeholder="標題／問題／回覆／單號"></div>
            <div class="el-fld"><label>客戶</label>
                <select id="fCustomer" class="form-control" style="width:150px;" data-eg-filter="輸入客戶名稱篩選…"><option value="">全部</option></select></div>
            <div class="el-fld"><label>料號</label>
                <select id="fPart" class="form-control" style="width:160px;" data-eg-filter="輸入料號篩選…"><option value="">全部</option></select></div>
            <div class="el-fld"><label>廠商</label>
                <select id="fMaker" class="form-control" style="width:150px;" data-eg-filter="輸入廠商名稱篩選…"><option value="">全部</option></select></div>
            <div class="el-fld"><label>類型</label>
                <select id="fType" class="form-control" style="width:100px;"><option value="">全部</option></select></div>
            <div class="el-fld"><label>建立日</label>
                <input type="date" id="fD1" class="form-control" style="width:140px;">
                <span style="margin:0 4px;color:#8a7358;">~</span>
                <input type="date" id="fD2" class="form-control" style="width:140px;"></div>
            <div class="el-fld"><label>&nbsp;</label>
                <span><button class="btn btn-sm" id="btnSearch" style="background:#8a5a2b;color:#fff;">查詢</button>
                <button class="btn btn-sm btn-default" id="btnReset">清除</button></span></div>
            <?php if ($P['canEdit']): ?>
            <div class="el-fld" style="float:right;"><label>&nbsp;</label>
                <span><button class="btn btn-sm" id="btnNew" style="background:#F0A24B;color:#fff;border-color:#d98a33;">
                    <i class="fa fa-plus"></i> 新增紀錄</button></span></div>
            <?php endif; ?>
            <div style="clear:both;"></div>
        </div>

        <div class="pager" id="pager"></div>
        <table class="el-tbl" id="listTbl">
            <thead><tr>
                <th style="width:120px;">編號</th>
                <th>標題與綁定</th>
                <th style="width:150px;">問題進度</th>
                <th style="width:96px;">狀態</th>
                <th style="width:92px;">建立者</th>
                <th style="width:96px;">建立日</th>
            </tr></thead>
            <tbody id="listBody"><tr><td colspan="6" style="text-align:center;color:#8a7358;padding:24px;">載入中…</td></tr></tbody>
        </table>

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
        <div class="sec-title">基本資料</div>
        <div class="fld" style="width:100%;max-width:430px;"><label>標題 <span style="color:#DD5138;">*</span></label>
            <input type="text" id="gTitle" class="form-control" maxlength="200" placeholder="例：RC105-N03-A 批圖問題">
            <div class="err" id="eTitle"></div></div>
        <div class="fld"><label>類型</label><select id="gType" class="form-control" style="width:110px;"></select></div>
        <div class="fld"><label>誰看得到</label><select id="gVis" class="form-control" style="width:120px;"></select></div>
        <div class="fld"><label>期限（可不填）</label><input type="datetime-local" id="gDeadline" class="form-control" style="width:190px;"></div>
        <div class="fld"><label>期限提醒</label>
            <span style="display:flex;align-items:center;gap:4px;">
                <input type="number" id="gRemindVal" class="form-control" style="width:64px;" min="1" placeholder="空=不提醒">
                <select id="gRemindUnit" class="form-control" style="width:66px;"><option value="1440">天</option><option value="60">小時</option></select>
                <span style="font-size:12px;color:#8a7358;white-space:nowrap;">前提醒</span>
            </span></div>
        <div class="fld"><label>幾天內算急件</label><input type="number" id="gUrgent" class="form-control" style="width:80px;" min="1" placeholder="預設3"></div>
        <div class="fld" style="width:100%;"><label>案件備註</label>
            <textarea id="gNote" class="form-control" rows="2" style="font-size:13px;"></textarea></div>

        <div class="sec-title">綁定對象</div>
        <div class="tip">綁定是為了「日後查得回來」：綁了 BOM 會自動帶出料號、客戶與這張 BOM 的所有發包廠商，不必一個一個選。</div>
        <div class="bind-row">
            <div><label style="font-size:12px;color:#8a7358;display:block;margin-bottom:2px;">型別</label>
                <select id="gBindType" class="form-control" style="width:130px;height:30px;font-size:13px;"></select></div>
            <div class="ac-wrap"><label style="font-size:12px;color:#8a7358;display:block;margin-bottom:2px;" id="gBindLbl">關鍵字</label>
                <input type="text" id="gBindKw" class="form-control" style="width:340px;height:30px;font-size:13px;" autocomplete="off" data-eg-skip>
                <div class="ac-list" id="gBindAc"></div>
                <div class="err" id="eBind"></div></div>
            <div><button class="btn btn-sm btn-default" id="btnAddManual" style="height:30px;display:none;">加入單號</button></div>
        </div>
        <div class="bind-box" id="gBindBox"></div>
        <div id="gBindWarn" class="tip-warn" style="display:none;"></div>

        <div id="newItemsWrap">
            <div class="sec-title">問題（可一次打很多條，末列按 ↓ 自動加一列）</div>
            <div class="tip">一條問題只對一個對象；同一個問題要問客戶也要問廠商時，請拆成兩條各自等回覆。</div>
            <table class="q-input">
                <thead><tr><th style="width:34px;">#</th><th>問題內容</th><th style="width:110px;">對象</th>
                    <th style="width:200px;">對象名稱</th><th style="width:120px;">聯絡人</th>
                    <th style="width:130px;">提出日期</th><th style="width:36px;"></th></tr></thead>
                <tbody id="qInputBody" data-eg-row-add="qRowAdd" data-eg-row-del="qRowDel"></tbody>
            </table>
        </div>

        <div class="sec-title">附件</div>
        <div class="tip" id="gFileHint">尚未儲存前檔案先暫存，按「儲存」時自動掛到這筆紀錄。</div>
        <input type="file" id="gFile" multiple style="font-size:12px;">
        <div id="gFileList" style="margin-top:6px;"></div>
    </div>
    <div class="m-foot">
        <button class="btn btn-default" onclick="closeMask('logMask')">取消</button>
        <button class="btn" id="btnSaveLog" style="background:#8a5a2b;color:#fff;">儲存</button>
    </div>
</div></div>

<!-- ═══ 案件明細 ═══ -->
<div class="el-mask" id="detMask"><div class="el-modal">
    <div class="m-head"><span id="detNo"></span><span class="sub" id="detSub"></span>
        <span class="x" onclick="closeMask('detMask')">&times;</span></div>
    <div class="m-body">
        <div id="detBinds" style="background:#faf6f0;border:1px solid #e4d8c6;padding:9px 11px;margin-bottom:10px;"></div>
        <div id="detNote" class="tip" style="display:none;"></div>
        <div id="detUnlinked" class="tip-warn" style="display:none;"></div>

        <div class="sec-title" style="display:flex;align-items:center;">
            <span>問題項</span>
            <span style="margin-left:auto;font-weight:normal;" id="detItemActs"></span>
        </div>
        <div style="border:1px solid #e4d8c6;" id="detItems"></div>

        <div class="sec-title">案件附件</div>
        <div id="detFiles"></div>
        <div id="detConclusion" style="display:none;margin-top:14px;background:#f5ecdf;border-left:3px solid #8a5a2b;padding:10px 12px;font-size:13px;"></div>
    </div>
    <div class="m-foot" id="detFoot"></div>
</div></div>

<!-- ═══ 填回覆（可一次套用到多條問題） ═══ -->
<div class="el-mask" id="repMask"><div class="el-modal md">
    <div class="m-head"><span>填寫對象回覆</span><span class="sub" id="repSub"></span>
        <span class="x" onclick="closeMask('repMask')">&times;</span></div>
    <div class="m-body">
        <div class="tip">勾選這一次回覆涵蓋哪幾條問題——客戶一通電話回了好幾題時，只要填一次。</div>
        <div id="repItems" style="border:1px solid #e4d8c6;max-height:230px;overflow:auto;margin-bottom:12px;"></div>
        <div class="fld"><label>回覆日期 <span style="color:#DD5138;">*</span></label>
            <input type="date" id="rDate" class="form-control" style="width:160px;">
            <div style="font-size:11.5px;color:#8a7358;margin-top:2px;">預設今天；對方是幾天前回的請改成當天日期</div>
            <div class="err" id="eRDate"></div></div>
        <div class="fld"><label>回覆人</label><input type="text" id="rBy" class="form-control" style="width:170px;" maxlength="60" placeholder="例：林工程師"></div>
        <div class="fld"><label>方式</label><select id="rChannel" class="form-control" style="width:110px;"></select></div>
        <div class="fld" style="width:100%;"><label>回覆內容 <span style="color:#DD5138;">*</span></label>
            <textarea id="rContent" class="form-control" rows="4" style="font-size:13px;"></textarea>
            <div class="err" id="eRContent"></div></div>
        <div class="fld" style="width:100%;"><label>附件（掛在這一則回覆上）</label>
            <input type="file" id="rFile" multiple style="font-size:12px;">
            <div id="rFileList" style="margin-top:6px;"></div></div>
    </div>
    <div class="m-foot">
        <button class="btn btn-default" onclick="closeMask('repMask')">取消</button>
        <button class="btn" id="btnSaveReply" style="background:#8a5a2b;color:#fff;">儲存回覆</button>
    </div>
</div></div>

<!-- ═══ 單一問題項 新增／編輯 ═══ -->
<div class="el-mask" id="itemMask"><div class="el-modal sm">
    <div class="m-head"><span id="itemMTitle">新增問題</span><span class="x" onclick="closeMask('itemMask')">&times;</span></div>
    <div class="m-body">
        <div class="fld" style="width:100%;"><label>問題內容 <span style="color:#DD5138;">*</span></label>
            <textarea id="iQuestion" class="form-control" rows="3" style="font-size:13px;"></textarea>
            <div class="err" id="eIQuestion"></div></div>
        <div class="fld"><label>對象</label><select id="iTargetType" class="form-control" style="width:110px;"></select></div>
        <div class="fld ac-wrap"><label>對象名稱</label>
            <input type="text" id="iTargetKw" class="form-control" style="width:230px;" autocomplete="off" data-eg-skip>
            <div class="ac-list" id="iTargetAc"></div></div>
        <div class="fld"><label>聯絡人</label><input type="text" id="iContact" class="form-control" style="width:130px;" maxlength="60"></div>
        <div class="fld"><label>提出日期</label><input type="date" id="iAsked" class="form-control" style="width:150px;">
            <div class="err" id="eIAsked"></div></div>
        <div class="fld"><label>等幾天沒回就提醒</label>
            <input type="number" id="iFollowUp" class="form-control" style="width:90px;" min="1" max="365" placeholder="預設7">
            <div style="font-size:11.5px;color:#8a7358;margin-top:2px;">算工作天</div></div>
    </div>
    <div class="m-foot">
        <button class="btn btn-default" onclick="closeMask('itemMask')">取消</button>
        <button class="btn" id="btnSaveItem" style="background:#8a5a2b;color:#fff;">儲存</button>
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
        <div style="border:1px solid #e4d8c6;max-height:320px;overflow:auto;" id="pickBody"></div>
    </div>
    <div class="m-foot">
        <button class="btn btn-default" onclick="closeMask('pickMask')">取消</button>
        <button class="btn" id="btnPickOk" style="background:#8a5a2b;color:#fff;">確定綁定</button>
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
        <button class="btn btn-default" onclick="closeMask('closeMask2')">取消</button>
        <button class="btn" id="btnDoClose" style="background:#8a5a2b;color:#fff;">確定結案</button>
    </div>
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
            <tr><td colspan="2" style="color:#8a7358;">尚未建立本模組角色，請到「使用者權限設定」新增。</td></tr>
        <?php endif; ?>
        </tbody></table>
    </div>
    <div class="m-foot"><button class="btn btn-default" onclick="closeMask('roleMask')">關閉</button></div>
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
    <div class="m-foot"><button class="btn" style="background:#8a5a2b;color:#fff;" onclick="closeMask('helpUseMask')">我知道了</button></div>
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
var CUR = null;                 // 目前開啟的案件明細
var EDIT_ID = 0;                // 編輯中的案件 id（0=新增）
var BINDS = [];                 // 編輯中的綁定
var TEMP_FILES = [], REP_TEMP_FILES = [];
var PICK_CTX = null, REP_CTX = null, ITEM_EDIT = null;
var OPEN_ID = <?= (int)$openId ?>;
var CAN_EDIT = <?= $P['canEdit'] ? 'true' : 'false' ?>;

/* 顯示用日期一律 YYYY.MM.DD（ai-rules/20，唯一實作 egFmtDate） */
function dispDate(v) { return (v && window.egFmtDate) ? egFmtDate(v) : (v || ''); }
function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }
function openMask(id) { $('#' + id).addClass('on'); }
function closeMask(id) { $('#' + id).removeClass('on'); }

function apiGet(action, data) { return $.getJSON(API, $.extend({ action: action }, data || {})); }
function apiPost(action, data) {
    data = $.extend({ action: action, csrf: CSRF }, data || {});
    return $.post(API, data, null, 'json');
}
$(document).ajaxError(function (e, xhr) {
    if (xhr && xhr.status) alert('連線發生問題（HTTP ' + xhr.status + '），請重新整理頁面後再試。');
});
function okOrAlert(r) {
    if (r && r.success) return true;
    alert((r && r.message) ? r.message : '操作失敗');
    return false;
}

/* ── 啟動 ─────────────────────────────────────────────────────────── */
$(function () {
    apiGet('bootstrap').done(function (r) {
        if (!r.success) { alert(r.message || '無法載入'); return; }
        CSRF = r.csrf; DICT = r.dict; PERMS = r.perms; ME = r.me; TODAY = r.today;
        fillDicts();
        buildQuickBar();
        loadList();
        loadFilterOptions();
        if (OPEN_ID > 0) openDetail(OPEN_ID);
    });
});

function fillDicts() {
    var $t = $('#fType').add('#gType');
    $.each(DICT.log_types, function (k, v) { $t.append($('<option>').val(k).text(v)); });
    $('#fType').val('');
    $.each(DICT.visibility, function (k, v) { $('#gVis').append($('<option>').val(k).text(v)); });
    $('#gVis').val('dept');
    $.each(DICT.channels, function (k, v) { $('#rChannel').append($('<option>').val(k).text(v)); });
    $.each(DICT.bind_types, function (k, v) { $('#gBindType').append($('<option>').val(k).text(v.name)); });
    var tt = { customer: '客戶', maker: '廠商', user: '公司內部' };
    $.each(tt, function (k, v) { $('#iTargetType').append($('<option>').val(k).text(v)); });
}

function buildQuickBar() {
    var list = [
        { k: 'open',     t: '處理中' },
        { k: 'waiting',  t: '待回覆' },
        { k: 'overdue',  t: '逾期未回', alert: 1 },
        { k: 'week',     t: '本週到期' },
        { k: 'done',     t: '已結案' },
        { k: 'unlinked', t: '未連結料號', alert: 1 },
        { k: '',         t: '全部' }
    ];
    var $b = $('#quickBar').empty();
    $.each(list, function (i, x) {
        $('<span class="qb">').addClass(x.alert ? 'alert' : '').addClass(x.k === QUICK ? 'on' : '')
            .attr('data-k', x.k).text(x.t).appendTo($b);
    });
}
$(document).on('click', '#quickBar .qb', function () {
    QUICK = $(this).attr('data-k'); PAGE = 1;
    $('#quickBar .qb').removeClass('on'); $(this).addClass('on');
    loadList();
});

/* ── 清單 ─────────────────────────────────────────────────────────── */
function filterParams() {
    return {
        kw: $('#fKw').val(), customer: $('#fCustomer').val(), part: $('#fPart').val(),
        maker: $('#fMaker').val(), log_type: $('#fType').val(),
        d1: $('#fD1').val(), d2: $('#fD2').val(), quick: QUICK, page: PAGE, per: PER
    };
}
function loadList() {
    $('#listBody').html('<tr><td colspan="6" style="text-align:center;color:#8a7358;padding:24px;">載入中…</td></tr>');
    apiGet('list', filterParams()).done(function (r) {
        if (!r.success) { $('#listBody').html('<tr><td colspan="6" style="color:#DD5138;padding:20px;">' + esc(r.message) + '</td></tr>'); return; }
        ROWS = r.rows || []; TOTAL = r.total || 0;
        renderList(); renderPager();
    });
}
function renderList() {
    var $b = $('#listBody').empty();
    if (!ROWS.length) {
        $b.html('<tr><td colspan="6" style="text-align:center;color:#8a7358;padding:26px;">沒有符合條件的紀錄</td></tr>');
        return;
    }
    $.each(ROWS, function (i, r) {
        var $tr = $('<tr class="clickable">').attr('data-id', r.id);
        $tr.append($('<td class="el-no">').text(r.log_no));

        var $c2 = $('<td>');
        $c2.append($('<div class="el-title">').text(r.title));
        var $bd = $('<div style="margin-top:2px;">');
        $.each(r.binds || [], function (j, b) {
            var name = (DICT.bind_types[b.bind_type] || {}).name || b.bind_type;
            $('<span class="bind-badge">').addClass(Number(b.is_manual) ? 'manual' : '')
                .append($('<b>').text(name)).append(document.createTextNode(b.bind_label || b.bind_id))
                .appendTo($bd);
        });
        if (r.unlinked) $bd.append($('<span class="chip chip-warn" style="margin-left:4px;">').text('未連結料號'));
        $c2.append($bd);
        $tr.append($c2);

        var $c3 = $('<td>');
        var cnt = Number(r.item_cnt) || 0, done = Number(r.item_done) || 0, wait = Number(r.item_wait) || 0;
        $c3.append($('<div style="font-size:12.5px;">').text(cnt ? ('已回 ' + done + ' / ' + cnt) : '尚未列問題'));
        if (wait > 0) {
            $c3.append($('<div style="font-size:11.5px;color:#8a7358;margin-top:2px;">')
                .text('待回覆 ' + wait + ' 條，最久已等 ' + (r.wait_days || 0) + ' 個工作天'));
        }
        $tr.append($c3);

        var $c4 = $('<td>');
        if (r.status === 'done') $c4.append($('<span class="chip chip-close">').text('已結案'));
        else if (wait > 0 && Number(r.wait_days) >= (Number(r.urgent_days) || 7))
            $c4.append($('<span class="chip chip-over">').text('逾期 ' + r.wait_days + ' 天'));
        else if (wait > 0) $c4.append($('<span class="chip chip-wait">').text('待回覆'));
        else $c4.append($('<span class="chip chip-done">').text('處理中'));
        $tr.append($c4);

        $tr.append($('<td style="font-size:12.5px;">').text(r.owner_name || ''));
        $tr.append($('<td style="font-size:12px;color:#8a7358;">').text(dispDate(r.created_at)));
        $b.append($tr);
    });
}
$(document).on('click', '#listBody tr.clickable', function () { openDetail(Number($(this).attr('data-id'))); });

function renderPager() {
    var pages = PER > 0 ? Math.max(1, Math.ceil(TOTAL / PER)) : 1;
    var $p = $('#pager').empty();
    $p.append($('<span style="color:#8a7358;font-size:12.5px;margin-right:10px;">').text('共 ' + TOTAL + ' 筆'));
    var $per = $('<select class="form-control" style="width:74px;height:26px;font-size:12.5px;display:inline-block;">');
    $.each([5, 10, 20, 50], function (i, n) { $per.append($('<option>').val(n).text(n + ' 筆')); });
    $per.val(PER).on('change', function () { PER = Number($(this).val()); PAGE = 1; loadList(); });
    $p.append($per);
    $p.append($('<button class="btn btn-default btn-sm">').text('上一頁').prop('disabled', PAGE <= 1)
        .on('click', function () { if (PAGE > 1) { PAGE--; loadList(); } }));
    $p.append($('<span style="margin:0 6px;font-size:12.5px;">').text(PAGE + ' / ' + pages));
    $p.append($('<button class="btn btn-default btn-sm">').text('下一頁').prop('disabled', PAGE >= pages)
        .on('click', function () { if (PAGE < pages) { PAGE++; loadList(); } }));
}

/* 三軸篩選選項：只列索引裡真的出現過的（不撈全站主檔，避免上千筆下拉）。
   選項變動不頻繁，載入一次即可；下拉本身掛了 data-eg-filter 可以打字篩選。 */
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
    $.each(rows || [], function (i, x) {
        $sel.append($('<option>').val(x.id).text(x.label || String(x.id)));
    });
    if (keep) $sel.val(keep);
}

$('#btnSearch').on('click', function () { PAGE = 1; loadList(); });
$('#btnReset').on('click', function () {
    $('#fKw,#fD1,#fD2').val(''); $('#fCustomer,#fPart,#fMaker,#fType').val('');
    PAGE = 1; loadList();
});
$('#fKw').on('keydown', function (e) { if (e.which === 13) { PAGE = 1; loadList(); } });

/* ── 新增／編輯案件 ─────────────────────────────────────────────────── */
$('#btnNew').on('click', function () { openLogModal(0); });

function openLogModal(id) {
    EDIT_ID = id || 0; BINDS = []; TEMP_FILES = [];
    $('#gTitle,#gNote,#gDeadline,#gRemindVal,#gUrgent').val('');
    $('#gType').val('other'); $('#gVis').val('dept'); $('#gRemindUnit').val('1440');
    $('#gFileList').empty(); $('#gFile').val('');
    $('.has-err').removeClass('has-err');
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
            renderBinds();
            renderEditFiles(r.files || []);
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
    $.each(files, function (i, f) {
        $('<div style="font-size:12px;margin-bottom:3px;">')
            .append($('<a target="_blank">').attr('href', API + '?action=download&id=' + f.id).text(f.original_name || f.file_name))
            .append($('<a href="javascript:;" style="margin-left:8px;color:#a2703a;">').text('刪除')
                .on('click', function () { delFile(f.id, $(this).closest('div')); }))
            .appendTo($w);
    });
}
function delFile(id, $row) {
    if (!confirm('確定刪除這個附件？')) return;
    apiPost('file_delete', { id: id }).done(function (r) { if (okOrAlert(r)) $row.remove(); });
}

/* 問題輸入列（可增列表格：末列↓自動加一列，走共用 eg_input_rules.js） */
function qRowAdd(afterTr) {
    var $tr = $('<tr>');
    $tr.append($('<td style="text-align:center;color:#8a7358;font-size:12px;" class="qseq">'));
    $tr.append($('<td>').append($('<input type="text" class="form-control q-q" maxlength="500" placeholder="例：外徑 φ32 未標公差，是否比照前批 h7？">')));
    var $tt = $('<select class="form-control q-tt">').append($('<option value="">—</option>'))
        .append($('<option value="customer">客戶</option>')).append($('<option value="maker">廠商</option>'))
        .append($('<option value="user">公司內部</option>'));
    $tr.append($('<td>').append($tt));
    $tr.append($('<td class="ac-wrap">').append($('<input type="text" class="form-control q-tn" autocomplete="off" data-eg-skip placeholder="打字搜尋">'))
        .append($('<div class="ac-list q-ac">')));
    $tr.append($('<td>').append($('<input type="text" class="form-control q-tc" maxlength="60">')));
    $tr.append($('<td>').append($('<input type="date" class="form-control q-ad">').val(TODAY)));
    $tr.append($('<td style="text-align:center;">').append($('<a href="javascript:;" style="color:#a2703a;" title="刪除這一列">&times;</a>')
        .on('click', function () { qRowDel($(this).closest('tr')); })));
    if (afterTr) $(afterTr).after($tr); else $('#qInputBody').append($tr);
    renumberQ();
    return $tr[0];
}
function qRowDel($tr) {
    if ($('#qInputBody tr').length <= 1) { $tr.find('input').val(''); $tr.find('.q-ad').val(TODAY); renumberQ(); return; }
    $tr.remove(); renumberQ();
}
function renumberQ() { $('#qInputBody tr').each(function (i) { $(this).find('.qseq').text(i + 1); }); }

/* 問題列的對象搜尋 */
$(document).on('input', '.q-tn', function () {
    var $inp = $(this), $tr = $inp.closest('tr');
    var type = $tr.find('.q-tt').val();
    var $ac = $tr.find('.q-ac');
    if (!type || type === 'user') { acUsers($inp, $ac, $tr); return; }
    acSearch(type, $inp.val(), $ac, function (row) {
        $inp.val(row.label).attr('data-id', row.id); $ac.removeClass('on');
    });
});
function acUsers($inp, $ac, $tr) {
    // 內部人員：沿用綁定搜尋擋不到，改用客戶/廠商以外的簡單提示（第一期先讓使用者自行輸入姓名）
    $ac.removeClass('on');
    $inp.attr('data-id', '');
}
function acSearch(type, kw, $ac, onPick) {
    kw = $.trim(kw || '');
    if (kw.length < 1) { $ac.removeClass('on').empty(); return; }
    apiGet('bind_search', { type: type, kw: kw }).done(function (r) {
        $ac.empty();
        if (!r.success || !r.rows || !r.rows.length) { $ac.removeClass('on'); return; }
        $.each(r.rows, function (i, row) {
            $('<div>').append($('<span>').text(row.label))
                .append($('<span class="s">').text(row.sub || ''))
                .on('mousedown', function (e) { e.preventDefault(); onPick(row); })
                .appendTo($ac);
        });
        $ac.addClass('on');
    });
}
$(document).on('blur', '.q-tn, #gBindKw, #iTargetKw', function () {
    var $ac = $(this).closest('.ac-wrap').find('.ac-list');
    setTimeout(function () { $ac.removeClass('on'); }, 150);
});

/* ── 綁定 ─────────────────────────────────────────────────────────── */
$('#gBindType').on('change', function () {
    var t = $(this).val(), meta = DICT.bind_types[t] || {};
    $('#gBindKw').val('').attr('data-id', '');
    if (Number(meta.manual)) {
        $('#gBindLbl').text('直接輸入單號');
        $('#gBindKw').attr('placeholder', '例：K-1150828（紙本上的單號）');
        $('#btnAddManual').show();
    } else {
        $('#gBindLbl').text('關鍵字');
        $('#gBindKw').attr('placeholder', '打字搜尋');
        $('#btnAddManual').hide();
    }
});
$('#gBindKw').on('input', function () {
    var t = $('#gBindType').val(), meta = DICT.bind_types[t] || {};
    if (Number(meta.manual)) return;
    acSearch(t, $(this).val(), $('#gBindAc'), function (row) {
        $('#gBindAc').removeClass('on');
        tryAddBind(t, String(row.id), row.label);
    });
});
$('#btnAddManual').on('click', function () {
    var t = $('#gBindType').val(), v = $.trim($('#gBindKw').val());
    if (!v) { $('#gBindKw').closest('.ac-wrap').addClass('has-err'); $('#eBind').text('請輸入單號'); return; }
    $('#gBindKw').closest('.ac-wrap').removeClass('has-err');
    tryAddBind(t, v, v);
    // 同一個單號在別的案件出現過就提示（日後異常單電子化時靠這個字串接得起來）
    apiGet('manual_no_check', { type: t, no: v, except: EDIT_ID }).done(function (r) {
        if (r.success && r.rows && r.rows.length) {
            alert('這個單號在另外 ' + r.rows.length + ' 筆紀錄也出現過：\n'
                + $.map(r.rows, function (x) { return x.log_no + '　' + x.title; }).join('\n'));
        }
    });
});

function tryAddBind(type, id, label) {
    for (var i = 0; i < BINDS.length; i++) {
        if (BINDS[i].bind_type === type && String(BINDS[i].bind_id) === String(id)) {
            $('#gBindKw').val(''); return;   // 去重
        }
    }
    if ($.inArray(type, DICT.multipart) >= 0) {
        // 出貨單／退貨單：底下不只一個料號時要問綁哪幾個
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
                    .append($('<span style="font-family:Consolas,monospace;">').text(p.part_no || ('d_id ' + p.d_id)))
                    .append($('<span style="text-align:right;color:#8a7358;font-size:12px;">').text(p.qty != null ? p.qty : ''))
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
    if (!BINDS.length) { $b.append($('<span style="font-size:12px;color:#a08a6f;">尚未綁定任何對象</span>')); }
    $.each(BINDS, function (i, x) {
        var meta = DICT.bind_types[x.bind_type] || {};
        var txt = x.bind_label || x.bind_id;
        if (x.sel_parts && x.sel_parts.length) txt += '（' + x.sel_parts.length + ' 個料號）';
        $('<span class="bind-badge">').addClass(Number(meta.manual) ? 'manual' : '')
            .append($('<b>').text(meta.name || x.bind_type))
            .append(document.createTextNode(txt))
            .append($('<a href="javascript:;" title="移除">&times;</a>').on('click', function () {
                BINDS.splice(i, 1); renderBinds();
            }))
            .appendTo($b);
    });
    // 只填手填單號的守門提示（提示不硬擋）
    var hasCarrier = false, manualNames = [];
    $.each(BINDS, function (i, x) {
        if ($.inArray(x.bind_type, DICT.carrier) >= 0) hasCarrier = true;
        if (Number((DICT.bind_types[x.bind_type] || {}).manual)) manualNames.push((DICT.bind_types[x.bind_type] || {}).name);
    });
    if (manualNames.length && !hasCarrier) {
        $('#gBindWarn').html('<b>提醒：</b>這筆只填了' + esc(manualNames.join('、'))
            + '的單號，系統查不到它的料號與客戶，之後用<b>客戶／料號／廠商都搜尋不到這筆紀錄</b>。'
            + '建議一併綁定 BOM、料號、出貨單或退貨單其中之一。（確實對不到料號的話仍可儲存，清單上會標「未連結料號」）').show();
    } else {
        $('#gBindWarn').hide();
    }
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
            var $row = $('<div style="font-size:12px;color:#8a7358;margin-bottom:3px;">').text('上傳中… ' + f.name).appendTo($list);
            $.ajax({ url: API, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
                .done(function (r) {
                    if (!r.success) { $row.css('color', '#DD5138').text(f.name + '：' + r.message); return; }
                    if (!(logId > 0 && ownerType === 'log')) bucket.push(r.id);
                    $row.empty().css('color', '#3a2c1a')
                        .append($('<span>').text(f.name))
                        .append($('<a href="javascript:;" style="margin-left:8px;color:#a2703a;">').text('移除')
                            .on('click', function () {
                                apiPost('file_delete', { id: r.id }).done(function (d) {
                                    if (!okOrAlert(d)) return;
                                    var k = $.inArray(r.id, bucket); if (k >= 0) bucket.splice(k, 1);
                                    $row.remove();
                                });
                            }));
                })
                .fail(function () { $row.css('color', '#DD5138').text(f.name + '：上傳失敗'); });
        })(files[i]);
    }
    input.value = '';
}

$('#btnSaveLog').on('click', function () {
    var title = $.trim($('#gTitle').val());
    $('#gTitle').closest('.fld').removeClass('has-err');
    if (!title) {
        $('#gTitle').closest('.fld').addClass('has-err'); $('#eTitle').text('請填寫標題');
        $('#gTitle').focus(); return;
    }
    var rv = $.trim($('#gRemindVal').val());
    var remind = rv === '' ? '' : (Number(rv) * Number($('#gRemindUnit').val()));

    var items = [];
    if (EDIT_ID === 0) {
        var bad = false;
        $('#qInputBody tr').each(function () {
            var $tr = $(this), q = $.trim($tr.find('.q-q').val());
            if (!q) return;
            var ad = $tr.find('.q-ad').val();
            if (ad && ad > TODAY) { bad = true; $tr.find('.q-ad').css('border-color', '#DD5138'); }
            items.push({
                question: q, target_type: $tr.find('.q-tt').val(),
                target_id: $tr.find('.q-tn').attr('data-id') || '',
                target_label: $.trim($tr.find('.q-tn').val()),
                target_contact: $.trim($tr.find('.q-tc').val()),
                asked_at: ad
            });
        });
        if (bad) { alert('提出日期不可以是未來日期'); return; }
    }

    var $btn = $(this).prop('disabled', true);
    apiPost('save_log', {
        id: EDIT_ID, title: title, log_type: $('#gType').val(), visibility: $('#gVis').val(),
        deadline: $('#gDeadline').val(), remind_before_minutes: remind,
        urgent_days: $.trim($('#gUrgent').val()), note: $.trim($('#gNote').val()),
        binds: JSON.stringify(BINDS), items: JSON.stringify(items), temp_files: JSON.stringify(TEMP_FILES)
    }).done(function (r) {
        $btn.prop('disabled', false);
        if (!okOrAlert(r)) return;
        closeMask('logMask');
        loadList(); loadFilterOptions();   // 綁定變了，三軸選項要跟著更新
        if (r.warning) alert('已儲存。\n\n' + r.warning);
        openDetail(r.id);
    }).fail(function () { $btn.prop('disabled', false); });
});

/* ── 案件明細 ─────────────────────────────────────────────────────── */
function openDetail(id) {
    apiGet('get', { id: id }).done(function (r) {
        if (!okOrAlert(r)) return;
        CUR = r;
        renderDetail();
        openMask('detMask');
    });
}
function renderDetail() {
    var g = CUR.log, w = CUR.log.can_write && CAN_EDIT;
    $('#detNo').text(g.log_no + '　' + g.title);
    $('#detSub').text((DICT.log_types[g.log_type] || '') + ' ／ ' + dispDate(g.created_at)
        + ' ／ ' + (g.owner_name || '') + ' ／ ' + (DICT.visibility[g.visibility] || ''));

    var $bd = $('#detBinds').empty();
    if (!(CUR.binds || []).length) $bd.append($('<span style="font-size:12px;color:#a08a6f;">未綁定任何對象</span>'));
    $.each(CUR.binds || [], function (i, b) {
        var meta = DICT.bind_types[b.bind_type] || {};
        var txt = b.bind_label || b.bind_id;
        var sel = b.sel_parts ? JSON.parse(b.sel_parts) : null;
        if (sel && sel.length) txt += '（' + sel.length + ' 個料號）';
        $('<span class="bind-badge">').addClass(Number(b.is_manual) ? 'manual' : '')
            .append($('<b>').text(meta.name || b.bind_type)).append(document.createTextNode(txt)).appendTo($bd);
    });
    var done = 0;
    $.each(CUR.items, function (i, it) { if (it.status === 'answered' || it.status === 'resolved') done++; });
    $bd.append($('<span style="font-size:11.5px;color:#8a7358;margin-left:8px;">')
        .text('已回 ' + done + ' / ' + CUR.items.length));

    if (g.note) $('#detNote').text('備註：' + g.note).show(); else $('#detNote').hide();
    if (CUR.unlinked) {
        $('#detUnlinked').html('<b>未連結料號：</b>這筆目前沒有連到任何料號，'
            + '之後用客戶／料號／廠商都查不到它。請編輯後綁定 BOM、料號、出貨單或退貨單。').show();
    } else $('#detUnlinked').hide();

    var $acts = $('#detItemActs').empty();
    if (w && g.status !== 'done') {
        $acts.append($('<button class="btn btn-xs btn-default">').html('<i class="fa fa-plus"></i> 新增問題')
            .on('click', function () { openItem(null); }));
        if (CUR.items.length) {
            $acts.append($('<button class="btn btn-xs" style="margin-left:6px;background:#F0A24B;color:#fff;border-color:#d98a33;">')
                .html('<i class="fa fa-reply"></i> 填回覆').on('click', function () { openReply(null); }));
        }
    }
    renderItems(w && g.status !== 'done');

    var $f = $('#detFiles').empty();
    var logFiles = $.grep(CUR.files, function (x) { return x.owner_type === 'log'; });
    if (!logFiles.length) $f.append($('<span style="font-size:12px;color:#a08a6f;">沒有案件層附件</span>'));
    $.each(logFiles, function (i, x) {
        $('<a class="att" target="_blank">').attr('href', API + '?action=download&id=' + x.id)
            .text('📎 ' + (x.original_name || x.file_name)).appendTo($f);
    });

    if (g.status === 'done') {
        $('#detConclusion').html('<b>結論：</b>' + esc(g.conclusion || '') + '　'
            + '<span style="color:#8a7358;font-size:12px;">（' + esc(dispDate(g.closed_at)) + ' 結案）</span>').show();
    } else $('#detConclusion').hide();

    var $foot = $('#detFoot').empty();
    $foot.append($('<button class="btn btn-default">').text('關閉').on('click', function () { closeMask('detMask'); }));
    if (w) {
        $foot.append($('<button class="btn btn-default">').text('編輯').on('click', function () {
            closeMask('detMask'); openLogModal(CUR.log.id);
        }));
        if (g.status === 'done') {
            $foot.append($('<button class="btn" style="background:#b5762f;color:#fff;">').text('重新開啟')
                .on('click', function () {
                    apiPost('reopen_log', { id: CUR.log.id }).done(function (r) {
                        if (okOrAlert(r)) { openDetail(CUR.log.id); loadList(); }
                    });
                }));
        } else {
            $foot.append($('<button class="btn" style="background:#8a5a2b;color:#fff;">').text('結案')
                .on('click', function () {
                    $('#cConclusion').val(''); $('#cConclusion').closest('.fld').removeClass('has-err');
                    openMask('closeMask2');
                }));
        }
        $foot.append($('<button class="btn btn-default" style="color:#DD5138;">').text('刪除')
            .on('click', function () {
                if (!confirm('確定刪除這筆紀錄？底下的問題、回覆與附件都會一起刪除，且無法復原。')) return;
                apiPost('delete_log', { id: CUR.log.id }).done(function (r) {
                    if (okOrAlert(r)) { closeMask('detMask'); loadList(); }
                });
            }));
    }
}

function renderItems(canWrite) {
    var $w = $('#detItems').empty();
    if (!CUR.items.length) {
        $w.append($('<div style="padding:16px;color:#8a7358;font-size:13px;">還沒有列出任何問題。</div>'));
        return;
    }
    $.each(CUR.items, function (i, it) {
        var $q = $('<div class="qitem">').addClass(it.status === 'resolved' || it.status === 'dropped' ? 'done' : '');
        var $h = $('<div class="qhead">');
        $h.append($('<span class="qno">').text(it.seq));
        $h.append($('<span class="qtext">').text(it.question));

        var $m = $('<span class="qmeta">');
        if (it.target_label) $m.append($('<b>').text(it.target_label));
        else $m.append($('<b>').text('—'));
        if (it.target_contact) $m.append(document.createTextNode(it.target_contact));
        $h.append($m);
        $h.append($('<span class="qdate">').text(it.asked_at ? dispDate(it.asked_at).substring(5) : ''));

        var $s = $('<span class="qstat">');
        if (it.status === 'waiting') {
            $s.append($('<span class="chip">').addClass(it.overdue ? 'chip-over' : 'chip-wait')
                .text((it.overdue ? '逾期 ' : '待回覆 ') + it.wait_days + ' 天'));
        } else if (it.status === 'answered') $s.append($('<span class="chip chip-done">').text('已回覆'));
        else if (it.status === 'resolved') $s.append($('<span class="chip chip-close">').text('已解決'));
        else $s.append($('<span class="chip chip-close">').text('不處理'));
        $h.append($s);

        var $a = $('<span class="qact">');
        if (canWrite) {
            $a.append($('<button class="btn btn-xs btn-default" title="填這一條的回覆">').text('回覆')
                .on('click', function (e) { e.stopPropagation(); openReply([it.id]); }));
            $a.append($('<a href="javascript:;" style="margin-left:6px;font-size:11.5px;color:#8a5a2b;">').text('⋯')
                .on('click', function (e) { e.stopPropagation(); itemMenu(it, $(this)); }));
        }
        $h.append($a);
        $q.append($h);

        if ((it.replies || []).length) {
            var $r = $('<div class="qreplies">');
            $.each(it.replies, function (j, rp) {
                var $one = $('<div class="rep">');
                $one.append($('<span class="rdate">').text(dispDate(rp.replied_on).substring(5))
                    .append($('<span class="rwho">').text((rp.channel ? (DICT.channels[rp.channel] || rp.channel) : ''))));
                var $body = $('<span class="rbody">').text(rp.content);
                if (rp.reply_by) $body.prepend($('<span style="color:#8a7358;">').text(rp.reply_by + '：'));
                var rf = $.grep(CUR.files, function (x) { return x.owner_type === 'reply' && Number(x.owner_id) === Number(rp.id); });
                $.each(rf, function (k, f) {
                    $body.append($('<a class="att" target="_blank">').attr('href', API + '?action=download&id=' + f.id)
                        .text('📎 ' + (f.original_name || f.file_name)));
                });
                $one.append($body);
                var $d = $('<span class="rdel">');
                if (canWrite) {
                    $d.append($('<a href="javascript:;" title="刪除這則回覆">&times;</a>').on('click', function () {
                        if (!confirm('確定刪除這則回覆？')) return;
                        apiPost('reply_delete', { log_id: CUR.log.id, id: rp.id }).done(function (r) {
                            if (okOrAlert(r)) openDetail(CUR.log.id);
                        });
                    }));
                }
                $one.append($d);
                $r.append($one);
            });
            $q.append($r);
        }
        if (it.conclusion) {
            $q.append($('<div style="margin:6px 0 0 calc(2rem + 10px);font-size:12.5px;color:#8a5a2b;">')
                .text('處理：' + it.conclusion));
        }
        $w.append($q);
    });
}

/* 問題項的更多動作 */
function itemMenu(it, $anchor) {
    var opts = ['編輯這一條', '標記為已解決', '標記為不處理', '退回待回覆', '再等幾天（順延提醒）', '刪除這一條'];
    var pick = prompt('要對第 ' + it.seq + ' 條做什麼？請輸入編號：\n'
        + $.map(opts, function (x, i) { return (i + 1) + '. ' + x; }).join('\n'), '');
    if (!pick) return;
    pick = Number($.trim(pick));
    if (pick === 1) { openItem(it); return; }
    if (pick === 2 || pick === 3) {
        var st = pick === 2 ? 'resolved' : 'dropped';
        var c = prompt('（選填）這一條最後怎麼處理？', it.conclusion || '');
        if (c === null) return;
        apiPost('item_status', { log_id: CUR.log.id, id: it.id, status: st, conclusion: c })
            .done(function (r) { if (okOrAlert(r)) openDetail(CUR.log.id); });
        return;
    }
    if (pick === 4) {
        apiPost('item_status', { log_id: CUR.log.id, id: it.id, status: 'waiting', conclusion: it.conclusion || '' })
            .done(function (r) { if (okOrAlert(r)) openDetail(CUR.log.id); });
        return;
    }
    if (pick === 5) {
        var d = prompt('再等幾天？（催過一次、對方說再給幾天時用；不順延的話這一條不會再提醒）', '3');
        if (d === null) return;
        apiPost('item_snooze', { log_id: CUR.log.id, id: it.id, days: Number($.trim(d)) })
            .done(function (r) { if (okOrAlert(r)) openDetail(CUR.log.id); });
        return;
    }
    if (pick === 6) {
        if (!confirm('確定刪除第 ' + it.seq + ' 條？底下的回覆與附件會一起刪除。')) return;
        apiPost('item_delete', { log_id: CUR.log.id, id: it.id })
            .done(function (r) { if (okOrAlert(r)) openDetail(CUR.log.id); });
    }
}

/* 單一問題項 新增／編輯 */
function openItem(it) {
    ITEM_EDIT = it;
    $('#itemMTitle').text(it ? ('編輯第 ' + it.seq + ' 條問題') : '新增問題');
    $('#iQuestion').val(it ? it.question : '');
    $('#iTargetType').val(it ? (it.target_type || 'customer') : 'customer');
    $('#iTargetKw').val(it ? (it.target_label || '') : '').attr('data-id', it ? (it.target_id || '') : '');
    $('#iContact').val(it ? (it.target_contact || '') : '');
    $('#iAsked').val(it ? (it.asked_at || TODAY) : TODAY);
    $('#iFollowUp').val(it ? (it.follow_up_days || '') : '');
    $('.has-err').removeClass('has-err');
    openMask('itemMask');
}
$('#iTargetKw').on('input', function () {
    var t = $('#iTargetType').val();
    var $inp = $(this);
    if (t === 'user') { $('#iTargetAc').removeClass('on'); $inp.attr('data-id', ''); return; }
    acSearch(t, $inp.val(), $('#iTargetAc'), function (row) {
        $inp.val(row.label).attr('data-id', row.id); $('#iTargetAc').removeClass('on');
    });
});
$('#btnSaveItem').on('click', function () {
    var q = $.trim($('#iQuestion').val());
    $('#iQuestion').closest('.fld').removeClass('has-err');
    if (!q) { $('#iQuestion').closest('.fld').addClass('has-err'); $('#eIQuestion').text('請填寫問題內容'); return; }
    var ad = $('#iAsked').val();
    $('#iAsked').closest('.fld').removeClass('has-err');
    if (ad && ad > TODAY) { $('#iAsked').closest('.fld').addClass('has-err'); $('#eIAsked').text('提出日期不可以是未來日期'); return; }
    apiPost('item_save', {
        log_id: CUR.log.id, id: ITEM_EDIT ? ITEM_EDIT.id : 0, question: q,
        target_type: $('#iTargetType').val(), target_id: $('#iTargetKw').attr('data-id') || '',
        target_label: $.trim($('#iTargetKw').val()), target_contact: $.trim($('#iContact').val()),
        asked_at: ad, follow_up_days: $.trim($('#iFollowUp').val())
    }).done(function (r) {
        if (!okOrAlert(r)) return;
        closeMask('itemMask'); openDetail(CUR.log.id);
    });
});

/* ── 填回覆（可一次套用到多條問題） ────────────────────────────────── */
function openReply(itemIds) {
    REP_TEMP_FILES = []; $('#rFileList').empty(); $('#rFile').val('');
    $('#rContent').val(''); $('#rBy').val(''); $('#rDate').val(TODAY);
    $('#rChannel').val('phone');
    $('.has-err').removeClass('has-err');
    var $w = $('#repItems').empty();
    var pending = $.grep(CUR.items, function (x) { return x.status === 'waiting' || x.status === 'answered'; });
    var show = pending.length ? pending : CUR.items;
    $.each(show, function (i, it) {
        var checked = itemIds ? ($.inArray(it.id, itemIds) >= 0) : false;
        $('<div class="pick-row" style="grid-template-columns:1.6rem minmax(0,1fr) 5rem;">')
            .append($('<span>').append($('<input type="checkbox" class="rp">').val(it.id).prop('checked', checked)))
            .append($('<span style="font-size:12.5px;">').text(it.seq + '. ' + it.question))
            .append($('<span style="font-size:11.5px;color:#8a7358;text-align:right;">').text(it.target_label || ''))
            .appendTo($w);
    });
    $('#repSub').text('共 ' + show.length + ' 條可勾選');
    openMask('repMask');
}
$('#btnSaveReply').on('click', function () {
    var ids = [];
    $('#repItems .rp:checked').each(function () { ids.push(Number($(this).val())); });
    if (!ids.length) { alert('請至少勾選一條問題'); return; }
    var content = $.trim($('#rContent').val());
    $('#rContent').closest('.fld').removeClass('has-err');
    if (!content) { $('#rContent').closest('.fld').addClass('has-err'); $('#eRContent').text('請填寫回覆內容'); return; }
    var d = $('#rDate').val();
    $('#rDate').closest('.fld').removeClass('has-err');
    if (d && d > TODAY) { $('#rDate').closest('.fld').addClass('has-err'); $('#eRDate').text('回覆日期不可以是未來日期'); return; }

    var $btn = $(this).prop('disabled', true);
    apiPost('reply_add', {
        log_id: CUR.log.id, item_ids: JSON.stringify(ids), replied_on: d,
        reply_by: $.trim($('#rBy').val()), channel: $('#rChannel').val(), content: content,
        temp_files: JSON.stringify(REP_TEMP_FILES)
    }).done(function (r) {
        $btn.prop('disabled', false);
        if (!okOrAlert(r)) return;
        closeMask('repMask'); openDetail(CUR.log.id); loadList();
    }).fail(function () { $btn.prop('disabled', false); });
});

/* ── 結案 ─────────────────────────────────────────────────────────── */
$('#btnDoClose').on('click', function () {
    var c = $.trim($('#cConclusion').val());
    $('#cConclusion').closest('.fld').removeClass('has-err');
    if (!c) { $('#cConclusion').closest('.fld').addClass('has-err'); $('#eCConclusion').text('請填寫結論'); return; }
    apiPost('close_log', { id: CUR.log.id, conclusion: c }).done(function (r) {
        if (!okOrAlert(r)) return;
        closeMask('closeMask2'); openDetail(CUR.log.id); loadList();
    });
});

/* ── 雜項 ─────────────────────────────────────────────────────────── */
$('#btnPageHelp').on('click', function () { openMask('helpUseMask'); });
$('#btnRoleHelp').on('click', function () { openMask('roleMask'); });
$(window).on('scroll', function () { $('#btnTop').toggle($(window).scrollTop() > 300); });
$('#btnTop').on('click', function () { $('html,body').animate({ scrollTop: 0 }, 200); });
$(document).on('keydown', function (e) {
    if (e.which === 27) $('.el-mask.on').last().removeClass('on');
});
</script>
</body>
</html>
