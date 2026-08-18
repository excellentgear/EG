<?php
/**
 * 公出單（2-MM-01-06）— 全新獨立頁面（2026-08-18 建立）
 * 外出都要填的單據，不限教育訓練：全體在職員工都能開自己的單；外訓場次「確認開課」時會自動產生草稿。
 * 簽核＝單位主管一關（主管本人公出改由最高核准人員），系統管理者可設「免簽核」＝送出即核准。
 * 資料一律走 src/store/BusinessTrip_API.php；共用邏輯 src/common/business_trip_lib.php
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/business_trip.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/business_trip_lib.php';

$db = (new DBConnection())->getPDO();
bt_ensure_schema($db);
$btUser  = bt_current_user($db);
$perms   = bt_perms($db, $btUser);
$roleLabel = $perms['isAdmin'] ? '管理者'
           : ($perms['canAdmin'] ? '公出單管理員'
           : ($perms['canViewAll'] ? '公出單檢閱' : ($perms['canApply'] ? '一般員工（可開自己的單）' : '無權限')));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>公出單</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px;
            background:#F0A24B; color:#fff; cursor:pointer; }
        .page-help-btn:hover { background:#d98a33; }
        @media print { .page-help-btn { display:none !important; } }
        .help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc b { color:#8A5A2B; }
        .bt-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .bt-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .bt-toolbar select, .bt-toolbar input, .bt-toolbar button {
            height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; }
        .bt-toolbar button { cursor:pointer; }
        .bt-toolbar button:hover { background:#F7E0BD; }
        .bt-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .bt-toolbar .btn-warm:hover { background:#d98a33; }
        .bt-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }
        .bt-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.bt-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.bt-table th, table.bt-table td { border:1px solid #EADFC8; padding:5px 8px; text-align:center; }
        table.bt-table thead th { background:#F7E0BD; color:#5b3a1e; font-weight:bold; white-space:nowrap; }
        table.bt-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.bt-table td.l { text-align:left; }
        .bt-op { color:#b5762a; cursor:pointer; margin:0 4px; white-space:nowrap; }
        .bt-op:hover { color:#8A5A2B; text-decoration:underline; }
        .bt-pager { display:flex; justify-content:flex-end; align-items:center; gap:6px; margin:6px 0; font-size:13px; color:#5b3a1e; }
        .bt-pager button { height:26px; padding:0 9px; border:1px solid #D8BE93; background:#fff; border-radius:4px; cursor:pointer; }
        .bt-pager button.on { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .st { display:inline-block; padding:1px 9px; border-radius:10px; font-size:12px; }
        .st-draft { background:#EFE7D8; color:#6b5535; }
        .st-submitted { background:#F7E0BD; color:#8A5A2B; }
        .st-approved { background:#F0A24B; color:#fff; }
        .st-rejected { background:#DD5138; color:#fff; }
        .bt-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:9000; overflow:auto; }
        .bt-modal { background:#fff; border-radius:8px; margin:40px auto; max-width:820px; width:94%; box-shadow:0 8px 30px rgba(0,0,0,.3); }
        .bt-modal.narrow { max-width:520px; }
        .bt-pull { display:flex; align-items:center; gap:10px; flex-wrap:wrap; background:#FBF5EA; border:1px solid #EADFC8;
                   border-radius:6px; padding:8px 10px; margin-bottom:10px; }
        .bt-pull .bt-hint { margin:0; }
        .bt-pulled { background:#F7E0BD; border-color:#E0BE86; }
        .bt-class-ref { background:#FDF8F0; border:1px solid #E0BE86; border-left:4px solid #F0A24B; border-radius:6px;
                        padding:8px 10px; margin-bottom:10px; font-size:13px; color:#5b3a1e; line-height:1.8; }
        .bt-class-ref b { color:#8A5A2B; }
        .bt-class-ref table { border-collapse:collapse; margin-top:4px; }
        .bt-class-ref td { border:1px solid #E0BE86; padding:2px 8px; white-space:nowrap; }
        .bt-class-ref td.d { color:#5b3a1e; }
        .bt-class-ref td.c { background:#FBF5EA; }
        .bt-toolbar button.btn-danger { background:#DD5138; border-color:#C4442D; color:#fff; }
        .bt-toolbar button.btn-danger:hover { background:#C4442D; }
        .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:9px 14px; border-radius:8px 8px 0 0; display:flex; }
        .m-close { margin-left:auto; cursor:pointer; }
        .m-body { padding:14px; max-height:70vh; overflow:auto; }
        .m-foot { padding:10px 14px; border-top:1px solid #EADFC8; text-align:right; }
        .m-foot button, .b-att { height:32px; padding:0 14px; border-radius:4px; border:1px solid #D8BE93; background:#fff; color:#5b3a1e; cursor:pointer; }
        .m-foot .b-ok { background:#F0A24B; color:#fff; border-color:#d98a33; margin-left:6px; }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:0 0 3px; font-weight:normal; }
        .m-body input[type=text], .m-body input[type=date], .m-body input[type=number], .m-body select, .m-body textarea {
            width:100%; border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:13px; color:#5b3a1e; }
        .m-body textarea { resize:vertical; }
        .ro-auto { background:#F3EADB; color:#7a6446; }
        .bt-hint { font-size:12px; color:#8a6d45; line-height:1.7; }
        .bt-err { color:#DD5138; font-size:12px; margin-top:2px; display:none; }
        table.day-tbl { width:100%; border-collapse:collapse; font-size:13px; }
        table.day-tbl th, table.day-tbl td { border:1px solid #EADFC8; padding:3px 5px; text-align:center; }
        table.day-tbl th { background:#F7E0BD; color:#5b3a1e; }
        table.day-tbl input { width:100%; border:1px solid #E8D5B5; border-radius:3px; padding:3px 5px; font-size:13px; }
        .bt-noperm { border:1.5px solid #E8D5B5; background:#FDF8EF; border-radius:8px; padding:24px; color:#5b3a1e; }
        .bt-top { position:fixed; right:24px; bottom:24px; width:40px; height:40px; border-radius:20px; background:#F0A24B;
            color:#fff; border:none; cursor:pointer; display:none; z-index:100; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">公出單
                <small style="color:#8a6d45;">2-MM-01-06　外出（含外訓）都要填的單據</small></h2>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canApply'] && !$perms['canViewAll']): ?>
        <div class="bt-noperm">
            <h4><i class="fa fa-lock"></i> 無公出單使用權限</h4>
            <p>此帳號目前不是在職狀態，無法開立公出單。如有疑問請洽人事或系統管理者。</p>
        </div>
<?php else: ?>
        <div class="bt-toolbar">
            <label>範圍</label>
            <select id="scopeSel">
                <option value="mine">我的公出單</option>
                <option value="pending">待我核准</option>
                <?php if ($perms['canViewAll']): ?><option value="all">全部（管理）</option><?php endif; ?>
            </select>
            <label>狀態</label>
            <select id="statusSel">
                <option value="">全部</option>
                <option value="draft">草稿</option>
                <option value="submitted">待核准</option>
                <option value="approved">已核准</option>
                <option value="rejected">已退回</option>
            </select>
            <label>期間</label>
            <input type="date" id="fDateFrom" max="9999-12-31" style="width:140px;">
            <span>～</span>
            <input type="date" id="fDateTo" max="9999-12-31" style="width:140px;">
            <input type="text" id="fKw" placeholder="姓名/地點/事由/單號" style="width:170px;">
            <button id="btnSearch" class="btn-warm"><i class="fa fa-search"></i> 查詢</button>
            <button id="btnAdd"><i class="fa fa-plus"></i> 新增公出單</button>
            <button id="btnPrintSel"><i class="fa fa-print"></i> 批次列印所選</button>
            <?php if ($perms['isAdmin']): ?>
            <button id="btnDelSel" class="btn-danger"><i class="fa fa-trash"></i> 批次刪除所選</button>
            <?php endif; ?>
            <?php if ($perms['canAdmin']): ?>
            <button id="btnFromTraining"><i class="fa fa-graduation-cap"></i> 外訓批次帶入</button>
            <button id="btnSetting"><i class="fa fa-cog"></i> 模組設定</button>
            <?php endif; ?>
            <span class="bt-role-badge">目前角色：<?= htmlspecialchars($roleLabel, ENT_QUOTES) ?></span>
        </div>

        <div class="bt-pager">
            <span id="pgInfo"></span>
            <label>每頁</label>
            <select id="pgSize" style="height:26px;border:1px solid #D8BE93;border-radius:4px;">
                <option>5</option><option selected>10</option><option>20</option><option>50</option>
            </select>
            <span id="pgBtns"></span>
        </div>
        <div class="bt-table-wrap">
            <table class="bt-table">
                <thead><tr>
                    <th style="width:32px;"><input type="checkbox" id="chkAll"></th>
                    <th>單號</th><th>單據日期</th><th>公出人</th><th>單位</th><th>職稱</th>
                    <th>公出時間</th><th>地點</th><th>事由</th><th>狀態</th><th>核准人</th><th>操作</th>
                </tr></thead>
                <tbody id="listBody"><tr><td colspan="12" style="padding:20px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
<?php endif; ?>
        <button class="bt-top" id="btnTop" title="回到頂端"><i class="fa fa-arrow-up"></i></button>
    </div>
</div>
</div>

<!-- 公出單編輯 -->
<div class="bt-mask" id="editMask"><div class="bt-modal" data-eg-form data-eg-submit="#btnSave">
    <div class="m-head"><span id="editTitle">新增公出單</span><span class="m-close" onclick="closeMask('editMask')">✕</span></div>
    <div class="m-body">
        <div class="bt-pull" id="pullBox">
            <button type="button" class="b-att" id="btnPullTraining"><i class="fa fa-graduation-cap"></i> 從外訓帶入</button>
            <span class="bt-hint" id="pullHint">已「確認實行」的外訓課程，可一鍵帶入日期／時間／地點／事由；<b>單據日期會自動帶該場外訓的最早一天</b>。</span>
        </div>
        <div class="bt-class-ref" id="classRefBox" style="display:none;"></div>
        <div class="grid2">
            <div><label>單據日期 *</label><input type="date" id="fApplyDate" max="9999-12-31"></div>
            <div id="forUserBox" style="display:none;"><label>公出人（管理員可代開）</label>
                <select id="fUser" data-eg-filter="輸入姓名篩選…"></select></div>
            <div><label>單位</label><select id="fDept" data-eg-filter="輸入部門篩選…"></select></div>
            <div><label>職稱</label><input type="text" id="fPosition" maxlength="100"></div>
            <div><label>公出起日 *</label><input type="date" id="fFrom" max="9999-12-31"></div>
            <div><label>公出迄日 *</label><input type="date" id="fTo" max="9999-12-31"></div>
            <div><label>開始時間（可直接輸入 0900／900／9）</label><input type="text" id="fTimeFrom" maxlength="5" placeholder="09:00">
                <div class="bt-err" id="errTimeFrom"></div></div>
            <div><label>結束時間</label><input type="text" id="fTimeTo" maxlength="5" placeholder="17:00">
                <div class="bt-err" id="errTimeTo"></div></div>
            <div style="grid-column:1/3;"><label>公出地點 *</label><input type="text" id="fLocation" maxlength="200">
                <div class="bt-err" id="errLocation"></div></div>
            <div style="grid-column:1/3;"><label>事由 *</label><textarea id="fReason" rows="3" maxlength="2000"></textarea>
                <div class="bt-err" id="errReason"></div></div>
        </div>
        <div id="dayBox" style="display:none;margin-top:10px;">
            <div style="font-weight:bold;color:#5b3a1e;margin-bottom:4px;">每日時段（多天且各天時間不同時才需要逐日填；不填就用上面的起訖時間）</div>
            <table class="day-tbl">
                <thead><tr><th style="width:44px;">第</th><th style="width:150px;">日期</th><th style="width:90px;">開始</th>
                    <th style="width:90px;">結束</th><th style="width:30px;"></th></tr></thead>
                <tbody id="dayBody" data-eg-row-add="btDayAdd" data-eg-row-del="btDayDelLast"></tbody>
            </table>
            <div class="bt-hint" style="margin-top:4px;">
                <button type="button" class="b-att" onclick="btDayAdd()"><i class="fa fa-plus"></i> 新增一天</button>
                末列按 ↓ 自動加一列；日期需落在公出起訖日之間。
            </div>
        </div>
        <div class="bt-hint" id="editHint" style="margin-top:10px;"></div>
    </div>
    <div class="m-foot">
        <button onclick="printTrip(CUR_ID)" id="btnPrintOne"><i class="fa fa-print"></i> 列印</button>
        <button onclick="closeMask('editMask')">取消</button>
        <button class="b-ok" id="btnSave">儲存草稿</button>
        <button class="b-ok" id="btnSubmit"><i class="fa fa-paper-plane"></i> 儲存並送出</button>
    </div>
</div></div>

<!-- 從外訓帶入（一般員工＝自己的外訓；管理員＝目前選定公出人的外訓） -->
<div class="bt-mask" id="myTrMask" style="z-index:9100;"><div class="bt-modal">
    <div class="m-head"><span id="myTrTitle">從外訓帶入</span><span class="m-close" onclick="closeMask('myTrMask')">✕</span></div>
    <div class="m-body">
        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
            <label style="margin:0;">年度</label>
            <select id="myTrYear" style="width:110px;"></select>
            <input type="text" id="myTrKw" placeholder="課程名稱／單位關鍵字" style="width:190px;">
            <button type="button" class="b-att" id="btnMyTrLoad"><i class="fa fa-search"></i> 查詢</button>
            <span class="bt-hint" id="myTrWho" style="margin-left:auto;"></span>
        </div>
        <div class="bt-table-wrap">
            <table class="bt-table">
                <thead><tr><th style="width:170px;">上課日期</th><th>課程名稱</th><th style="width:150px;">外訓單位</th>
                    <th style="width:130px;">上課地點</th><th style="width:110px;">公出單</th><th style="width:80px;">操作</th></tr></thead>
                <tbody id="myTrBody"><tr><td colspan="6" style="padding:16px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
        <div class="bt-hint" style="margin-top:8px;">只列出<b>已確認實行</b>（已排定／已完成）的<b>外訓</b>課程；帶入後仍可自行修改再存檔。</div>
    </div>
    <div class="m-foot"><button onclick="closeMask('myTrMask')">關閉</button></div>
</div></div>

<!-- 核准／退回 -->
<div class="bt-mask" id="decideMask"><div class="bt-modal narrow" data-eg-form data-eg-submit="#btnDecideOk">
    <div class="m-head"><span id="decideTitle">核准公出單</span><span class="m-close" onclick="closeMask('decideMask')">✕</span></div>
    <div class="m-body">
        <div id="decideInfo" class="bt-hint" style="margin-bottom:10px;"></div>
        <label>決定 *</label>
        <select id="decideSel"><option value="approved">核准</option><option value="rejected">退回</option></select>
        <label style="margin-top:8px;">核准日期（業務日期）</label>
        <input type="date" id="decideDate" max="9999-12-31">
        <label style="margin-top:8px;">意見／退回原因<span id="noteReq" style="color:#DD5138;"> *（退回必填）</span></label>
        <textarea id="decideNote" rows="3" maxlength="500"></textarea>
        <div class="bt-err" id="errNote"></div>
    </div>
    <div class="m-foot">
        <button onclick="closeMask('decideMask')">取消</button>
        <button class="b-ok" id="btnDecideOk">送出決行</button>
    </div>
</div></div>

<?php if ($perms['canAdmin']): ?>
<!-- 模組設定 -->
<div class="bt-mask" id="setMask"><div class="bt-modal">
    <div class="m-head"><span>公出單 — 模組設定</span><span class="m-close" onclick="closeMask('setMask')">✕</span></div>
    <div class="m-body">
        <label>公出單 — AS 文件編號</label>
        <div style="display:flex;gap:6px;align-items:center;">
            <input type="text" id="setAsDoc" class="ro-auto" readonly style="flex:1;">
            <button type="button" class="b-att" id="btnPickAsDoc"><i class="fa fa-search"></i> 挑選</button>
            <button type="button" class="b-att" id="btnClearAsDoc">清除</button>
        </div>
        <div class="bt-hint" style="margin-top:4px;">列印表頭的表單名稱與右下角編號都由這裡的綁定推導（版次依單據日期回推），不在頁面寫死。</div>

        <div style="border-top:1px dashed #EADFC8;margin:14px 0 10px;"></div>
        <label>是否需要主管簽核<span id="needApprLock" style="color:#8a6d45;font-size:12px;"></span></label>
        <select id="setNeedAppr">
            <option value="0">免簽核（送出即視同核准，列印自動蓋章）</option>
            <option value="1">需要簽核（送出後通知單位主管核准／退回）</option>
        </select>
        <div class="bt-hint" style="margin-top:4px;">此項僅系統管理者可改。找不到單位主管與最高核准人員時，一律自動核准避免卡關。
            主管本人公出時，核准人自動改為最高核准人員（比照紙本附註）。</div>

        <label style="margin-top:10px;">外訓場次確認開課時自動產生公出單</label>
        <select id="setAutoTr">
            <option value="1">自動產生（每位參加人員各一張草稿）</option>
            <option value="0">不自動產生（改用「從外訓帶入」手動補）</option>
        </select>

        <label style="margin-top:10px;">外訓帶入的通勤時間（分鐘）</label>
        <input type="number" id="setCommute" min="0" max="240" step="5" style="width:120px;">
        <div class="bt-hint" style="margin-top:4px;">從外訓帶入（含自動產生、批次帶入）時，公出時間會自動
            <b>提前這個分鐘數出發、延後這個分鐘數結束</b>，作為往返通勤時間；填 0 表示公出時間就等於上課時間。帶入後仍可自行修改。</div>

        <label style="margin-top:10px;">核准圖章樣式</label>
        <select id="setStampTpl"><option value="0">（預設圓形圖章）</option></select>
        <div class="bt-hint" style="margin-top:4px;">有上傳掃描實體章的人一律優先用掃描章，這裡只影響沒掃描章時自動產生的印章樣式。</div>

        <div style="border-top:1px dashed #EADFC8;margin:14px 0 10px;"></div>
        <div style="font-weight:bold;color:#5b3a1e;margin-bottom:6px;">列印簽章欄（會計／單位主管 兩格）</div>
        <div class="grid2">
            <div><label>會計格</label>
                <input type="text" class="ro-auto" readonly value="固定留白（有請款需求時才由會計手蓋）">
                <div class="bt-hint" style="margin-top:2px;">會計只有在需要請款時才蓋章，故系統不自動帶人、一律留白。</div></div>
            <div><label>單位主管格</label><select id="setSignGroup"></select>
                <div class="bt-hint" style="margin-top:2px;">預設「實際核准的單位主管」＝蓋真正核准這張單的人。</div></div>
        </div>
        <div class="bt-hint" style="margin-top:4px;">選「留白」列印時空白，供紙本手蓋；選項的實際人員都是當下查組織角色綁定得出，不寫死人名。
            <b>主管本人公出時</b>核准人會自動改成最高核准人員，選「實際核准的單位主管」時該格就會蓋到總經理的章（比照紙本附註）；
            由代理人代簽時圖章右下角會加「代」字。</div>
    </div>
    <div class="m-foot">
        <button onclick="closeMask('setMask')">關閉</button>
        <button class="b-ok" id="btnSaveSet">儲存設定</button>
    </div>
</div></div>

<!-- 從外訓帶入 -->
<div class="bt-mask" id="trMask"><div class="bt-modal">
    <div class="m-head"><span>外訓批次帶入（整場次每位參加人員各開一張草稿）</span><span class="m-close" onclick="closeMask('trMask')">✕</span></div>
    <div class="m-body">
        <div style="display:flex;gap:6px;align-items:center;margin-bottom:8px;">
            <label style="margin:0;">年度</label>
            <input type="number" id="trYear" style="width:100px;" min="2000" max="2999">
            <button type="button" class="b-att" id="btnTrLoad">查詢</button>
        </div>
        <div class="bt-table-wrap">
            <table class="bt-table">
                <thead><tr><th>開課日</th><th>課程名稱</th><th>外訓單位</th><th>參加人數</th><th>已開公出單</th><th>操作</th></tr></thead>
                <tbody id="trBody"><tr><td colspan="6" style="padding:16px;color:#8a6d45;">請先查詢</td></tr></tbody>
            </table>
        </div>
    </div>
    <div class="m-foot"><button onclick="closeMask('trMask')">關閉</button></div>
</div></div>
<?php endif; ?>

<!-- 使用說明（鐵律7） -->
<div class="bt-mask" id="helpUseMask"><div class="bt-modal">
    <div class="m-head"><span>公出單 — 使用說明</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>這個頁面在做什麼</h4>
        <p>把紙本 <b>2-MM-01-06 公出單</b> 線上化。<b>任何因公外出都要填</b>（拜訪客戶、送件、外訓…），不限教育訓練。
        送出後由單位主管核准，核准後即可列印（含簽章圖章），紙本交管理課存查。</p>
        <h4>操作步驟</h4>
        <p>①按<b>「新增公出單」</b>填單據日期、公出起訖日期時間、地點、事由（單位與職稱會自動帶入，可改）。<br>
        　　參加外訓的話，直接按跳窗上方的<b>「從外訓帶入」</b>選課程，日期／時間／地點／事由會一次帶好（見下方說明）。<br>
        ②多天且每天時段不同時，展開<b>「每日時段」</b>逐日填；同一個時段就不用填。<br>
        ③按<b>「儲存並送出」</b>：需要簽核時會通知你的單位主管；設定為免簽核時，送出即視同核准。<br>
        ④主管在通知或本頁<b>「待我核准」</b>分頁核准／退回（<b>退回一定要填原因</b>）。<br>
        ⑤已核准的單按<b>「列印」</b>印出紙本；清單勾選多筆可<b>批次列印</b>（依序各自開視窗，請允許彈出視窗）。</p>
        <h4>重要行為／常見疑問</h4>
        <p>・<b>從外訓帶入（自己補開單用）</b>：新增／編輯公出單時按<b>「從外訓帶入」</b>，會列出教育訓練頁已
        <b>確認實行</b>（已排定／已完成）且<b>你本人列在參加人員名單</b>裡的外訓課程，點「帶入」即自動填入公出起訖日期、
        每日時段、上課地點與事由；<b>公出時間會自動比上課時間提前出發、延後結束</b>（分鐘數在模組設定的
        「外訓帶入的通勤時間」，預設 30 分鐘），跳窗上方會列出<b>原始上課日期時間</b>供你對照修改。<b>單據日期會自動設成該場外訓的最早一天</b>（補舊資料才不會全部印成今天）；
        帶入後所有欄位仍可自行修改。已經有公出單的場次會標示狀態，重複帶入前會再確認一次。<br>
        　　<b>公出單管理員／系統管理者</b>在跳窗選定「公出人」後按「從外訓帶入」，撈到的是<b>該員</b>的外訓資料，方便代為補單。<br>
        ・<b>外訓會自動產生公出單</b>：教育訓練的外訓場次按「確認開課」時，系統會為每位參加人員各產生一張<b>草稿</b>
        （日期／時間／地點／事由自動帶入，一張涵蓋整個訓練期間，<b>單據日期＝該場外訓最早一天</b>），
        你只要確認後送出即可。已送出／已核准的單不會被覆蓋。<br>
        ・<b>主管本人公出</b>：核准人自動改為最高核准人員（比照紙本附註「主管公出請總經理代理」）。<br>
        ・<b>主管請假</b>時，核准人會依代理設定自動轉給代理人（全站共用的代理系統，不是這一頁自己猜）。<br>
        ・<b>已送出的單不能改</b>，要改請先請主管退回，或另開新單。<br>
        ・列印的表單名稱、右下角文件編號、版次都來自 AS 文件綁定，<b>版次依單據日期回推</b>當時生效的版本。</p>
        <h4>設定入口</h4>
        <p>本頁工具列<b>「模組設定」</b>（限公出單管理員）：AS 文件編號綁定、是否需要主管簽核（<b>僅系統管理者可改</b>）、
        外訓是否自動產生、通勤時間、核准圖章樣式、列印簽章兩格（<b>會計格固定留白</b>，只有需要請款時才由會計手蓋；
        <b>單位主管格</b>蓋實際核准這張單的人，可另選來源）。
        部門主管與最高核准人員的認定來自<a href="../admin/org_role_setting.php" target="_blank" style="color:#b5762a;">組織角色綁定設定</a>。</p>
        <h4>權限角色</h4>
        <p><b>一般在職員工</b>：不需指派任何角色，就能開立／檢視／列印<b>自己的</b>公出單，並核准指派給自己的單。
        <b>公出單檢閱</b>：可查看全部人的公出單（唯讀）。<b>公出單管理員</b>：查全部＋代其他人開單（含代撈該員外訓帶入）、刪除、模組設定、AS 綁定、外訓批次帶入。
        <b>系統管理者</b>：以上全部，另有<b>「批次刪除所選」</b>——勾選清單後一次刪除（含已送出／已核准的單，
        整批同一個交易，失敗會整批回捲），主要用在補資料時清掉整批誤產生的單；刪除為軟刪除，清單與統計不再出現。
        管理者固定擁有全部權限。角色指派於「使用者權限設定」。</p>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">我知道了</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});

var API = '../../src/store/BusinessTrip_API.php';
var META = null, ROWS = [], CUR_ID = 0, CUR_TRIP = null, PAGE = 1, PSIZE = 10, DECIDE_ID = 0;
var PULL_REF = 0, MY_TR = [];      // PULL_REF＝這張單帶入的外訓場次 session_id（存檔時一併寫入來源）
var STATUS_LABEL = {draft:'草稿', submitted:'待核准', approved:'已核准', rejected:'已退回'};

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function dispDate(d){ return d ? egFmtDate(d) : ''; }        // 顯示一律 YYYY.MM.DD（ai-rules/20）
function openMask(id){ document.getElementById(id).style.display='block'; }
function closeMask(id){ document.getElementById(id).style.display='none'; }
function stampHtml(name, date, isDeputy){
    try { if (window.EGStamp && EGStamp.stamp)
        return EGStamp.stamp(name, date||'', !!isDeputy, (META && META.stamp_template) ? META.stamp_template.schema : null); } catch(e){}
    return '<span style="font-size:14px;">'+esc(name)+'</span>'+(date?'<div style="font-size:10px;color:#555;">'+esc(date)+'</div>':'');
}
$(document).ajaxError(function(e, x){
    var m = (x.responseJSON && x.responseJSON.error) || ('HTTP '+x.status);
    alert('操作失敗：'+m);
});

/* ================= 載入 ================= */
function loadMeta(then){
    $.getJSON(API, {action:'meta'}, function(res){
        if (!res.ok) return;
        META = res;
        $('#fApplyDate').val(res.today);
        $('#trYear').val(new Date().getFullYear());
        var dh = '<option value="">（未設定）</option>';
        (res.depts||[]).forEach(function(d){ dh += '<option value="'+d.id+'">'+esc(d.name)+'</option>'; });
        $('#fDept').html(dh);
        if ((res.people||[]).length) {
            var ph = '';
            res.people.forEach(function(p){
                ph += '<option value="'+p.id+'">'+esc((p.dept_name||'')+' '+(p.position_name||'')+' '+(p.user_cname||p.name||''))+'</option>';
            });
            $('#fUser').html(ph);
            $('#forUserBox').show();
        }
        if (res.perms && res.perms.canAdmin) fillSettingUI(res);
        if (typeof then === 'function') then();
    });
}
function loadList(){
    var q = {action:'list', scope:$('#scopeSel').val(), status:$('#statusSel').val(),
             date_from:$('#fDateFrom').val(), date_to:$('#fDateTo').val(), kw:$('#fKw').val()};
    $('#listBody').html('<tr><td colspan="12" style="padding:20px;color:#8a6d45;">載入中…</td></tr>');
    $.getJSON(API, q, function(res){
        if (!res.ok) return;
        ROWS = res.rows || [];
        PAGE = 1;
        renderList();
    });
}
function renderList(){
    PSIZE = parseInt($('#pgSize').val(), 10) || 10;
    var total = ROWS.length, pages = Math.max(1, Math.ceil(total / PSIZE));
    if (PAGE > pages) PAGE = pages;
    var start = (PAGE - 1) * PSIZE, part = ROWS.slice(start, start + PSIZE), h = '';
    part.forEach(function(r){
        var ops = '<span class="bt-op" onclick="openTrip('+r.trip_id+')"><i class="fa fa-folder-open-o"></i> 開啟</span>'
                + '<span class="bt-op" onclick="printTrip('+r.trip_id+')"><i class="fa fa-print"></i> 列印</span>';
        if (r.can_decide) ops += '<span class="bt-op" onclick="openDecide('+r.trip_id+')"><i class="fa fa-gavel"></i> 核准/退回</span>';
        if (r.can_edit)   ops += '<span class="bt-op" onclick="delTrip('+r.trip_id+')"><i class="fa fa-trash"></i> 刪除</span>';
        h += '<tr><td><input type="checkbox" class="chkRow" value="'+r.trip_id+'"></td>'
           + '<td>'+esc(r.trip_no||('#'+r.trip_id))+'</td>'
           + '<td>'+esc(dispDate(r.apply_date))+'</td>'
           + '<td>'+esc(r.user_name||'')+'</td><td>'+esc(r.dept_name||'')+'</td><td>'+esc(r.position_name||'')+'</td>'
           + '<td>'+esc(r.period||'')+'</td><td class="l">'+esc(r.location||'')+'</td>'
           + '<td class="l" title="'+esc(r.reason||'')+'">'+esc((r.reason||'').substr(0,20))+((r.reason||'').length>20?'…':'')+'</td>'
           + '<td><span class="st st-'+esc(r.status)+'">'+esc(STATUS_LABEL[r.status]||r.status)+'</span></td>'
           + '<td>'+esc(r.approver_name||'')+(+r.is_delegated?'<div style="font-size:11px;color:#8a6d45;">代理簽核</div>':'')+'</td>'
           + '<td>'+ops+'</td></tr>';
    });
    $('#listBody').html(h || '<tr><td colspan="12" style="padding:20px;color:#8a6d45;">查無資料</td></tr>');
    $('#pgInfo').text('共 '+total+' 筆');
    var bh = '';
    for (var i=1; i<=pages; i++) bh += '<button class="pgb'+(i===PAGE?' on':'')+'" data-p="'+i+'">'+i+'</button>';
    $('#pgBtns').html(bh);
    $('#chkAll').prop('checked', false);
}
$(document).on('click', '.pgb', function(){ PAGE = parseInt($(this).data('p'), 10); renderList(); });
$('#pgSize').on('change', function(){ PAGE = 1; renderList(); });
$('#chkAll').on('change', function(){ $('.chkRow').prop('checked', $(this).is(':checked')); });
$('#btnSearch').on('click', loadList);
$('#scopeSel,#statusSel').on('change', loadList);

/* ================= 編輯 ================= */
function newTrip(){
    CUR_ID = 0; CUR_TRIP = null;
    $('#editTitle').text('新增公出單');
    $('#fApplyDate').val(META ? META.today : '');
    $('#fFrom').val(META ? META.today : ''); $('#fTo').val(META ? META.today : '');
    $('#fTimeFrom').val(''); $('#fTimeTo').val('');
    $('#fLocation').val(''); $('#fReason').val('');
    if (META && META.me) {
        $('#fDept').val(META.me.dept_id || '');
        $('#fPosition').val(META.me.position_name || '');
        if ($('#fUser').length) $('#fUser').val(META.me.id);
    }
    $('#dayBody').html(''); btDayAdd();
    $('#dayBox').hide();
    clearPull();
    $('#pullBox').show();
    $('#editHint').text('儲存草稿後仍可修改；按「儲存並送出」才會進入簽核。');
    $('#btnSave').show(); $('#btnSubmit').show(); $('#btnPrintOne').hide();
    openMask('editMask');
}
function openTrip(id){
    // 點開即刷新（ai-rules/08 第六節）：先抓後端最新狀態再決定按鈕
    $.getJSON(API, {action:'get', trip_id:id}, function(res){
        if (!res.ok) return;
        var t = res.trip; CUR_ID = id; CUR_TRIP = t;
        $('#editTitle').text((t.can_edit ? '編輯' : '檢視') + '公出單　' + (t.trip_no||('#'+id))
                             + '（' + (STATUS_LABEL[t.status]||t.status) + '）');
        $('#fApplyDate').val(t.apply_date);
        if ($('#fUser').length) $('#fUser').val(t.user_id);
        $('#fDept').val(t.dept_id || '');
        $('#fPosition').val(t.position_name || '');
        $('#fFrom').val(t.date_from); $('#fTo').val(t.date_to);
        $('#fTimeFrom').val(t.time_from || ''); $('#fTimeTo').val(t.time_to || '');
        $('#fLocation').val(t.location || ''); $('#fReason').val(t.reason || '');
        $('#dayBody').html('');
        (t.days||[]).forEach(function(d){ btDayAdd(d); });
        if (!(t.days||[]).length) btDayAdd();
        syncDayBox();
        clearPull();
        renderClassRef(t.training);        // 這張單綁著外訓場次時，顯示原始上課日期時間
        var hint = [];
        if (t.source === 'training') hint.push('本單資料由教育訓練外訓場次帶入。');
        if (t.approver_name) hint.push('核准人：' + esc(t.approver_name) + (+t.is_delegated ? '（代理簽核）' : ''));
        if (t.status === 'approved') hint.push('核准日期：' + dispDate(t.approved_date) + (+t.is_auto ? '（' + esc(t.auto_note||'自動核准') + '）' : ''));
        if (t.status === 'rejected') hint.push('<span style="color:#DD5138;">退回原因：' + esc(t.decide_note||'') + '</span>');
        $('#editHint').html(hint.join('<br>'));
        var ro = !t.can_edit;
        $('#editMask input, #editMask select, #editMask textarea').prop('disabled', ro);
        $('#pullBox').toggle(!ro);
        $('#btnSave').toggle(!ro); $('#btnSubmit').toggle(!ro); $('#btnPrintOne').show();
        openMask('editMask');
    });
}
function collectDays(){
    var out = [];
    $('#dayBody tr').each(function(){
        var d = $(this).find('.dDate').val(), s = $(this).find('.dStart').val(), e = $(this).find('.dEnd').val();
        if (d) out.push({day_date:d, start_time:s, end_time:e});
    });
    return out;
}
function btDayAdd(d){
    d = d || {};
    var n = $('#dayBody tr').length + 1;
    $('#dayBody').append('<tr><td>'+n+'</td>'
        + '<td><input type="date" class="dDate" max="9999-12-31" value="'+esc(d.day_date||'')+'"></td>'
        + '<td><input type="text" class="dStart" maxlength="5" value="'+esc(d.start_time||'')+'"></td>'
        + '<td><input type="text" class="dEnd" maxlength="5" value="'+esc(d.end_time||'')+'"></td>'
        + '<td><span class="bt-op" onclick="$(this).closest(\'tr\').remove();renumDays();">✕</span></td></tr>');
    renumDays();
}
function btDayDelLast(){ if ($('#dayBody tr').length > 1) { $('#dayBody tr').last().remove(); renumDays(); } }
function renumDays(){ $('#dayBody tr').each(function(i){ $(this).find('td').first().text(i+1); }); }
function syncDayBox(){
    var multi = $('#fFrom').val() && $('#fTo').val() && $('#fFrom').val() !== $('#fTo').val();
    $('#dayBox').toggle(!!multi);
}
$('#fFrom,#fTo').on('change', function(){
    if ($('#fTo').val() && $('#fFrom').val() && $('#fTo').val() < $('#fFrom').val()) $('#fTo').val($('#fFrom').val());
    syncDayBox();
});
/* 時間欄即時檢查（錯誤當場顯示原因，不等送出；後端會再驗一次） */
function normTime(v){
    var s = String(v||'').trim();
    if (s === '') return '';
    var m;
    if ((m = s.match(/^(\d{1,2}):(\d{1,2})$/)))      { var h=+m[1], i=+m[2]; }
    else if ((m = s.match(/^(\d{3,4})$/)))           { var h=+m[1].slice(0,-2), i=+m[1].slice(-2); }
    else if ((m = s.match(/^(\d{1,2})$/)))           { var h=+m[1], i=0; }
    else return null;
    if (h<0||h>23||i<0||i>59) return null;
    return (h<10?'0':'')+h+':'+(i<10?'0':'')+i;
}
$('#fTimeFrom,#fTimeTo').on('blur', function(){
    var $e = $(this).attr('id')==='fTimeFrom' ? $('#errTimeFrom') : $('#errTimeTo');
    var v = normTime($(this).val());
    if (v === null) { $e.text('時間格式不正確（時須 0-23、分須 0-59，可輸入 0900／900／9）').show(); $(this).css('border-color','#DD5138'); return; }
    $(this).val(v).css('border-color','#D8BE93'); $e.hide();
    if ($('#fFrom').val() === $('#fTo').val()) {
        var a = normTime($('#fTimeFrom').val()), b = normTime($('#fTimeTo').val());
        if (a && b && b <= a) { $('#errTimeTo').text('同一天的結束時間不可早於或等於開始時間').show(); }
        else $('#errTimeTo').hide();
    }
});
function validEdit(){
    var ok = true;
    $('.bt-err').hide();
    if (!$('#fLocation').val().trim()) { $('#errLocation').text('請填公出地點').show(); ok = false; }
    if (!$('#fReason').val().trim())   { $('#errReason').text('請填事由').show(); ok = false; }
    if (normTime($('#fTimeFrom').val()) === null) { $('#errTimeFrom').text('時間格式不正確').show(); ok = false; }
    if (normTime($('#fTimeTo').val()) === null)   { $('#errTimeTo').text('時間格式不正確').show(); ok = false; }
    return ok;
}
function saveTrip(submitAfter){
    if (!validEdit()) return;
    var d = {action:'save', trip_id:CUR_ID, apply_date:$('#fApplyDate').val(),
             dept_id:$('#fDept').val(), position_name:$('#fPosition').val(),
             date_from:$('#fFrom').val(), date_to:$('#fTo').val(),
             time_from:$('#fTimeFrom').val(), time_to:$('#fTimeTo').val(),
             location:$('#fLocation').val(), reason:$('#fReason').val(),
             days:JSON.stringify($('#dayBox').is(':visible') ? collectDays() : [])};
    if ($('#fUser').length && $('#forUserBox').is(':visible')) d.user_id = $('#fUser').val();
    if (PULL_REF) { d.ref_type = 'training_session'; d.ref_id = PULL_REF; }
    $.post(API, d, function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        CUR_ID = res.trip_id;
        if (!submitAfter) { alert('已儲存草稿'); closeMask('editMask'); loadList(); return; }
        $.post(API, {action:'submit', trip_id:res.trip_id}, function(r2){
            if (!r2.ok) { alert(r2.error||'送出失敗'); return; }
            alert(r2.status === 'approved'
                ? ('已送出並自動核准（' + (r2.note||'') + '）')
                : (r2.is_self ? '已送出。你是全站最高決策者，這張單由你自己核准——請到「待我核准」按核准。'
                               : ('已送出，待「' + (r2.approver||'') + '」核准。')));
            closeMask('editMask'); loadList();
        }, 'json');
    }, 'json');
}
$('#btnSave').on('click', function(){ saveTrip(false); });
$('#btnSubmit').on('click', function(){ saveTrip(true); });
$('#btnAdd').on('click', newTrip);
function delTrip(id){
    if (!confirm('確定刪除這張公出單？')) return;
    $.post(API, {action:'delete', trip_id:id}, function(res){
        if (!res.ok) { alert(res.error||'刪除失敗'); return; }
        loadList();
    }, 'json');
}

/* ================= 從外訓帶入（全體員工：自己的外訓；管理員：可代任一員工撈，方便補資料） =================
   來源＝教育訓練已「確認實行」（已排定／已完成）的外訓場次；
   單據日期一律自動帶該場外訓的**最早一天**（使用者明確要求，補舊資料才不會全部印成今天）。 */
/* 上課時間對照區：帶入／編輯外訓來源的公出單時顯示原始上課日期時間，
   方便手動調整公出時間時對照（公出時間已自動前後各留通勤時間） */
function renderClassRef(info){
    if (!info || !(info.class_days||[]).length) { $('#classRefBox').hide().html(''); return; }
    var cm = +info.commute_min || 0;
    var rows = '';
    (info.class_days||[]).forEach(function(d, i){
        rows += '<tr><td class="d">第 ' + (i+1) + ' 天</td><td class="d">' + esc(dispDate(d.day_date)) + '</td>'
             +  '<td class="c">' + esc((d.start_time||'') + ((d.end_time||'') ? '～' + d.end_time : '')) + '</td></tr>';
    });
    $('#classRefBox').html('<div><i class="fa fa-graduation-cap"></i> 外訓上課時間（對照用）：<b>'
        + esc(info.course_name||'') + '</b>' + (info.org_unit ? '（' + esc(info.org_unit) + '）' : '') + '</div>'
        + '<table><tbody>' + rows + '</tbody></table>'
        + (cm ? '<div style="margin-top:4px;">公出時間已自動<b>提前 ' + cm + ' 分鐘出發、延後 ' + cm
              + ' 分鐘結束</b>作為通勤時間，可自行修改。</div>'
              : '<div style="margin-top:4px;">目前設定不加通勤時間，公出時間＝上課時間。</div>')).show();
}
function clearPull(){
    PULL_REF = 0;
    renderClassRef(null);
    $('#pullBox').removeClass('bt-pulled');
    $('#pullHint').html('已「確認實行」的外訓課程，可一鍵帶入日期／時間／地點／事由；<b>單據日期會自動帶該場外訓的最早一天</b>。');
}
/* 帶入對象：管理員代開時＝目前選定的公出人，其餘一律本人（後端也會再擋一次） */
function pullTargetId(){
    return ($('#fUser').length && $('#forUserBox').is(':visible')) ? ($('#fUser').val() || '') : '';
}
function pullTargetName(){
    var id = pullTargetId();
    if (!id) return (META && META.me) ? META.me.user_name : '本人';
    return $('#fUser option:selected').text() || '該員';
}
$('#btnPullTraining').on('click', function(){
    var y = String($('#fApplyDate').val() || (META ? META.today : '')).substr(0, 4);
    var now = new Date().getFullYear(), h = '';
    for (var i = now + 1; i >= now - 6; i--) h += '<option value="' + i + '">' + i + '</option>';
    h += '<option value="0">全部年度</option>';
    $('#myTrYear').html(h).val($('#myTrYear option[value="' + y + '"]').length ? y : String(now));
    $('#myTrKw').val('');
    $('#myTrWho').html('帶入對象：<b>' + esc(pullTargetName()) + '</b>');
    $('#myTrTitle').text('從外訓帶入　—　' + pullTargetName());
    openMask('myTrMask');
    loadMyTraining();
});
$('#btnMyTrLoad').on('click', loadMyTraining);
$('#myTrYear').on('change', loadMyTraining);
$('#myTrKw').on('keydown', function(e){ if (e.which === 13) { e.preventDefault(); renderMyTraining(); } });
function loadMyTraining(){
    $('#myTrBody').html('<tr><td colspan="6" style="padding:16px;color:#8a6d45;">載入中…</td></tr>');
    $.getJSON(API, {action:'my_training', year:$('#myTrYear').val(), user_id:pullTargetId()}, function(res){
        if (!res.ok) return;
        MY_TR = res.rows || [];
        renderMyTraining();
    });
}
function renderMyTraining(){
    var kw = $.trim($('#myTrKw').val()).toLowerCase().split(/\s+/).filter(function(x){ return x; });
    var rows = MY_TR.filter(function(r){
        if (!kw.length) return true;
        var hay = ((r.course_name || '') + ' ' + (r.org_unit || '') + ' ' + (r.location || '')).toLowerCase();
        return kw.every(function(k){ return hay.indexOf(k) >= 0; });
    });
    var h = '';
    rows.forEach(function(r){
        var d1 = r.date_from, d2 = r.date_to;
        var dtxt = d1 ? (dispDate(d1) + (d2 && d2 !== d1 ? ' ～ ' + dispDate(d2) : '')) : '（未排日期）';
        if (+r.day_cnt > 1) dtxt += '<div style="font-size:11px;color:#8a6d45;">共 ' + r.day_cnt + ' 天</div>';
        var st = r.trip_status
               ? '<span style="color:#DD5138;">已開（' + esc(STATUS_LABEL[r.trip_status] || r.trip_status) + '）</span>'
               : '—';
        h += '<tr><td>' + dtxt + '</td><td>' + esc(r.course_name || '') + '</td><td>' + esc(r.org_unit || '')
           + '</td><td>' + esc(r.location || '') + '</td><td>' + st + '</td>'
           + '<td><span class="bt-op" onclick="pullMyTraining(' + r.session_id + ')"><i class="fa fa-download"></i> 帶入</span></td></tr>';
    });
    $('#myTrBody').html(h || '<tr><td colspan="6" style="padding:16px;color:#8a6d45;">查無已確認實行的外訓課程'
        + '（外訓需在教育訓練頁按「確認實行」並列入參加人員，才會出現在這裡）</td></tr>');
}
function pullMyTraining(sid){
    $.getJSON(API, {action:'training_fill', session_id:sid, user_id:pullTargetId()}, function(res){
        if (!res.ok) return;
        var f = res.fill || {};
        if (f.exist_trip && !confirm('這場外訓已經有一張公出單（' + (f.exist_trip.trip_no || '')
            + '，' + (STATUS_LABEL[f.exist_trip.status] || f.exist_trip.status) + '）。\n仍要另外帶入一張新的嗎？')) return;
        $('#fApplyDate').val(f.apply_date || '');          /* 單據日期＝外訓最早一天 */
        $('#fFrom').val(f.date_from || ''); $('#fTo').val(f.date_to || '');
        $('#fTimeFrom').val(f.time_from || ''); $('#fTimeTo').val(f.time_to || '');
        $('#fLocation').val(f.location || ''); $('#fReason').val(f.reason || '');
        if (f.attendee && f.attendee.position_name) $('#fPosition').val(f.attendee.position_name);
        /* 多天才需要逐日時段明細；單日用主檔起訖時間就夠 */
        $('#dayBody').html('');
        var days = f.days || [];
        if (days.length > 1) days.forEach(function(d){ btDayAdd(d); });
        if (!$('#dayBody tr').length) btDayAdd();
        syncDayBox();
        PULL_REF = +sid;
        renderClassRef(f);
        $('#pullBox').addClass('bt-pulled');
        $('#pullHint').html('已帶入外訓：<b>' + esc(f.course_name || '') + '</b>'
            + (f.org_unit ? '（' + esc(f.org_unit) + '）' : '')
            + '　單據日期已自動設為最早上課日 <b>' + esc(dispDate(f.apply_date)) + '</b>'
            + ((+f.commute_min) ? '，公出時間已前後各加 <b>' + (+f.commute_min) + ' 分鐘</b>通勤時間' : '')
            + '；內容仍可修改。');
        closeMask('myTrMask');
    });
}

/* ================= 批次刪除（僅系統管理者；勾選清單後整批軟刪除） ================= */
$('#btnDelSel').on('click', function(){
    var rows = $('.chkRow:checked').map(function(){
        var id = +this.value;
        return ROWS.filter(function(r){ return +r.trip_id === id; })[0] || {trip_id:id};
    }).get();
    if (!rows.length) { alert('請先勾選要刪除的公出單'); return; }
    /* 已送出／已核准的單也刪得掉（系統管理者專用），但要先講清楚勾到哪些，避免誤刪 */
    var cnt = {};
    rows.forEach(function(r){ var k = STATUS_LABEL[r.status] || r.status || '未知'; cnt[k] = (cnt[k]||0) + 1; });
    var brief = Object.keys(cnt).map(function(k){ return k + ' ' + cnt[k] + ' 張'; }).join('、');
    var names = rows.slice(0, 8).map(function(r){ return (r.trip_no||('#'+r.trip_id)) + ' ' + (r.user_name||''); });
    if (rows.length > 8) names.push('…等共 ' + rows.length + ' 張');
    if (!confirm('確定刪除勾選的 ' + rows.length + ' 張公出單？\n（' + brief + '）\n\n' + names.join('\n')
        + '\n\n刪除後不會出現在清單與統計中，需要救回請洽系統管理者。')) return;
    var $b = $('#btnDelSel').prop('disabled', true);
    $.post(API, {action:'delete_batch', ids:JSON.stringify(rows.map(function(r){ return r.trip_id; }))}, function(res){
        $b.prop('disabled', false);
        if (!res.ok) { alert(res.error||'批次刪除失敗'); return; }
        alert('已刪除 ' + res.deleted + ' 張'
            + (res.skipped ? ('，另有 ' + res.skipped + ' 張已被刪除或不存在（略過）') : '') + '。');
        $('#chkAll').prop('checked', false);
        loadList();
    }, 'json');
});

/* ================= 核准／退回 ================= */
function openDecide(id){
    $.getJSON(API, {action:'get', trip_id:id}, function(res){       // 點開即刷新
        if (!res.ok) return;
        var t = res.trip;
        if (!t.can_decide) { alert('這張單目前不是待您核准的狀態（可能已被處理），已重新整理清單。'); loadList(); return; }
        DECIDE_ID = id;
        $('#decideInfo').html('<b>'+esc(t.user_name||'')+'</b>（'+esc(t.dept_name||'')+' '+esc(t.position_name||'')+'）<br>'
            + '公出時間：'+esc(t.period||'')+'<br>公出地點：'+esc(t.location||'')+'<br>事由：'+esc(t.reason||''));
        $('#decideSel').val('approved');
        $('#decideDate').val((META && META.today) || '');
        $('#decideNote').val(''); $('#errNote').hide();
        openMask('decideMask');
    });
}
$('#btnDecideOk').on('click', function(){
    var dec = $('#decideSel').val(), note = $('#decideNote').val().trim();
    if (dec === 'rejected' && !note) { $('#errNote').text('退回一定要填原因，申請人才知道要改什麼').show(); return; }
    $.post(API, {action:'decide', trip_id:DECIDE_ID, decision:dec, note:note, decide_date:$('#decideDate').val()}, function(res){
        if (!res.ok) { alert(res.error||'決行失敗'); return; }
        alert(dec === 'approved' ? '已核准' : '已退回');
        closeMask('decideMask'); loadList();
    }, 'json');
});

/* ================= 列印（版面比照紙本 2-MM-01-06） ================= */
function printTrip(id){
    if (!id) { alert('請先儲存後再列印'); return; }
    $.getJSON(API, {action:'print_meta', trip_id:id}, function(res){
        if (!res.ok) { alert(res.error||'讀取失敗'); return; }
        var w = window.open('', '_blank');
        if (!w) { alert('請允許彈出視窗'); return; }
        w.document.write(tripPrintHtml(res));
        w.document.close();
    });
}
function tripPrintHtml(res){
    var t = res.trip, sg = res.signers || {}, ready = (t.status === 'approved');
    var dt = ready ? dispDate(t.approved_date || t.apply_date) : '';
    var stamp = function(nm, deputy){ return (ready && nm) ? stampHtml(nm, dt, deputy) : ''; };
    var timeText = (t.time_from||'') + ((t.time_to||'') ? ' 至 ' + t.time_to : '');
    // 逐日時段：一列排兩天（天數多也不撐版），未填滿補空格供手寫
    var ds = t.days || [], dayTbl = '';
    if (ds.length) {
        var cells = [];
        for (var i = 0; i < ds.length; i++) {                 /* 不補空白格：欄位少、補了反而像沒填完 */
            var d = ds[i] || {}, tm = d.start_time ? (d.start_time + (d.end_time ? '～' + d.end_time : '')) : '';
            cells.push('<td class="dn">' + (d.day_date ? ('第 ' + (i+1) + ' 天') : '') + '</td>'
                     + '<td class="dd">' + (d.day_date ? esc(dispDate(d.day_date)) : '') + '</td>'
                     + '<td class="dt">' + esc(tm) + '</td>');
        }
        if (cells.length % 2) cells.push('<td class="dn"></td><td class="dd"></td><td class="dt"></td>');
        var rows = '';
        for (var k = 0; k < cells.length; k += 2) rows += '<tr>' + cells[k] + cells[k+1] + '</tr>';
        dayTbl = '<table class="sub"><tbody>' + rows + '</tbody></table>';
    }
    // 有逐日明細時，整段期間套同一組起訖時間並不成立，改指向下方每日清單，避免印出不實的時段
    var period = (t.date_from === t.date_to)
        ? dispDate(t.date_from) + '　自 ' + timeText
        : dispDate(t.date_from) + ' ～ ' + dispDate(t.date_to)
          + (dayTbl ? '（各日時段如下）' : '　自 ' + timeText);
    var ymd = String(t.apply_date||'').split('-');

    var css = '@page{size:A4 portrait;margin:0;}html,body{margin:0;padding:0;}'
        + 'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;'
        + 'padding:14mm 14mm 12mm;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        + '.pg{display:flex;flex-direction:column;}'
        /* 表頭 */
        + '.hd{text-align:center;}'
        + '.hd .co{font-size:23px;font-weight:bold;letter-spacing:3px;}'
        + '.hd .tt{display:inline-block;font-size:22px;font-weight:bold;letter-spacing:14px;'
        +   'padding:0 0 3px 14px;margin-top:8px;border-bottom:2px solid #000;}'
        + '.ymd{text-align:right;font-size:14px;margin:10px 2px 6px;letter-spacing:1px;}'
        /* 表身：整框，右側直書存查欄與表身同高；事由欄吃掉剩餘高度，簽章區自然壓在頁尾 */
        + '.frm{display:flex;border:1.6px solid #000;}'
        + '.bd{flex:1;display:flex;flex-direction:column;min-width:0;}'
        + '.r{display:flex;border-bottom:1px solid #000;min-height:13mm;}'
        + '.r>.lb{width:27mm;flex:none;background:#F5EEE3;border-right:1px solid #000;'
        +   'display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:bold;letter-spacing:4px;}'
        + '.r>.vl{flex:1;min-width:0;display:flex;align-items:center;padding:6px 10px;font-size:15px;'
        +   'line-height:1.7;word-break:break-all;}'
        + '.r>.lb2{width:27mm;flex:none;background:#F5EEE3;border-left:1px solid #000;border-right:1px solid #000;'
        +   'display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:bold;letter-spacing:4px;}'
        + '.r>.vl2{width:62mm;flex:none;display:flex;align-items:center;padding:6px 10px;font-size:15px;line-height:1.7;}'
        /* 事由：留足書寫高度並吃掉整頁剩餘空間 */
        + '.rs{display:flex;border-bottom:1px solid #000;min-height:34mm;}'
        + '.rs>.vl{align-items:flex-start;white-space:pre-wrap;padding:8px 10px;}'
        + '.rs>.lb{align-items:flex-start;padding-top:8px;}'
        + 'table.sub{width:100%;border-collapse:collapse;table-layout:fixed;font-size:13px;margin-top:8px;}'
        + 'table.sub td{border:1px solid #999;padding:4px 5px;text-align:center;height:9mm;white-space:nowrap;}'
        + 'table.sub td.dn{width:10%;color:#444;font-size:12px;}table.sub td.dd{width:19%;}table.sub td.dt{width:21%;}'
        /* 簽章三格（比照紙本：會計／課長／組長） */
        + '.sg{display:flex;height:28mm;}'
        + '.sg .cell{flex:1;display:flex;flex-direction:column;border-right:1px solid #000;}'
        + '.sg .cell:last-child{border-right:none;}'
        + '.sg .lb{background:#F5EEE3;border-bottom:1px solid #000;text-align:center;font-size:14px;'
        +   'font-weight:bold;letter-spacing:2px;padding:3px 0;}'
        + '.sg .bx{flex:1;display:flex;align-items:center;justify-content:center;}'
        + '.sg .stamp-wrap svg,.sg svg.car-stamp{width:72px;height:72px;}'
        /* 右側直書「外出時交管理課存查」 */
        + '.keep{width:17mm;flex:none;border-left:1.6px solid #000;display:flex;align-items:center;justify-content:center;}'
        + '.keep span{writing-mode:vertical-rl;letter-spacing:8px;font-size:16px;font-weight:bold;}'
        + '.note{font-size:12.5px;margin-top:8px;color:#333;line-height:1.7;}'
        + '.docno{position:fixed;right:14mm;bottom:8mm;font-size:10pt;color:#333;}';

    var body = '<div class="pg">'
        + '<div class="hd"><div class="co">' + esc(res.company) + '</div>'
        +   '<div class="tt">' + esc(res.doc_name || '公出單') + '</div></div>'
        + '<div class="ymd">' + esc(ymd[0]||'') + ' 年 ' + esc(ymd[1]||'') + ' 月 ' + esc(ymd[2]||'') + ' 日</div>'
        + '<div class="frm"><div class="bd">'
        +   '<div class="r"><div class="lb">姓　名</div><div class="vl">' + esc(t.user_name||'') + '</div>'
        +     '<div class="lb2">單　位</div><div class="vl2">' + esc(t.dept_name||'') + '</div></div>'
        +   '<div class="r"><div class="lb">職　稱</div><div class="vl">' + esc(t.position_name||'') + '</div>'
        +     '<div class="lb2">公出時間</div><div class="vl2">' + esc(period) + '</div></div>'
        +   '<div class="r"><div class="lb">公出地點</div><div class="vl">' + esc(t.location||'') + '</div></div>'
        +   '<div class="r rs"><div class="lb">事　由</div><div class="vl"><div style="width:100%;">'
        +     esc(t.reason||'') + dayTbl + '</div></div></div>'
        +   '<div class="sg">'
        /* 會計格固定留白：只有需要請款時才由會計手蓋 */
        +     '<div class="cell"><div class="lb">會　計</div><div class="bx"></div></div>'
        /* 單位主管格：蓋實際核准這張單的人（主管本人公出時＝總經理）；代理簽核的章帶「代」字 */
        +     '<div class="cell"><div class="lb">單位主管</div><div class="bx">'
        +       stamp(sg.group, +t.is_delegated) + '</div></div>'
        +   '</div>'
        + '</div><div class="keep"><span>外出時交管理課存查</span></div></div>'
        + '<div class="note">＊業務、會計、品管、採購物料單位因公外出時，請單位主管核准即可；主管公出時單位主管欄位由總經理核准</div>'
        + '</div>'
        + (res.doc_no ? '<div class="docno">' + esc(res.doc_no) + '</div>' : '');

    return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + esc(res.doc_name||'公出單') + '</title>'
         + '<style>' + css + '</style></head><body>' + body
         + '<scr'+'ipt>window.onload=function(){setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>';
}
/* 批次列印：依序各自開視窗排隊（ai-rules/16 第三之五節），上一份關閉才開下一份 */
$('#btnPrintSel').on('click', function(){
    var ids = $('.chkRow:checked').map(function(){ return +this.value; }).get();
    if (!ids.length) { alert('請先勾選要列印的公出單'); return; }
    var i = 0;
    (function next(){
        if (i >= ids.length) return;
        $.getJSON(API, {action:'print_meta', trip_id:ids[i++]}, function(res){
            if (!res.ok) { next(); return; }
            var w = window.open('', '_blank');
            if (!w) { alert('請允許彈出視窗'); return; }
            w.document.write(tripPrintHtml(res)); w.document.close();
            var timer = setInterval(function(){ if (w.closed) { clearInterval(timer); next(); } }, 600);
        });
    })();
});

/* ================= 設定（管理員） ================= */
function fillSettingUI(res){
    var s = res.settings || {};
    $('#setNeedAppr').val(String(s.bt_need_approval));
    $('#setAutoTr').val(String(s.bt_auto_from_training));
    $('#setCommute').val(s.bt_commute_min == null ? 30 : s.bt_commute_min);
    var th = '<option value="0">（預設圓形圖章）</option>';
    (res.stamp_tpls||[]).forEach(function(t){
        th += '<option value="'+t.id+'">'+esc(t.tpl_name + (t.type_name ? '（'+t.type_name+'）' : ''))+'</option>';
    });
    $('#setStampTpl').html(th).val(String(s.bt_stamp_tpl_id || 0));
    var sh = '';
    $.each(res.sign_sources || {}, function(k, v){ sh += '<option value="'+esc(k)+'">'+esc(v)+'</option>'; });
    $('#setSignGroup').html(sh).val(s.bt_sign_group || '');
    $('#setAsDoc').val(res.as_doc ? EGAsDoc.label(res.as_doc) : '');
    if (!res.perms.isAdmin) { $('#setNeedAppr').prop('disabled', true).addClass('ro-auto'); $('#needApprLock').text('（僅系統管理者可改）'); }
}
$('#btnSetting').on('click', function(){ openMask('setMask'); });
$('#btnPickAsDoc').on('click', function(){
    EGAsDoc.open({
        docs: (META && META.as_docs) || [],
        current: (META && META.as_doc) ? META.as_doc.id : 0,
        title: '公出單 — AS 文件編號綁定',
        onSave: function(id){
            $.post(API, {action:'save_asdoc', doc_id:id||0}, function(res){
                if (!res.ok) { alert(res.error||'儲存失敗'); return; }
                $('#setAsDoc').val(res.as_doc ? EGAsDoc.label(res.as_doc) : '');
                loadMeta();
            }, 'json');
        }
    });
});
$('#btnClearAsDoc').on('click', function(){
    $.post(API, {action:'save_asdoc', doc_id:0}, function(res){
        if (!res.ok) { alert(res.error||'清除失敗'); return; }
        $('#setAsDoc').val(''); loadMeta();
    }, 'json');
});
$('#btnSaveSet').on('click', function(){
    var d = {action:'save_settings', bt_auto_from_training:$('#setAutoTr').val(), bt_stamp_tpl_id:$('#setStampTpl').val(),
             bt_commute_min:$('#setCommute').val(),
             bt_sign_group:$('#setSignGroup').val()};
    if (!$('#setNeedAppr').prop('disabled')) d.bt_need_approval = $('#setNeedAppr').val();
    $.post(API, d, function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        alert('設定已儲存'); closeMask('setMask'); loadMeta();
    }, 'json');
});

/* ================= 從外訓帶入（管理員） ================= */
$('#btnFromTraining').on('click', function(){ openMask('trMask'); });
$('#btnTrLoad').on('click', function(){
    $.getJSON(API, {action:'training_sessions', year:$('#trYear').val()}, function(res){
        if (!res.ok) return;
        var h = '';
        (res.rows||[]).forEach(function(r){
            h += '<tr><td>'+esc(dispDate(r.done_date))+'</td><td class="l">'+esc(r.course_name||'')+'</td>'
               + '<td>'+esc(r.org_unit||'')+'</td><td>'+esc(r.att_cnt)+'</td><td>'+esc(r.trip_cnt)+'</td>'
               + '<td><span class="bt-op" onclick="pullTraining('+r.session_id+')"><i class="fa fa-download"></i> 帶入</span></td></tr>';
        });
        $('#trBody').html(h || '<tr><td colspan="6" style="padding:16px;color:#8a6d45;">該年度沒有已排定/已完成的外訓場次</td></tr>');
    });
});
function pullTraining(sid){
    $.post(API, {action:'from_training', session_id:sid}, function(res){
        if (!res.ok) { alert(res.error||'帶入失敗'); return; }
        alert('新增 '+res.created+' 張、更新 '+res.updated+' 張草稿'+(res.skipped?('，'+res.skipped+' 張已送出不覆蓋'):''));
        $('#btnTrLoad').click(); loadList();
    }, 'json');
}

/* ================= 其他 ================= */
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
$(window).on('scroll', function(){ $('#btnTop').toggle($(window).scrollTop() > 300); });
$('#btnTop').on('click', function(){ $('html,body').animate({scrollTop:0}, 200); });
$(function(){
    loadMeta(function(){
        var q = new URLSearchParams(location.search);
        if (q.get('trip_approve')) { $('#scopeSel').val('pending'); loadList(); openDecide(+q.get('trip_approve')); return; }
        if (q.get('trip_id')) { loadList(); openTrip(+q.get('trip_id')); return; }
        loadList();
    });
});
</script>
</body>
</html>
