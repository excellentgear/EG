<?php
/**
 * 人資職務表單 — 職務說明書／專業技能鑑定考核表／員工職能鑑定表（三分頁同一頁）—— 2026-08-13 新增
 * 範本/白名單/部門設定請至「人資職務表單設定」hr_position_forms_template.php（僅管理員）。
 * 資料一律走 src/store/HrForm_API.php；權限 src/common/hr_form_lib.php hrf_perms()
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/hr_position_forms.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/hr_form_lib.php';

$db = (new DBConnection())->getPDO();
$hrfUser = hrf_current_user($db);
$perms = hrf_perms($db, $hrfUser);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>人資職務表單</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .hf-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .hf-toolbar input[type=text], .hf-toolbar select, .hf-toolbar button { height:30px; font-size:13px; padding:0 12px; border:1px solid #D8BE93; border-radius:4px;
            background:#fff; color:#5b3a1e; }
        .hf-toolbar button { cursor:pointer; }
        .hf-toolbar button:disabled { cursor:not-allowed; opacity:.5; }
        .hf-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .hf-toolbar .btn-danger { background:#DD5138; color:#fff; border-color:#c23f28; }
        .hf-pager { display:flex; align-items:center; gap:4px; margin-left:auto; font-size:12.5px; color:#5b3a1e; }
        .hf-pager select, .hf-pager button { height:26px; font-size:12px; padding:0 6px; }
        .page-help-btn { height:30px; font-size:13px; padding:0 14px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .help-doc h4 { font-size:14px; color:#8A5A2B; margin:10px 0 4px; }
        .hf-asdoc { font-size:12px; color:#8a6d45; }
        table.hf-tbl { width:100%; border-collapse:collapse; font-size:13px; background:#fff; }
        table.hf-tbl th, table.hf-tbl td { border:1px solid #EADFC8; padding:6px 8px; }
        table.hf-tbl thead th { background:#F7E0BD; color:#5b3a1e; }
        .hf-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; }
        .st-badge { border-radius:10px; padding:2px 9px; font-size:11.5px; color:#fff; white-space:nowrap; }
        .st-draft,.st-active{background:#b0a390;} .st-confirming,.st-approving{background:#F0A24B;} .st-signed{background:#3f9142;} .st-rejected{background:#DD5138;}
        .na-tag{color:#b0a390;font-style:italic;}
        .hf-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; overflow-y:auto; }
        .hf-modal { background:#fff; border-radius:8px; max-width:1040px; margin:24px auto; box-shadow:0 5px 25px rgba(0,0,0,.3); }
        #viewMask .hf-modal, #autoSignMask .hf-modal { max-width:min(1100px, 94vw); }
        .hf-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; position:sticky; top:0; }
        .hf-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .hf-modal .m-body { padding:15px; }
        .hf-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .hf-modal .m-body input[type=text], .hf-modal .m-body input[type=date], .hf-modal .m-body input[type=password], .hf-modal .m-body select, .hf-modal .m-body textarea
            { border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:13px; box-sizing:border-box; width:100%; }
        .hf-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .hf-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; margin-left:6px; }
        .hf-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .hf-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; }
        .hf-modal .m-foot .b-danger { background:#DD5138; color:#fff; border-color:#c23f28; }
        table.itm-tbl { width:100%; border-collapse:collapse; font-size:12.5px; margin-bottom:8px; }
        table.itm-tbl th, table.itm-tbl td { border:1px solid #EADFC8; padding:5px 6px; vertical-align:top; }
        table.itm-tbl thead th { background:#F7E0BD; color:#5b3a1e; }
        table.itm-tbl textarea { width:100%; min-height:88px; border:1px solid #D8BE93; border-radius:4px; padding:4px 6px; font-size:12.5px; box-sizing:border-box; }
        table.itm-tbl select, table.itm-tbl input[type=number], table.itm-tbl input[type=text] { width:100%; height:56px; border:1px solid #D8BE93; border-radius:4px; padding:3px 5px; font-size:12px; box-sizing:border-box; }
        .hf-people-pick { border:1px solid #D8BE93; border-radius:6px; padding:6px; }
        .hf-people-pick .flt { width:100%; border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:12.5px; margin-bottom:6px; box-sizing:border-box; }
        .hf-people-list { max-height:260px; overflow-y:auto; }
        .ppl-dept-hd { background:#FBF0DD; font-weight:bold; font-size:12px; color:#8A5A2B; padding:3px 5px; cursor:pointer; margin-top:4px; border-radius:3px; }
        .ppl-dept-hd:hover { background:#F7E0BD; }
        .hf-people-list label { display:block; font-size:12.5px; padding:2px 4px 2px 16px; margin:0; cursor:pointer; }
        .hf-people-list label:hover { background:#FBF0DD; }
        .hf-people-list .leave-tag { color:#DD5138; font-size:11px; }
        .hf-people-list .concur-tag { color:#c0782d; font-size:11px; }
        .hf-machine-pick { max-height:200px; overflow-y:auto; border:1px solid #D8BE93; border-radius:6px; padding:6px; margin-top:4px; }
        .hf-machine-pick label { display:block; font-size:12.5px; padding:2px 4px; cursor:pointer; }
        .hf-radio-row label { display:inline-block; margin-right:14px; font-weight:normal; font-size:13px; }
        .decide-box { border:1.5px solid #E8D5B5; border-radius:8px; padding:10px; margin-top:10px; background:#FDF8EF; }
        .hf-score-tbl td input[type=number] { width:56px; text-align:center; }
        .nav-hf { margin:0 0 10px; }
        .nav-hf > li > a { color:#5b3a1e; }
        .nav-hf > li.active > a { color:#8A5A2B; font-weight:bold; border-color:#E8D5B5 #E8D5B5 #fff; }
        .hf-tabpane { display:none; }
        .hf-tabpane.active { display:block; }
        .err-list { color:#DD5138; font-size:12px; margin-top:6px; }
        .as-fill-box { border:1px dashed #D8BE93; border-radius:6px; padding:8px; margin-bottom:10px; background:#FBF0DD; }
        .as-fill-box .lbl { font-size:12px; color:#8a6d45; margin-right:4px; }
        .as-fill-box select { width:52px; height:26px; font-size:12px; display:inline-block; }
        table.as-sign-tbl input[type=number] { width:48px; text-align:center; }
        @media print { .page-help-btn { display:none; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;clear:both;">
            <h2 style="margin:6px 0;">人資職務表單 <small style="color:#8a6d45;">職務說明書／專業技能鑑定考核表／員工職能鑑定表</small></h2>
            <a href="hr_position_forms_template.php" class="admin-only" style="display:none;margin-left:12px;">範本管理→</a>
            <button class="page-help-btn" id="btnPageHelp" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div><h4><i class="fa fa-lock"></i> 無人資職務表單檢閱權限</h4><p>請洽系統管理者於「使用者權限設定」指派「人資職務表單」相關角色。</p></div>
<?php else: ?>
        <ul class="nav nav-tabs nav-hf" id="hfTabs">
            <li class="active"><a href="#" data-type="job_desc">職務說明書</a></li>
            <li><a href="#" data-type="skill_assess">專業技能鑑定考核表</a></li>
            <li><a href="#" data-type="competency">員工職能鑑定表</a></li>
        </ul>

<?php foreach (['job_desc','skill_assess','competency'] as $ft): ?>
        <div class="hf-tabpane<?= $ft==='job_desc'?' active':'' ?>" id="pane-<?= $ft ?>" data-type="<?= $ft ?>">
            <div class="hf-toolbar">
                <input type="text" class="kw" placeholder="搜尋部門/職位/姓名…" style="width:200px;">
                <?php if ($ft === 'job_desc'): ?>
                <select class="dept-filter" style="width:140px;">
                    <option value="">部門：全部</option>
                </select>
                <?php endif; ?>
                <button class="btn-warm btn-create"><i class="fa fa-plus"></i> 建立表單</button>
                <button class="btn-print-all"><i class="fa fa-print"></i> 列印全部</button>
                <?php if ($ft !== 'job_desc'): ?>
                <button class="btn-missing"><i class="fa fa-exclamation-circle"></i> 缺件提示</button>
                <select class="st-filter" style="width:120px;">
                    <option value="">狀態：全部</option>
                    <option value="draft">草稿</option>
                    <option value="confirming">確認中</option>
                    <option value="approving">核准中</option>
                    <option value="signed">已完成</option>
                    <option value="rejected">已退回</option>
                </select>
                <button class="btn-auto-sign" style="display:none;background:#8A5A2B;color:#fff;"><i class="fa fa-magic"></i> 超管自動簽核</button>
                <button class="btn-bulk-del" style="display:none;background:#DD5138;color:#fff;"><i class="fa fa-trash"></i> 超管批次刪除</button>
                <?php endif; ?>
                <span class="hf-asdoc"></span>
                <div class="hf-pager">
                    每頁<select class="page-size"><option>5</option><option selected>10</option><option>20</option><option>50</option></select>筆
                    <button class="pg-prev">‹</button><span class="pg-label">1/1</span><button class="pg-next">›</button>
                </div>
            </div>
            <div class="hf-table-wrap">
            <table class="hf-tbl">
                <thead class="thead-<?= $ft ?>"></thead>
                <tbody class="list-body"><tr><td colspan="10" style="text-align:center;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
            </div>
        </div>
<?php endforeach; ?>
<?php endif; ?>
    </div>
</div>
</div>

<!-- 建立/批次建立 modal -->
<div class="hf-mask" id="createMask"><div class="hf-modal" style="max-width:640px;">
    <div class="m-head"><span id="createTitle">建立表單</span><span class="m-close" onclick="closeMask('createMask')">✕</span></div>
    <div class="m-body">
        <label>日期（可自行指定以利補登舊資料）</label>
        <input type="date" id="createBizDate" max="9999-12-31">
        <div id="createAsofHint" style="display:none;font-size:12px;color:#8a6d45;margin:2px 0 6px;"></div>
        <div id="createDeptPosBlock" style="display:none;">
            <label>選擇部門×職位（可複選；01職務說明書以部門×職位為主，不綁單一員工，有人在職的組合都需要一份，含兼任職位；灰底＝已建立過或全站最高決策者不需要）</label>
            <div class="hf-people-pick">
                <input type="text" class="flt" placeholder="輸入部門/職稱篩選…" oninput="hfFilterPeople(this)">
                <div class="hf-people-list" id="createDeptPosList"></div>
            </div>
        </div>
        <div id="createPeopleBlock">
        <label>選擇員工（可複選；選 1 人＝單人建立，選多人＝批次建立；點部門標題可整組全選/取消，兼任職務的人會同時列在各自部門底下）</label>
        <div class="hf-people-pick">
            <input type="text" class="flt" placeholder="輸入姓名/部門/職稱篩選…" oninput="hfFilterPeople(this)">
            <div class="hf-people-list" id="createPeopleList"></div>
        </div>
        </div>
        <div id="createMachineBlock" style="display:none;">
            <label>機型來源</label>
            <div class="hf-radio-row">
                <label><input type="radio" name="mSrc" value="tpl" checked onchange="hfToggleMachineSrc()"> 依各員工職位範本自動帶入（預設）</label>
                <label><input type="radio" name="mSrc" value="manual" onchange="hfToggleMachineSrc()"> 手動指定機型（套用到全部選取員工）</label>
            </div>
            <div id="createMachineList" style="display:none;">
                <div style="margin-bottom:4px;"><button type="button" class="hf-btn-sm" onclick="hfMachineCkAll(true)">全選</button> <button type="button" class="hf-btn-sm" onclick="hfMachineCkAll(false)">取消全選</button></div>
                <div class="hf-machine-pick" id="createMachineListBody"></div>
            </div>
        </div>
        <div class="err-list" id="createErrList"></div>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('createMask')">取消</button><button class="b-ok" onclick="hfSubmitCreate()">建立</button></div>
</div></div>

<!-- 超管批次刪除 modal -->
<div class="hf-mask" id="bulkDelMask"><div class="hf-modal" style="max-width:720px;">
    <div class="m-head"><span>超級管理員批次刪除</span><span class="m-close" onclick="closeMask('bulkDelMask')">✕</span></div>
    <div class="m-body">
        <p style="font-size:12.5px;color:#DD5138;">將刪除下列 <b id="bulkDelCount">0</b> 筆表單，連同表單內容、簽核紀錄與相關通知一併移除，<b>刪除後無法復原</b>。已完成簽核的表單也會被刪除，請確認後再執行。</p>
        <div style="max-height:300px;overflow-y:auto;border:1px solid #D8BE93;border-radius:6px;padding:6px;" id="bulkDelList"></div>
        <label>操作確認密碼</label>
        <input type="password" id="bulkDelPwd">
        <div class="err-list" id="bulkDelErr"></div>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('bulkDelMask')">取消</button><button class="b-ok" style="background:#DD5138;" onclick="hfSubmitBulkDel()">確認刪除</button></div>
</div></div>

<!-- 檢視/編輯/簽核 modal -->
<div class="hf-mask" id="viewMask"><div class="hf-modal">
    <div class="m-head"><span id="viewTitle">表單</span><span class="m-close" onclick="closeMask('viewMask')">✕</span></div>
    <div class="m-body" id="viewBody"></div>
</div></div>

<!-- 多選 picker modal（AS文件/KPI項目共用，選定後把結果附加進目標文字欄，比照 hr_position_forms_template.php 同款） -->
<div class="hf-mask" id="multiPickMask"><div class="hf-modal" style="max-width:600px;">
    <div class="m-head"><span id="multiPickTitle">選擇</span><span class="m-close" onclick="closeMask('multiPickMask')">✕</span></div>
    <div class="m-body">
        <label>輸入關鍵字篩選</label><input type="text" id="multiPickFilter" oninput="multiPickFilterList(this.value)">
        <div style="max-height:320px;overflow-y:auto;border:1px solid #D8BE93;border-radius:6px;padding:6px;" id="multiPickList"></div>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('multiPickMask')">取消</button><button class="b-ok" onclick="multiPickConfirm()">加入所選</button></div>
</div></div>

<!-- 超管自動簽核 modal -->
<div class="hf-mask" id="autoSignMask"><div class="hf-modal">
    <div class="m-head"><span>超級管理員自動簽核</span><span class="m-close" onclick="closeMask('autoSignMask')">✕</span></div>
    <div class="m-body">
        <p style="font-size:12.5px;color:#8a6d45;">已勾選 <b id="autoSignCount">0</b> 筆表單，請先確認/調整下方各筆分數，再輸入操作確認密碼執行。技能鑑定表 NA 欄位（課長考核，該員工直屬主管與核准人為同一人時無中間層可考核）不可填寫。</p>
        <div class="as-fill-box">
            <span class="lbl">一鍵套用固定分數到全部技能鑑定表（可再個別修改）：</span><br>
            總經理－品質<input type="text" id="fillQGm" class="score-inp" inputmode="numeric" maxlength="1" style="width:36px;height:30px;font-size:13px;"> 效率<input type="text" id="fillEGm" class="score-inp" inputmode="numeric" maxlength="1" style="width:36px;height:30px;font-size:13px;"> 熟練度<input type="text" id="fillPGm" class="score-inp" inputmode="numeric" maxlength="1" style="width:36px;height:30px;font-size:13px;">
            　課長－品質<input type="text" id="fillQMgr" class="score-inp" inputmode="numeric" maxlength="1" style="width:36px;height:30px;font-size:13px;"> 效率<input type="text" id="fillEMgr" class="score-inp" inputmode="numeric" maxlength="1" style="width:36px;height:30px;font-size:13px;"> 熟練度<input type="text" id="fillPMgr" class="score-inp" inputmode="numeric" maxlength="1" style="width:36px;height:30px;font-size:13px;">
            <button type="button" class="hf-btn-sm" onclick="hfAutoSignApplyAll()">套用到全部</button>
        </div>
        <div class="hf-table-wrap" style="max-height:260px;overflow-y:auto;">
        <table class="hf-tbl as-sign-tbl" id="autoSignRowsTbl">
            <thead><tr><th>姓名</th><th>類型/機型</th><th>品質(總經理/課長)</th><th>效率(總經理/課長)</th><th>熟練度(總經理/課長)</th></tr></thead>
            <tbody id="autoSignRowsBody"></tbody>
        </table>
        </div>
        <label>操作確認密碼</label>
        <input type="password" id="autoSignPwd">
        <label>簽核日期（決行時間會在此日期內隨機錯開，不跨天；預設抓所選表單的日期）</label>
        <input type="date" id="autoSignDate" max="9999-12-31">
        <div class="err-list" id="autoSignErr"></div>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('autoSignMask')">取消</button><button class="b-ok" onclick="hfSubmitAutoSign()">執行</button></div>
</div></div>

<!-- 使用說明 modal（鐵律7） -->
<div class="hf-mask" id="helpUseMask"><div class="hf-modal" style="max-width:780px;">
    <div class="m-head"><span>使用說明 — 人資職務表單</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <h4>功能說明</h4>
三張固定表單：<b>職務說明書</b>（以部門×職位為主，不綁單一員工——有人在職的部門×職位組合都需要一份，含兼任職位，內容依職位範本帶入，不需簽核，僅留記錄）、<b>專業技能鑑定考核表</b>（每位員工「每個機型」一份，固定不重複建立，總經理／課長各自評分，需確認＋核准）、<b>員工職能鑑定表</b>（每位員工一份，部門/職稱不變只需沿用既有那份不重複建立，職能項目依職位範本帶入，需確認＋核准）。三張表單各自獨立綁定 AS 文件編號。
        <h4>操作步驟</h4>
        <b>①建立表單</b>：職務說明書勾選一組或多組「部門×職位」＋指定日期即可建立（灰底＝已建立過或全站最高決策者不需要）；技能鑑定表／職能鑑定表<b>先填日期、再勾選一位或多位員工</b>（勾 1 人＝單人建立，勾多人＝批次建立；點部門標題可整組全選/取消）——<b>員工清單會依填入的日期自動改為「該日期當時」的組織</b>：當時所屬部門/職稱（依人事的職務調動紀錄回推，沒有異動紀錄的人用現況），且<b>當時在職、現在已離職的人也會列出並標示「已離職」</b>，方便補登舊表單；日期改一次清單就重抓一次，已勾選的人會保留。建立出來的表單存的部門/職稱/直屬主管也一律是該日期當時的狀態，不是今天的；系統會依比對到的部門×職位「職位範本」自動帶入內容，找不到範本會在建立結果顯示錯誤，需請管理員先到「範本管理」設定。專業技能鑑定考核表另需選機型（預設依職位範本的適用機型清單自動展開成多筆，也可手動指定機型套用到所有選取員工）。<br>
        <b>②填寫／評分</b>：職務說明書內容欄可直接編輯存檔；技能鑑定表由課長／總經理各自在「確認」「核准」時填寫自己那欄分數；職能鑑定表的操作/異常排除評分由確認人（直屬主管）填寫。若員工本身職等已無中間層主管可考核（其直屬主管解析結果與總經理相同），課長考核欄位為 NA，不可填寫。<br>
        <b>③送出</b>：技能鑑定表／職能鑑定表草稿建立後需按「送出」才會通知確認人（該員工直屬主管）；確認通過後自動通知核准人（總經理）；任一關退回都需填寫原因，退回後表單回到草稿可修改重送。<br>
        <b>④複製表單</b>：技能鑑定表／職能鑑定表可按「複製」，以複製者身分建立一份新草稿（機型/內容原樣帶入），需重新走送出流程；職務說明書以部門×職位為主不提供複製，內容直接在原表單編輯存檔即可。<br>
        <b>⑤列印</b>：可單筆列印，或「列印全部」依目前清單篩選結果逐筆各自開視窗列印（結果較多會先詢問是否自動分批排隊）；每份文件各自獨立分頁計算頁碼，只有內容超過一頁時才顯示「第X頁/共Y頁」，單頁文件不顯示頁碼。<br>
        <b>⑥超級管理員自動簽核</b>：僅 id=1 可用，勾選表單後可先逐筆調整分數（或用「一鍵套用固定分數」快速帶入再個別修改），輸入操作確認密碼＋指定簽核日期，一次補齊尚未完成的確認/核准關卡，用於補登舊紙本資料。<br>
        <b>⑦超級管理員批次刪除</b>：僅 id=1 可用，於清單勾選（表頭勾選框可整頁全選）後按「超管批次刪除」，跳窗會列出即將刪除的表單供核對，輸入操作確認密碼即可一次刪除；<b>連同表單內容、簽核紀錄與相關通知一併移除且無法復原</b>，已完成簽核的表單也會被刪除，補登錯誤要重做時用。
        <h4>重要行為</h4>
        ・部門是否產生技能鑑定表／職能鑑定表由管理員在「範本管理」設定，職務說明書全員適用。<br>
        ・機型選項為管理員從既有機台主檔（依機型去重，同機型多台機台編號只算一個考核對象）與量測儀器校驗的量具主檔勾選建立的白名單，不是全部主檔都能選。<br>
        ・確認人固定為該員工直屬主管、核准人固定為全站最高決策者（多數為總經理），無法個別調整。
        <h4>權限角色</h4>
        人資職務表單檢閱＝看清單（僅看跟自己有關的）；檢視全部＝可檢視自己職位以下員工的表單（同職級以上看不到）；建立＝新增/批次建立/複製/編輯/送出；列印；範本管理＝到「範本管理」頁設定範本/白名單/部門資格/AS文件綁定；管理者全權。
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">關閉</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<script src="../../resource/js/eg_stamp_tpl.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp_tpl.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script>
var API = '../../src/store/HrForm_API.php';
var META = {};
var CUR_TAB = 'job_desc';
var LISTS = {job_desc:[], skill_assess:[], competency:[]};
var PAGE_STATE = {job_desc:{page:1,size:10}, skill_assess:{page:1,size:10}, competency:{page:1,size:10}};
var FORM_LABEL = {job_desc:'職務說明書', skill_assess:'專業技能鑑定考核表', competency:'員工職能鑑定表'};
var STATUS_LABEL = {draft:'草稿', active:'已建立', confirming:'確認中', approving:'核准中', signed:'已完成', rejected:'已退回'};
var CUR = null; // 目前檢視中的 instance

function esc(s){ return $('<div>').text(s==null?'':s).html(); }
function dispDate(d){ return (typeof egFmtDate === 'function') ? egFmtDate(d) : (d||''); }
function openMask(id){ $('#'+id).css('display','block'); }
function closeMask(id){ $('#'+id).css('display','none'); }
function ajaxPost(action, data, cb){
    data = data || {}; data.action = action; data.csrf = META.csrf;
    $.post(API, data, function(res){ cb(res); }, 'json').fail(function(xhr){
        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error
                : (function(){ try { return JSON.parse(xhr.responseText).error; } catch(e){ return null; } })();
        cb({ok:false, error: msg || ('連線失敗（HTTP '+xhr.status+'）')});
    });
}

$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });

/* 日期一改就重抓「那一天」的員工清單（09/10 才有人員挑選；01 是部門×職位不受日期影響）。
   change＝月曆選日期/輸入完成離開欄位都會觸發；直接打字中的半成品日期不會誤觸發。 */
$('#createBizDate').on('change', function(){ hfLoadCreatePeople(); });

$('#hfTabs a').on('click', function(e){
    e.preventDefault();
    var t = $(this).data('type');
    $('#hfTabs li').removeClass('active'); $(this).parent().addClass('active');
    $('.hf-tabpane').removeClass('active'); $('#pane-'+t).addClass('active');
    CUR_TAB = t;
    loadList(t);
});
$('.hf-tabpane .kw').on('input', function(){ var ft=$(this).closest('.hf-tabpane').data('type'); PAGE_STATE[ft].page=1; renderList(ft); });
$('.hf-tabpane .st-filter').on('change', function(){ var ft=$(this).closest('.hf-tabpane').data('type'); PAGE_STATE[ft].page=1; renderList(ft); });
$('.hf-tabpane .dept-filter').on('change', function(){ var ft=$(this).closest('.hf-tabpane').data('type'); PAGE_STATE[ft].page=1; renderList(ft); });
$('.hf-tabpane .page-size').on('change', function(){ var ft=$(this).closest('.hf-tabpane').data('type'); PAGE_STATE[ft].size=parseInt(this.value,10); PAGE_STATE[ft].page=1; renderList(ft); });
$('.hf-tabpane .pg-prev').on('click', function(){ var ft=$(this).closest('.hf-tabpane').data('type'); if (PAGE_STATE[ft].page>1){ PAGE_STATE[ft].page--; renderList(ft); } });
$('.hf-tabpane .pg-next').on('click', function(){ var ft=$(this).closest('.hf-tabpane').data('type'); PAGE_STATE[ft].page++; renderList(ft); });
$('.hf-tabpane .btn-create').on('click', function(){ openCreateModal($(this).closest('.hf-tabpane').data('type')); });
$('.hf-tabpane .btn-print-all').on('click', function(){ printAll($(this).closest('.hf-tabpane').data('type')); });
$('.hf-tabpane .btn-auto-sign').on('click', function(){ openAutoSignModal($(this).closest('.hf-tabpane').data('type')); });
$('.hf-tabpane .btn-bulk-del').on('click', function(){ openBulkDelModal($(this).closest('.hf-tabpane').data('type')); });
$('.hf-tabpane .btn-missing').on('click', function(){ openMissingModal($(this).closest('.hf-tabpane').data('type')); });

function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        META = res;
        window.__ownCompany = META.company_name || '';
        if (META.perms.canAdmin) $('.admin-only').show();
        if (META.perms.isSuperAdmin) {
            // 職務說明書不需簽核，該分頁只顯示批次刪除，不顯示自動簽核
            $('.hf-tabpane:not([data-type="job_desc"]) .btn-auto-sign').show();
            $('.btn-bulk-del').show();
        }
        if (!META.perms.canCreate) $('.btn-create').prop('disabled', true).attr('title', '無建立權限，請洽管理員於「使用者權限設定」指派「人資職務表單」角色');
        if (!META.perms.canPrint) $('.btn-print-all').prop('disabled', true).attr('title', '無列印權限');
        loadAsDoc('job_desc'); loadAsDoc('skill_assess'); loadAsDoc('competency');
        buildTableHeads();
        if (cb) cb();
    });
}
function loadAsDoc(ft){
    $.getJSON(API, {action:'asdoc_get', form_type:ft}, function(res){
        if (!res.ok) return;
        var t = res.doc ? ((res.print_no || res.doc.doc_no)+' '+res.doc.doc_name) : '（尚未綁定 AS 文件編號）';
        $('#pane-'+ft+' .hf-asdoc').text('AS文件：'+t);
    });
}
function buildTableHeads(){
    var ck = META.perms.isSuperAdmin ? '<th style="width:26px;"><input type="checkbox" class="ck-all" onclick="toggleAllCk(this)"></th>' : '';
    $('.thead-job_desc').html('<tr>'+ck+'<th>部門</th><th>職位</th><th>日期</th><th>確認完成</th><th style="width:150px;">操作</th></tr>');
    $('.thead-skill_assess').html('<tr>'+ck+'<th>部門</th><th>姓名</th><th>機型</th><th>總經理考核</th><th>課長考核</th><th>確認</th><th>核准</th><th style="width:170px;">操作</th></tr>');
    $('.thead-competency').html('<tr>'+ck+'<th>部門</th><th>姓名</th><th>職務</th><th>確認</th><th>核准</th><th style="width:170px;">操作</th></tr>');
}
function toggleAllCk(box){
    $(box).closest('table').find('tbody .auto-ck').prop('checked', box.checked);
}

function loadList(ft){
    var url = {action:'list', form_type:ft};
    $.getJSON(API, url, function(res){
        if (!res.ok){ $('#pane-'+ft+' .list-body').html('<tr><td colspan="10" style="color:#DD5138;">'+esc(res.error||'載入失敗')+'</td></tr>'); return; }
        LISTS[ft] = res.instances || [];
        PAGE_STATE[ft].page = 1;
        if (ft === 'job_desc') buildDeptFilterOptions();
        renderList(ft);
    });
}
/** 部門篩選只列出「目前已建立表單」的部門，不是全公司部門清單（使用者明確要求）。 */
function buildDeptFilterOptions(){
    var $sel = $('#pane-job_desc .dept-filter');
    var cur = $sel.val();
    var names = [];
    (LISTS.job_desc||[]).forEach(function(r){ if (r.dept_name && names.indexOf(r.dept_name) < 0) names.push(r.dept_name); });
    names.sort();
    $sel.html('<option value="">部門：全部</option>' + names.map(function(n){ return '<option value="'+esc(n)+'">'+esc(n)+'</option>'; }).join(''));
    if (cur && names.indexOf(cur) >= 0) $sel.val(cur);
}

/** 全站考核分數(1~4)共用輸入元件：純文字輸入框取代下拉選單/number spinner，全站統一同一款樣式（技能鑑定/職能鑑定/超管自動簽核皆共用）。 */
function scoreInputHtml(cls, val, extraAttr){
    var v = (val===null || val===undefined || val==='') ? '' : val;
    return '<input type="text" class="'+cls+' score-inp" inputmode="numeric" maxlength="1" autocomplete="off" value="'+esc(String(v))+'"'+(extraAttr||'')+'>';
}
$(document).on('input', '.score-inp', function(){
    var raw = this.value;
    var clean = raw.replace(/[^1-4]/g, '').slice(0,1);
    var bad = raw !== '' && clean === '';
    var $msg = $(this).nextAll('.score-err-msg').first();
    if (bad) {
        if (!$msg.length) $(this).after('<div class="score-err-msg" style="color:#DD5138;font-size:10px;">僅能輸入1~4</div>');
    } else {
        $msg.remove();
    }
    if (this.value !== clean) this.value = clean;
});
function scoreAvg(a,b,c){ var v=[a,b,c].filter(function(x){return x!==null && x!=='';}); if(!v.length) return null; var s=0; v.forEach(function(x){s+=Number(x);}); return Math.round((s/v.length)*100)/100; }

/** 依目前篩選條件(搜尋字/狀態)過濾，不含分頁——「列印全部」要用這份，不能只印當頁。 */
function filteredRows(ft){
    var $pane = $('#pane-'+ft);
    var kw = ($pane.find('.kw').val()||'').toLowerCase();
    var stf = $pane.find('.st-filter').val()||'';
    var deptf = $pane.find('.dept-filter').val()||'';
    return (LISTS[ft]||[]).filter(function(r){
        if (stf && r.status !== stf) return false;
        if (deptf && r.dept_name !== deptf) return false;
        if (!kw) return true;
        var hay = (r.dept_name+' '+r.position_name+' '+(r.user_cname||'')+' '+(r.machine_display_name||'')+' '+(r.machine_model||'')+' '+(r.machine_real_name||'')+' '+(r.machine_asset_nos||[]).join(' ')).toLowerCase();
        return hay.indexOf(kw) >= 0;
    });
}

function renderList(ft){
    ft = ft || CUR_TAB;
    var $pane = $('#pane-'+ft);
    var all = filteredRows(ft);
    var st = PAGE_STATE[ft];
    var totalPages = Math.max(1, Math.ceil(all.length / st.size));
    if (st.page > totalPages) st.page = totalPages;
    var rows = all.slice((st.page-1)*st.size, st.page*st.size);
    $pane.find('.pg-label').text(st.page + '/' + totalPages + '（共' + all.length + '筆）');
    $pane.find('.pg-prev').prop('disabled', st.page<=1);
    $pane.find('.pg-next').prop('disabled', st.page>=totalPages);
    var $tb = $pane.find('.list-body');
    if (!rows.length){ $tb.html('<tr><td colspan="10" style="text-align:center;color:#8a6d45;">尚無資料</td></tr>'); return; }
    var html = '';
    rows.forEach(function(r){
        var stBadge = '<span class="st-badge st-'+r.status+'">'+(STATUS_LABEL[r.status]||r.status)+'</span>';
        var opBtns = '<button class="hf-btn-sm" onclick="openViewModal(\''+ft+'\','+r.id+')">檢視</button> '
                   + '<button class="hf-btn-sm" onclick="printOne(\''+ft+'\','+r.id+')">列印</button> '
                   + (ft==='job_desc' ? '' : '<button class="hf-btn-sm" onclick="copyInstance(\''+ft+'\','+r.id+')">複製</button> ')
                   + (META.perms.canAdmin || r.created_by == META.uid ? '<button class="hf-btn-sm" onclick="deleteInstance(\''+ft+'\','+r.id+')">刪除</button>' : '');
        var ck = META.perms.isSuperAdmin ? '<td><input type="checkbox" class="auto-ck" value="'+r.id+'"></td>' : '';
        if (ft === 'job_desc') {
            var jdConfirmCell = r.confirm_user_name ? (dispDate(r.confirm_at?r.confirm_at.substr(0,10):'')+' '+esc(r.confirm_user_name)) : '<span style="color:#8a6d45;">未確認</span>';
            html += '<tr>'+ck+'<td>'+esc(r.dept_name)+'</td><td>'+esc(r.position_name)+'</td><td>'+dispDate(r.business_date)+'</td><td>'+jdConfirmCell+'</td><td>'+opBtns+'</td></tr>';
        } else if (ft === 'skill_assess') {
            var gmAvg = scoreAvg(r.score_quality_gm,r.score_efficiency_gm,r.score_proficiency_gm);
            var mgrAvg = r.confirm_na ? null : scoreAvg(r.score_quality_mgr,r.score_efficiency_mgr,r.score_proficiency_mgr);
            var confirmCell = r.confirm_na ? '<span class="na-tag">NA</span>' : (r.confirm_user_name?esc(r.confirm_user_name):stBadge);
            html += '<tr>'+ck+'<td>'+esc(r.dept_name)+'</td><td>'+esc(r.user_cname)+'</td><td>'+machineListCellHtml(r)+'</td>'
                  + '<td>'+(gmAvg===null?'-':gmAvg)+'</td><td>'+(r.confirm_na?'<span class="na-tag">NA</span>':(mgrAvg===null?'-':mgrAvg))+'</td>'
                  + '<td>'+confirmCell+'</td><td>'+(r.approve_user_name?esc(r.approve_user_name):(r.status==='signed'?stBadge:'-'))+'</td>'
                  + '<td>'+opBtns+'</td></tr>';
        } else {
            html += '<tr>'+ck+'<td>'+esc(r.dept_name)+'</td><td>'+esc(r.user_cname)+'</td><td>'+esc(r.position_name)+'</td>'
                  + '<td>'+(r.confirm_user_name?esc(r.confirm_user_name):stBadge)+'</td><td>'+(r.approve_user_name?esc(r.approve_user_name):(r.status==='signed'?stBadge:'-'))+'</td>'
                  + '<td>'+opBtns+'</td></tr>';
        }
    });
    $tb.html(html);
    $pane.find('.ck-all').prop('checked', false);
}
</script>
<style>.hf-btn-sm{height:26px;padding:0 8px;border-radius:4px;font-size:11.5px;border:1px solid #D8BE93;background:#fff;color:#5b3a1e;cursor:pointer;}.hf-btn-sm:hover{background:#FBF0DD;}
.asset-pill{display:inline-block;padding:1px 7px;border-radius:10px;background:#F7E0BD;color:#5b3a1e;font-size:11px;border:1px solid #D8BE93;white-space:nowrap;}
.score-inp{width:56px;height:56px;text-align:center;font-size:16px;border:1px solid #D8BE93;border-radius:4px;box-sizing:border-box;}
.score-inp.inp-err{border-color:#DD5138;background:#FDEDEA;}</style>
<script>
/* ============================================================ 員工/機型挑選元件（依部門分組，兼任職務同時列在各自部門底下） ============================================================ */

function hfDeptGroups(people){
    var byDept = {}; // dept_id(或0=無部門) -> {name, rows:[{p, isPrimary}]}
    people.forEach(function(p){
        var ids = (p.dept_ids && p.dept_ids.length) ? p.dept_ids : [p.dept_id || 0];
        ids.forEach(function(did){
            did = did || 0;
            if (!byDept[did]) {
                var dName = did ? (((META.departments||[]).find(function(d){ return String(d.id)===String(did); }) || {}).name || ASOF_DEPT_NAMES[did]) : '（未設定部門）';
                byDept[did] = {name: dName || '（未知部門）', rows: []};
            }
            byDept[did].rows.push({p: p, isPrimary: String(p.dept_id) === String(did)});
        });
    });
    return byDept;
}
function hfPeoplePickerHtml(people, containerIdPrefix){
    var groups = hfDeptGroups(people);
    var deptIds = Object.keys(groups).sort(function(a,b){ return groups[a].name.localeCompare(groups[b].name, 'zh-Hant'); });
    var html = '';
    deptIds.forEach(function(did){
        var g = groups[did];
        html += '<div class="ppl-dept-hd" data-dept="'+did+'" onclick="hfDeptGroupToggle(this)"><i class="fa fa-folder-o"></i> '+esc(g.name)+'（'+g.rows.length+'人，點擊全選/取消）</div>';
        g.rows.forEach(function(row){
            var p = row.p;
            var lv = p.on_leave ? ' <span class="leave-tag">['+esc(p.leave_note)+']</span>' : '';
            if (p.resigned) lv += ' <span class="leave-tag">['+esc(p.resign_note||'已離職')+']</span>';
            var ct = !row.isPrimary ? ' <span class="concur-tag">(兼)</span>' : '';
            html += '<label data-dept="'+did+'" data-hay="'+esc((g.name||'')+' '+(p.position_name||'')+' '+p.user_cname).toLowerCase()+'">'
                  + '<input type="checkbox" class="ppl-ck" value="'+p.id+'" onchange="hfPplSync(this)"> '
                  + esc(p.position_name||'') + ' / ' + esc(p.user_cname) + ct + lv + '</label>';
        });
    });
    return html || '<span style="color:#8a6d45;">目前沒有符合資格的員工（請確認部門表單資格設定）</span>';
}
/** 同一人可能因兼任出現在多個部門群組下，勾一處要連動其他處，狀態才不會不一致。 */
function hfPplSync(box){
    $('.ppl-ck[value="'+box.value+'"]').prop('checked', box.checked);
}
function hfDeptGroupToggle(hd){
    var did = $(hd).data('dept');
    var $boxes = $('.ppl-ck').filter(function(){ return $(this).closest('label').data('dept') == did; });
    var allChecked = $boxes.length && $boxes.filter(':checked').length === $boxes.length;
    $boxes.each(function(){ $(this).prop('checked', !allChecked); hfPplSync(this); });
}
function hfFilterPeople(input){
    var kw = (input.value||'').toLowerCase();
    $(input).closest('.hf-people-pick').find('.hf-people-list label, .hf-people-list .ppl-dept-hd').each(function(){
        if ($(this).hasClass('ppl-dept-hd')) return; // 部門標題永遠顯示，方便當群組操作入口
        $(this).toggle(!kw || ($(this).data('hay')+'').indexOf(kw) >= 0);
    });
}

var CREATE_TYPE = 'job_desc';
/** 01職務說明書：依部門×職位分組的挑選器（灰底＝已建立過或全站最高決策者，不可勾選）。 */
function hfDeptPosPickerHtml(){
    var existing = {};
    (LISTS.job_desc||[]).forEach(function(r){ existing[r.dept_id+'-'+r.position_id] = true; });
    var top = META.top_approver_dept_position;
    var topKey = top ? (top.dept_id+'-'+top.position_id) : null;
    var groups = {};
    (META.dept_position_pairs||[]).forEach(function(p){
        if (!groups[p.dept_id]) groups[p.dept_id] = {name:p.dept_name, rows:[]};
        groups[p.dept_id].rows.push(p);
    });
    var deptIds = Object.keys(groups).sort(function(a,b){ return groups[a].name.localeCompare(groups[b].name,'zh-Hant'); });
    var html = '';
    deptIds.forEach(function(did){
        var g = groups[did];
        html += '<div class="ppl-dept-hd" data-dept="'+did+'" onclick="hfDeptPosGroupToggle(this)"><i class="fa fa-folder-o"></i> '+esc(g.name)+'（'+g.rows.length+'個職位，點擊全選/取消）</div>';
        g.rows.forEach(function(p){
            var key = p.dept_id+'-'+p.position_id;
            var isTop = key === topKey, isDone = !!existing[key];
            var tag = isTop ? ' <span class="na-tag">(全站最高決策者，不需要)</span>' : (isDone ? ' <span class="na-tag">(已建立)</span>' : '');
            html += '<label data-dept="'+did+'" data-hay="'+esc(g.name+' '+p.position_name).toLowerCase()+'"'+((isTop||isDone)?' style="opacity:.55;"':'')+'>'
                  + '<input type="checkbox" class="dp-ck" value="'+key+'"'+((isTop||isDone)?' disabled':'')+'> '
                  + esc(p.position_name) + '（' + p.holder_count + '人）' + tag + '</label>';
        });
    });
    return html || '<span style="color:#8a6d45;">查無部門×職位資料</span>';
}
function hfDeptPosGroupToggle(hd){
    var did = $(hd).data('dept');
    var $boxes = $('#createDeptPosList .dp-ck:not(:disabled)').filter(function(){ return $(this).closest('label').data('dept') == did; });
    var allChecked = $boxes.length && $boxes.filter(':checked').length === $boxes.length;
    $boxes.prop('checked', !allChecked);
}
/* 建立表單的員工清單一律「依輸入的日期」重算（2026-08-18 使用者明確要求）：補 2025 年的舊表單時，要看到的是
   那一天的組織——當時在生產2廠、現在調到別廠的人要出現在生產2廠底下，當時在職、現在已離職的人也要挑得到。
   回推邏輯在後端 hrf_people_asof()（走 user_position_history，ai-rules/14），這裡只負責重畫與保留已勾選的人。 */
var ASOF_DEPT_NAMES = {};
function hfLoadCreatePeople(){
    if (CREATE_TYPE === 'job_desc') return;
    var ft = CREATE_TYPE;
    var bizDate = $('#createBizDate').val() || META.today;
    var checked = {};
    $('#createPeopleList .ppl-ck:checked').each(function(){ checked[this.value] = true; });
    var kw = $('#createPeopleBlock .flt').val() || '';
    $('#createPeopleList').html('<span style="color:#8a6d45;">載入中…</span>');
    $.getJSON(API, {action:'people_asof', date:bizDate}, function(res){
        if (CREATE_TYPE !== ft) return;                       // 期間使用者已切換表單類型，這次結果作廢
        if (!res.ok){ $('#createPeopleList').html('<span style="color:#DD5138;">'+esc(res.error||'人員載入失敗')+'</span>'); return; }
        ASOF_DEPT_NAMES = res.dept_names || {};
        var col = ft === 'skill_assess' ? 'produce_skill_assess' : 'produce_competency';
        var eligDeptIds = (META.dept_type_settings||[]).filter(function(d){ return !!d[col]; }).map(function(d){ return d.department_id; });
        var people = (res.people||[]).filter(function(p){
            return (p.dept_ids||[]).some(function(d){ return eligDeptIds.indexOf(d) >= 0; });
        });
        $('#createPeopleList').html(hfPeoplePickerHtml(people));
        $('#createPeopleList .ppl-ck').each(function(){ if (checked[this.value]) this.checked = true; });
        if (kw) hfFilterPeople($('#createPeopleBlock .flt')[0]);
        var isPast = bizDate < META.today;
        $('#createAsofHint').toggle(isPast).text(isPast
            ? '＊已依 '+dispDate(bizDate)+' 當時的組織列出人員（部門/職稱為當時的狀態，含當時在職但現已離職者）'
            : '');
    });
}
function openCreateModal(ft){
    CREATE_TYPE = ft;
    $('#createTitle').text('建立表單 — '+FORM_LABEL[ft]);
    $('#createBizDate').val(META.today);
    $('#createAsofHint').hide();
    $('#createErrList').empty();
    $('#createDeptPosBlock').toggle(ft === 'job_desc');
    $('#createPeopleBlock').toggle(ft !== 'job_desc');
    if (ft === 'job_desc') {
        $('#createDeptPosList').html(hfDeptPosPickerHtml());
        $('#createMachineBlock').hide();
        openMask('createMask');
        return;
    }
    hfLoadCreatePeople();
    $('#createMachineBlock').toggle(ft === 'skill_assess');
    if (ft === 'skill_assess') {
        $('input[name=mSrc][value=tpl]').prop('checked', true);
        $('#createMachineList').hide();
        $.getJSON(API, {action:'whitelist_list'}, function(res){
            if (!res.ok) { $('#createMachineListBody').html('<span style="color:#8a6d45;">（僅管理員可預覽白名單，手動指定請洽管理員）</span>'); return; }
            $('#createMachineListBody').html(hfMachinePickHtml(res.whitelist||[]));
        });
    }
    openMask('createMask');
}
/* 手動指定機型的清單一律跟「機型/量具白名單」設定頁長得一樣：同機台類型/量具類別放一起、標示台數與
   機台(量具)編號，同一個機型只出現一次（分組與去重由後端 hrf_whitelist_list() 算好）。來源機台已停用或
   機型未填的失效白名單項目不列出——建立新表單不該再挑到它們。2026-08-18 使用者要求。 */
function hfMachinePickHtml(wl){
    var order = [], map = {};
    (wl||[]).forEach(function(w){
        if (Number(w.stale)) return;
        var g = w.group_name || '未分類';
        if (!map[g]) { map[g] = []; order.push(g); }
        map[g].push(w);
    });
    var html = '';
    order.forEach(function(g){
        html += '<div style="font-weight:bold;color:#8a6d45;margin:8px 0 2px;font-size:12.5px;">'+esc(g)+'</div>';
        map[g].forEach(function(w){
            var model = w.machine_model || w.whitelist_machine_model || (w.source_type==='machine' ? w.display_name : '') || '';
            var label = w.machine_name ? (w.machine_name !== model ? (model ? (model+' '+w.machine_name) : w.machine_name) : model) : w.display_name;
            var meta = w.source_type === 'machine'
                     ? (Number(w.unit_count) > 1 ? ('　共'+w.unit_count+'台，機台編號：'+esc(w.asset_no_list||'-')) : '')
                     : (w.machine_name ? ('　量具編號：'+esc(w.asset_no_list||w.display_name||'-')) : '');
            html += '<label><input type="checkbox" class="mach-ck" value="'+w.id+'"> '+esc(label)
                  + '<span style="color:#8a6d45;font-size:11px;">'+meta+'</span></label>';
        });
    });
    return html || '<span style="color:#8a6d45;">尚未建立白名單</span>';
}
function hfMachineCkAll(check){ $('#createMachineListBody .mach-ck').prop('checked', check); }
function hfToggleMachineSrc(){
    $('#createMachineList').toggle($('input[name=mSrc]:checked').val() === 'manual');
}
function hfSubmitCreate(){
    var bizDate = $('#createBizDate').val() || META.today;
    if (CREATE_TYPE === 'job_desc') {
        var pairs = $('#createDeptPosList .dp-ck:checked').map(function(){
            var kv = $(this).val().split('-');
            return {dept_id:kv[0], position_id:kv[1]};
        }).get();
        if (!pairs.length){ $('#createErrList').text('請至少選擇一組部門×職位'); return; }
        ajaxPost('batch_create', {form_type:'job_desc', dept_position_pairs:JSON.stringify(pairs), business_date:bizDate}, function(res){
            if (!res.ok){ $('#createErrList').text(res.error||'建立失敗'); return; }
            var msg = '成功建立 '+res.created+' 筆';
            if (res.skipped && res.skipped.length) msg += '；' + res.skipped.length + ' 筆已存在略過：' + res.skipped.join('；');
            if (res.errors && res.errors.length) msg += '；' + res.errors.length + ' 筆失敗：' + res.errors.join('；');
            $('#createErrList').css('color', res.errors && res.errors.length ? '#DD5138' : '#3f9142').text(msg);
            loadList('job_desc');
            if (!res.errors || !res.errors.length) setTimeout(function(){ closeMask('createMask'); }, 900);
        });
        return;
    }
    var uids = $('#createPeopleList .ppl-ck:checked').map(function(){ return $(this).val(); }).get();
    uids = uids.filter(function(v,i){ return uids.indexOf(v)===i; }); // 兼任會出現多次checkbox但value相同，去重
    if (!uids.length){ $('#createErrList').text('請至少選擇一位員工'); return; }
    var wids = [];
    if (CREATE_TYPE === 'skill_assess' && $('input[name=mSrc]:checked').val() === 'manual') {
        wids = $('#createMachineListBody .mach-ck:checked').map(function(){ return $(this).val(); }).get();
        if (!wids.length){ $('#createErrList').text('請至少選擇一個機型，或改選「依職位範本自動帶入」'); return; }
    }
    ajaxPost('batch_create', {form_type:CREATE_TYPE, user_ids:JSON.stringify(uids), whitelist_ids:JSON.stringify(wids), business_date:bizDate}, function(res){
        if (!res.ok){ $('#createErrList').text(res.error||'建立失敗'); return; }
        var msg = '成功建立 '+res.created+' 筆';
        if (res.skipped && res.skipped.length) msg += '；' + res.skipped.length + ' 筆已存在略過：' + res.skipped.join('；');
        if (res.errors && res.errors.length) msg += '；' + res.errors.length + ' 筆失敗：' + res.errors.join('；');
        $('#createErrList').css('color', res.errors && res.errors.length ? '#DD5138' : '#3f9142').text(msg);
        loadList(CREATE_TYPE);
        if (!res.errors || !res.errors.length) setTimeout(function(){ closeMask('createMask'); }, 900);
    });
}

/** 缺件提示：找出符合部門資格、但目前完全沒有任何該類表單的員工（09/10 專用；01有各自的部門×職位灰底提示不需要這支）。 */
function hfMissingReport(ft){
    var col = ft === 'skill_assess' ? 'produce_skill_assess' : 'produce_competency';
    var eligDeptIds = (META.dept_type_settings||[]).filter(function(d){ return !!d[col]; }).map(function(d){ return d.department_id; });
    var people = (META.people||[]).filter(function(p){ return (p.dept_ids||[]).some(function(d){ return eligDeptIds.indexOf(d) >= 0; }); });
    var have = {};
    (LISTS[ft]||[]).forEach(function(r){
        if (ft === 'competency') have[r.user_id+'-'+r.dept_id+'-'+r.position_id] = true;
        else have[r.user_id] = true;
    });
    return people.filter(function(p){
        return ft === 'competency' ? !have[p.id+'-'+p.dept_id+'-'+p.position_id] : !have[p.id];
    });
}
function openMissingModal(ft){
    var missing = hfMissingReport(ft);
    if (!missing.length){ alert('未偵測到缺件，符合資格的員工目前都已建立過「'+FORM_LABEL[ft]+'」。'); return; }
    var names = missing.map(function(p){ return esc(p.dept_name)+'／'+esc(p.position_name)+'／'+esc(p.user_cname); });
    if (!confirm('偵測到 '+missing.length+' 位員工尚未建立「'+FORM_LABEL[ft]+'」：\n'+names.join('\n')+'\n\n是否開啟建立表單視窗並預先勾選這些人？')) return;
    openCreateModal(ft);
    setTimeout(function(){ missing.forEach(function(p){ hfPplSync($('.ppl-ck[value="'+p.id+'"]').prop('checked', true)[0]); }); }, 30);
}
</script>
<script>
/* ============================================================ 檢視/編輯/簽核 ============================================================ */

function statusNote(r){
    if (r.status === 'rejected') return '<div style="color:#DD5138;">此表單已被退回，可修改後重新送出。</div>';
    return '';
}
function jdItemsTableHtml(items){
    var rows = items && items.length ? items : [{data:{}}];
    var html = '<div class="itm-tbl-wrap"><table class="itm-tbl"><thead><tr><th>工作摘要</th><th>工作相關程序書</th><th>產出表單名稱</th><th>DPI 項目（績效標準計算方式）</th></tr></thead>'
             + '<tbody id="jdItemsBody" data-eg-row-add="hfJdRowAdd" data-eg-row-del="hfJdRowDel">';
    rows.forEach(function(it){ html += jdRowHtml(it.data||{}); });
    html += '</tbody></table></div><button class="hf-btn-sm" onclick="hfJdRowAdd()">+新增列</button> <button class="hf-btn-sm" onclick="hfJdRowDel()">-刪除末列</button>';
    return html;
}
function jdRowHtml(d){
    return '<tr><td><textarea class="c-a">'+esc(d.summary||'')+'</textarea></td>'
         + '<td><textarea class="c-b">'+esc(d.process||'')+'</textarea><br>'
           + '<button type="button" class="hf-btn-sm" onclick="openAsDocPicker([\'二階\'],$(this).siblings(\'textarea\')[0])">選程序書(AS二階)</button></td>'
         + '<td><textarea class="c-c">'+esc(d.form_name||'')+'</textarea><br>'
           + '<button type="button" class="hf-btn-sm" onclick="openAsDocPicker([\'三階\',\'四階\'],$(this).siblings(\'textarea\')[0])">選表單(AS三/四階)</button></td>'
         + '<td><textarea class="c-d">'+esc(d.dpi||'')+'</textarea><br>'
           + '<button type="button" class="hf-btn-sm" onclick="openKpiPicker($(this).siblings(\'textarea\')[0])">選KPI標準</button></td></tr>';
}
function hfJdRowAdd(){ $('#jdItemsBody').append(jdRowHtml({})); }
function hfJdRowDel(){ var $rows=$('#jdItemsBody tr'); if ($rows.length>1) $rows.last().remove(); }

/* ============================================================ AS文件/KPI 多選 picker（共用modal，選定後附加進目標textarea，比照範本設定頁同款） ============================================================ */
var MULTI_PICK_TARGET = null, MULTI_PICK_ITEMS = [], MULTI_PICK_FORMAT = null;
function multiPickRender(items){
    $('#multiPickList').html(items.map(function(it, i){
        return '<label style="display:block;font-size:12.5px;padding:2px 0;" data-hay="'+esc(it._hay).toLowerCase()+'"><input type="checkbox" class="mp-ck" data-idx="'+i+'"> '+esc(it._label)+'</label>';
    }).join('') || '<span style="color:#8a6d45;">查無資料</span>');
}
function multiPickFilterList(kw){
    kw = (kw||'').toLowerCase();
    $('#multiPickList label').each(function(){ $(this).toggle(!kw || ($(this).data('hay')+'').indexOf(kw) >= 0); });
}
function multiPickConfirm(){
    var picked = $('.mp-ck:checked').map(function(){ return MULTI_PICK_ITEMS[$(this).data('idx')]; }).get();
    if (!picked.length){ closeMask('multiPickMask'); return; }
    var lines = picked.map(MULTI_PICK_FORMAT).join('\n');
    var cur = $(MULTI_PICK_TARGET).val();
    $(MULTI_PICK_TARGET).val(cur ? (cur + '\n' + lines) : lines);
    closeMask('multiPickMask');
}
/** 從 AS 文件管理現成 API 依「階」查詢（二階=程序書、三/四階=表單），選定後帶出「編號 名稱」不含版次。 */
function openAsDocPicker(levels, targetTextarea){
    MULTI_PICK_TARGET = targetTextarea;
    MULTI_PICK_FORMAT = function(d){ return d.doc_no + ' ' + d.doc_name; };
    $('#multiPickTitle').text('選擇 AS 文件（'+levels.join('/')+'）');
    $('#multiPickFilter').val('');
    $('#multiPickList').html('<span style="color:#8a6d45;">載入中…</span>');
    openMask('multiPickMask');
    var calls = levels.map(function(lv){
        return $.getJSON('../../src/store/AS_Document_API.php', {action:'list_documents', level:lv});
    });
    $.when.apply($, calls).always(function(){
        var results = calls.length > 1 ? Array.prototype.slice.call(arguments) : [arguments];
        var docs = [];
        results.forEach(function(r){
            var res = r[0];
            if (res && res.status === 'success' && res.data) docs = docs.concat(res.data);
        });
        MULTI_PICK_ITEMS = docs.map(function(d){ return {doc_no:d.doc_no, doc_name:d.doc_name, _label:d.doc_no+' '+d.doc_name, _hay:d.doc_no+' '+d.doc_name}; });
        multiPickRender(MULTI_PICK_ITEMS);
    });
}
var KPI_INDICATORS_CACHE = null;
function openKpiPicker(targetTextarea){
    MULTI_PICK_TARGET = targetTextarea;
    MULTI_PICK_FORMAT = function(d){ return d.name + '（' + (d.stat_desc||'') + '）'; };
    $('#multiPickTitle').text('選擇 KPI 標準與計算方式');
    $('#multiPickFilter').val('');
    if (KPI_INDICATORS_CACHE) { MULTI_PICK_ITEMS = KPI_INDICATORS_CACHE; multiPickRender(MULTI_PICK_ITEMS); openMask('multiPickMask'); return; }
    $('#multiPickList').html('<span style="color:#8a6d45;">載入中…</span>');
    openMask('multiPickMask');
    $.getJSON(API, {action:'kpi_indicator_list'}, function(res){
        var rows = res.ok ? (res.indicators||[]) : [];
        KPI_INDICATORS_CACHE = rows.map(function(d){ return {name:d.name, stat_desc:d.stat_desc, _label:d.name+'（'+(d.stat_desc||'')+'）', _hay:d.name+' '+(d.stat_desc||'')}; });
        MULTI_PICK_ITEMS = KPI_INDICATORS_CACHE;
        multiPickRender(MULTI_PICK_ITEMS);
    });
}
function jdItemsCollect(){
    var out = [];
    $('#jdItemsBody tr').each(function(){
        var $t = $(this);
        out.push({data:{summary:$t.find('.c-a').val(), process:$t.find('.c-b').val(), form_name:$t.find('.c-c').val(), dpi:$t.find('.c-d').val()}});
    });
    return out;
}

/** 有建立「專業技能鑑定考核表」的部門，員工職能鑑定表的項目本來就是機台清單帶入，欄位標題改標「機台設定」；其餘部門維持通用「項目名稱」。 */
function deptHasSkillAssess(deptId){
    var d = (META.dept_type_settings||[]).find(function(x){ return String(x.department_id)===String(deptId); });
    return !!(d && d.produce_skill_assess);
}
function cpItemsTableHtml(items, editable, deptId){
    var rows = items && items.length ? items : [{data:{}}];
    var nameLabel = deptHasSkillAssess(deptId) ? '機台設定' : '項目名稱';
    var html = '<div class="itm-tbl-wrap"><table class="itm-tbl"><thead><tr><th style="width:36px;">編號</th><th>'+nameLabel+'</th><th style="width:110px;">操作</th><th style="width:110px;">異常排除</th></tr></thead>'
             + '<tbody id="cpItemsBody" data-eg-row-add="hfCpRowAdd" data-eg-row-del="hfCpRowDel">';
    rows.forEach(function(it,i){ html += cpRowHtml(it.data||{}, i+1, editable); });
    html += '</tbody></table></div>';
    if (editable) {
        html += '<button class="hf-btn-sm" onclick="hfCpRowAdd()">+新增列</button> <button class="hf-btn-sm" onclick="hfCpRowDel()">-刪除末列</button>'
              + '<div style="margin-top:8px;font-size:12.5px;">一鍵套用：操作 <input type="text" id="cpFillOp" class="score-inp" inputmode="numeric" maxlength="1" style="width:40px;height:30px;font-size:13px;"> <button type="button" class="hf-btn-sm" onclick="hfCpFillAll(\'op\')">套用到全部</button>'
              + '　異常排除 <input type="text" id="cpFillEx" class="score-inp" inputmode="numeric" maxlength="1" style="width:40px;height:30px;font-size:13px;"> <button type="button" class="hf-btn-sm" onclick="hfCpFillAll(\'ex\')">套用到全部</button></div>';
    }
    return html;
}
function cpRowHtml(d, no, editable){
    var nameCell = editable ? '<textarea class="c-name">'+esc(d.skill_name||'')+'</textarea>' : esc(d.skill_name||'');
    return '<tr><td style="text-align:center;">'+no+'</td><td>'+nameCell+'</td>'
         + '<td>'+(editable ? scoreInputHtml('c-op', d.score_op) : esc(d.score_op||'-'))+'</td>'
         + '<td>'+(editable ? scoreInputHtml('c-ex', d.score_ex) : esc(d.score_ex||'-'))+'</td></tr>';
}
function hfCpFillAll(which){
    var v = ($('#cpFill'+(which==='op'?'Op':'Ex')).val()||'').replace(/[^1-4]/g,'').slice(0,1);
    if (!v){ alert('請先輸入1~4的數字'); return; }
    $('#cpItemsBody .c-'+which).val(v);
}
function hfCpRowAdd(){ var n=$('#cpItemsBody tr').length+1; $('#cpItemsBody').append(cpRowHtml({}, n, true)); }
function hfCpRowDel(){ var $rows=$('#cpItemsBody tr'); if ($rows.length>1) $rows.last().remove(); }
function cpItemsCollect(){
    var out = [];
    $('#cpItemsBody tr').each(function(){
        var $t = $(this);
        out.push({data:{skill_name:$t.find('.c-name').val() || $t.find('td').eq(1).text(), score_op:$t.find('.c-op').val()||null, score_ex:$t.find('.c-ex').val()||null}});
    });
    return out;
}

function openViewModal(ft, id){
    $.getJSON(API, {action:'get', id:id}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        CUR = res.instance;
        $('#viewTitle').text(FORM_LABEL[ft]+' — '+(ft==='job_desc' ? (CUR.dept_name+'／'+CUR.position_name) : CUR.user_cname));
        $('#viewBody').html(renderViewBody(ft, CUR));
        openMask('viewMask');
    });
}
/** 09表單「機型」列：右側附機台名稱、下一格列出目前所有未停用機台編號（即時從 machine_list 撈出，view/print 共用）。 */
function machineNameLabel(r){
    var model = r.machine_model || r.machine_display_name || '';
    var name = r.machine_real_name || '';
    return (name && name !== model) ? (model + ' ' + name) : model;
}
function machineAssetNoText(r){
    return (r.machine_asset_nos && r.machine_asset_nos.length) ? r.machine_asset_nos.join('、') : '-';
}
/** 列表「機型」欄：機型 機台名稱 後面接每個未停用機台編號的按鈕式小標籤。 */
function machineListCellHtml(r){
    var pills = (r.machine_asset_nos||[]).map(function(a){ return '<span class="asset-pill">('+esc(a)+')</span>'; }).join(' ');
    return esc(machineNameLabel(r)) + (pills ? ' '+pills : '');
}
function headTableHtml(r){
    var h = '<table class="itm-tbl"><tbody>'
          + '<tr><th style="width:90px;">部門</th><td>'+esc(r.dept_name||'')+'</td><th style="width:90px;">職位</th><td>'+esc(r.position_name||'')+'</td></tr>';
    if (r.form_type === 'job_desc') {
        h += '<tr><th>日期</th><td>'+dispDate(r.business_date)+'</td><th>狀態</th><td>'+(STATUS_LABEL[r.status]||r.status)+'</td></tr>';
        h += '</tbody></table>';
        return h;
    }
    h += '<tr><th>姓名</th><td>'+esc(r.user_cname||'')+'</td><th>員工編號</th><td>'+esc(r.user_no||'')+'</td></tr>'
       + '<tr><th>到職日</th><td>'+dispDate(r.onboard_date)+'</td><th>主管</th><td>'+esc(r.supervisor_name||'')+'</td></tr>'
       + '<tr><th>日期</th><td>'+dispDate(r.business_date)+'</td><th>狀態</th><td>'+(STATUS_LABEL[r.status]||r.status)+'</td></tr>';
    if (r.form_type === 'skill_assess') h += '<tr><th>機型</th><td>'+esc(machineNameLabel(r))+'</td><th>機台編號</th><td>'+esc(machineAssetNoText(r))+'</td></tr>';
    if (r.form_type === 'competency') {
        var updCell = META.perms.isSuperAdmin
            ? '<input type="date" id="cpUpdDate" max="9999-12-31" value="'+esc(r.cp_update_date||r.business_date||'')+'" style="width:150px;"> <button type="button" class="hf-btn-sm" onclick="hfCpUpdDateSave()">儲存</button>'
            : esc(dispDate(r.cp_update_date||r.business_date));
        h += '<tr><th>最新更新日期</th><td colspan="3">'+updCell+'</td></tr>';
    }
    h += '</tbody></table>';
    return h;
}
function hfCpUpdDateSave(){
    var d = $('#cpUpdDate').val();
    if (!d){ alert('請選擇日期'); return; }
    ajaxPost('cp_set_update_date', {id:CUR.id, date:d}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        alert('已儲存'); openViewModal('competency', CUR.id); loadList('competency');
    });
}
function decideBoxHtml(level, r){
    if (r.status !== level) return '';
    var scoreInputs = '';
    if (r.form_type === 'skill_assess') {
        if (level === 'confirming' && r.confirm_na) {
            scoreInputs = '<p style="color:#b0a390;">此員工職等已無中間層可考核，課長考核為 <b>NA</b>，直接按下方「確認通過」即可。</p>';
        } else {
            var suf = level === 'confirming' ? 'mgr' : 'gm';
            var label = level === 'confirming' ? '課長考核' : '總經理考核';
            scoreInputs = '<p style="font-weight:bold;">'+label+'評分（1~4分）</p>'
                + '<table class="itm-tbl hf-score-tbl"><tr><td>品質</td><td><input type="number" min="1" max="4" id="sc-quality" value="'+(r['score_quality_'+suf]??'')+'"></td>'
                + '<td>效率</td><td><input type="number" min="1" max="4" id="sc-efficiency" value="'+(r['score_efficiency_'+suf]??'')+'"></td>'
                + '<td>熟練度</td><td><input type="number" min="1" max="4" id="sc-proficiency" value="'+(r['score_proficiency_'+suf]??'')+'"></td></tr></table>';
        }
    }
    var btnLabel = level === 'confirming' ? '確認' : '核准';
    var fn = level === 'confirming' ? 'hfConfirmDecide' : 'hfApproveDecide';
    return '<div class="decide-box"><b>'+btnLabel+'（'+(level==='confirming'?'直屬主管':'總經理')+'）</b>'
         + scoreInputs
         + '<label>退回原因（僅退回時必填）</label><textarea id="decideNote" rows="2"></textarea>'
         + '<div style="margin-top:8px;"><button class="b-ok" onclick="'+fn+'(\'approved\')">'+btnLabel+'通過</button> '
         + '<button class="b-danger" onclick="'+fn+'(\'rejected\')">退回</button></div></div>';
}
function renderViewBody(ft, r){
    var h = statusNote(r) + headTableHtml(r);
    if (ft === 'job_desc') {
        h += jdItemsTableHtml(r.items);
        var jdConfirmNote = r.confirm_user_name
            ? ('已確認完成：'+dispDate(r.confirm_at?r.confirm_at.substr(0,10):'')+' '+esc(r.confirm_user_name))
            : '尚未確認完成';
        h += '<div style="margin-top:10px;">'
           + '<button class="b-ok" onclick="hfSaveItems(\'job_desc\')">存檔</button> '
           + '<button class="hf-btn-sm" onclick="printOne(\'job_desc\','+r.id+')">列印</button> '
           + '<button class="hf-btn-sm" onclick="hfJdConfirm()">確認完成</button> '
           + '<span style="font-size:12.5px;color:#8a6d45;">'+jdConfirmNote+'</span>'
           + '</div>';
    } else if (ft === 'skill_assess') {
        var mgrNA = !!r.confirm_na;
        h += '<table class="itm-tbl hf-score-tbl"><thead><tr><th></th><th>品質</th><th>效率</th><th>熟練度</th><th>平均</th></tr></thead><tbody>'
           + '<tr><th>總經理考核</th><td>'+(r.score_quality_gm??'-')+'</td><td>'+(r.score_efficiency_gm??'-')+'</td><td>'+(r.score_proficiency_gm??'-')+'</td><td>'+(scoreAvg(r.score_quality_gm,r.score_efficiency_gm,r.score_proficiency_gm)??'-')+'</td></tr>'
           + '<tr><th>課長考核</th>'+(mgrNA ? '<td colspan="4" class="na-tag">NA（此員工職等已無中間層可考核）</td>' :
               ('<td>'+(r.score_quality_mgr??'-')+'</td><td>'+(r.score_efficiency_mgr??'-')+'</td><td>'+(r.score_proficiency_mgr??'-')+'</td><td>'+(scoreAvg(r.score_quality_mgr,r.score_efficiency_mgr,r.score_proficiency_mgr)??'-')+'</td>'))
           + '</tr></tbody></table>';
        if (r.status === 'draft') h += '<div style="margin-top:8px;"><button class="b-ok" onclick="hfSubmitInstance()">送出（通知直屬主管確認）</button></div>';
        h += decideBoxHtml('confirming', r) + decideBoxHtml('approving', r);
        h += '<div style="margin-top:10px;"><button class="hf-btn-sm" onclick="printOne(\'skill_assess\','+r.id+')">列印</button> <button class="hf-btn-sm" onclick="copyInstance(\'skill_assess\','+r.id+')">複製</button></div>';
    } else {
        // 使用者明確要求：送簽後的員工職能鑑定表仍可修改，存檔時後端會自動退回草稿＋改今天的最新更新日期並要求重新送簽（見 hrf_instance_save_items()）
        h += cpItemsTableHtml(r.items, true, r.dept_id);
        h += '<div style="margin-top:8px;"><button class="b-ok" onclick="hfSaveItems(\'competency\')">存檔</button> '
           + (r.status === 'draft' ? '<button class="b-ok" onclick="hfSubmitInstance()">送出（通知直屬主管確認）</button>' : '')
           + '</div>';
        if (r.status !== 'draft') h += '<p style="font-size:12px;color:#c0782d;margin-top:4px;">內容如有異動並存檔，將自動退回草稿並需重新送出簽核。</p>';
        h += decideBoxHtml('confirming', r) + decideBoxHtml('approving', r);
        h += '<div style="margin-top:10px;"><button class="hf-btn-sm" onclick="printOne(\'competency\','+r.id+')">列印</button> <button class="hf-btn-sm" onclick="copyInstance(\'competency\','+r.id+')">複製</button></div>';
    }
    return h;
}
function hfSaveItems(ft){
    var items = ft === 'job_desc' ? jdItemsCollect() : cpItemsCollect();
    ajaxPost('save_items', {id:CUR.id, items:JSON.stringify(items)}, function(res){
        if (!res.ok){ alert(res.error||'存檔失敗'); return; }
        alert('已存檔'); loadList(ft);
    });
}
function hfJdConfirm(){
    ajaxPost('jd_confirm', {id:CUR.id}, function(res){
        if (!res.ok){ alert(res.error||'確認失敗'); return; }
        openViewModal('job_desc', CUR.id);
        loadList('job_desc');
    });
}
function hfSubmitInstance(){
    ajaxPost('submit', {id:CUR.id}, function(res){
        if (!res.ok){ alert(res.error||'送出失敗'); return; }
        alert('已送出'); closeMask('viewMask'); loadList(CUR.form_type);
    });
}
function hfConfirmDecide(decision){
    var note = $('#decideNote').val();
    if (decision === 'rejected' && !note){ alert('退回請填寫原因'); return; }
    var payload = {id:CUR.id, decision:decision, note:note};
    if (CUR.form_type === 'skill_assess' && !CUR.confirm_na) payload.scores = JSON.stringify({quality_mgr:$('#sc-quality').val(), efficiency_mgr:$('#sc-efficiency').val(), proficiency_mgr:$('#sc-proficiency').val()});
    if (CUR.form_type === 'competency') payload.items = JSON.stringify(cpItemsCollect());
    ajaxPost('confirm_decide', payload, function(res){
        if (!res.ok){ alert(res.error||'處理失敗'); return; }
        alert('已處理'); closeMask('viewMask'); loadList(CUR.form_type);
    });
}
function hfApproveDecide(decision){
    var note = $('#decideNote').val();
    if (decision === 'rejected' && !note){ alert('退回請填寫原因'); return; }
    var payload = {id:CUR.id, decision:decision, note:note};
    if (CUR.form_type === 'skill_assess') payload.scores = JSON.stringify({quality_gm:$('#sc-quality').val(), efficiency_gm:$('#sc-efficiency').val(), proficiency_gm:$('#sc-proficiency').val()});
    ajaxPost('approve_decide', payload, function(res){
        if (!res.ok){ alert(res.error||'處理失敗'); return; }
        alert('已處理'); closeMask('viewMask'); loadList(CUR.form_type);
    });
}
function copyInstance(ft, id){
    if (!confirm('確定要複製這份表單？將建立一份新草稿。')) return;
    ajaxPost('copy', {id:id}, function(res){
        if (!res.ok){ alert(res.error||'複製失敗'); return; }
        alert('已複製為新草稿'); loadList(ft);
    });
}
function deleteInstance(ft, id){
    if (!confirm('確定要刪除這份表單？無法復原。')) return;
    ajaxPost('delete', {id:id}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        loadList(ft);
    });
}
</script>
<script>
/* ============================================================ 超管自動簽核（逐筆可調分數＋一鍵套用固定分數） ============================================================ */
/* ============================================================ 超管批次刪除 ============================================================ */
var BULK_DEL_TYPE = null, BULK_DEL_ROWS = [];
function openBulkDelModal(ft){
    BULK_DEL_TYPE = ft;
    var ids = $('#pane-'+ft+' .auto-ck:checked').map(function(){ return this.value; }).get();
    if (!ids.length){ alert('請先在清單勾選要刪除的表單'); return; }
    BULK_DEL_ROWS = (LISTS[ft]||[]).filter(function(r){ return ids.indexOf(String(r.id)) >= 0; });
    $('#bulkDelCount').text(BULK_DEL_ROWS.length);
    $('#bulkDelPwd').val('');
    $('#bulkDelErr').empty();
    $('#bulkDelList').html(BULK_DEL_ROWS.map(function(r){
        var who = ft === 'job_desc' ? (esc(r.dept_name)+' / '+esc(r.position_name))
                                    : (esc(r.dept_name)+' / '+esc(r.user_cname)+(r.machine_display_name ? '（'+esc(r.machine_display_name)+'）' : ''));
        return '<div style="font-size:12.5px;padding:2px 0;border-bottom:1px dashed #E8DCC6;">'+who
             + ' ｜ '+dispDate(r.business_date)+' ｜ <span class="st-badge st-'+r.status+'">'+(STATUS_LABEL[r.status]||r.status)+'</span></div>';
    }).join(''));
    openMask('bulkDelMask');
}
function hfSubmitBulkDel(){
    var pwd = $('#bulkDelPwd').val();
    if (!pwd){ $('#bulkDelErr').text('請輸入操作確認密碼'); return; }
    var ids = BULK_DEL_ROWS.map(function(r){ return r.id; });
    if (!ids.length){ $('#bulkDelErr').text('沒有選取任何表單'); return; }
    if (!confirm('確定要刪除這 '+ids.length+' 筆表單嗎？此動作無法復原。')) return;
    ajaxPost('delete_bulk', {ids:JSON.stringify(ids), password:pwd}, function(res){
        if (!res.ok){ $('#bulkDelErr').text(res.error||'刪除失敗'); return; }
        var msg = '已刪除 '+res.deleted+' 筆';
        if (res.errors && res.errors.length) msg += '；' + res.errors.join('；');
        alert(msg);
        closeMask('bulkDelMask');
        loadList(BULK_DEL_TYPE);
    });
}

var AUTO_SIGN_TYPE = null;
var AUTO_SIGN_ROWS = []; // 選取當下的 instance 快照

function mostCommonDate(rows){
    var count = {};
    rows.forEach(function(r){ if (r.business_date) count[r.business_date] = (count[r.business_date]||0)+1; });
    var best = null, bestN = 0;
    Object.keys(count).forEach(function(d){ if (count[d] > bestN){ best = d; bestN = count[d]; } });
    return best || META.today;
}
/** 職能鑑定表沒有帶項目資料（list API 不含items），開窗時先各自補抓一次，跟技能鑑定表一樣改用共用 scoreInputHtml 元件，兩處樣式統一。 */
function openAutoSignModal(ft){
    AUTO_SIGN_TYPE = ft;
    var ids = $('#pane-'+ft+' .auto-ck:checked').map(function(){ return this.value; }).get();
    if (!ids.length){ alert('請先在清單勾選要補簽核的表單'); return; }
    AUTO_SIGN_ROWS = (LISTS[ft]||[]).filter(function(r){ return ids.indexOf(String(r.id)) >= 0; });
    $('#autoSignCount').text(AUTO_SIGN_ROWS.length);
    $('#autoSignPwd').val('');
    $('#autoSignDate').val(mostCommonDate(AUTO_SIGN_ROWS));
    $('#autoSignErr').empty();
    ['fillQGm','fillEGm','fillPGm','fillQMgr','fillEMgr','fillPMgr'].forEach(function(id){ $('#'+id).val(''); });
    $('#autoSignRowsBody').html('<tr><td colspan="5" style="text-align:center;color:#8a6d45;">載入中…</td></tr>');
    openMask('autoSignMask');
    var cpRows = AUTO_SIGN_ROWS.filter(function(r){ return r.form_type === 'competency'; });
    if (!cpRows.length) { renderAutoSignRows(); return; }
    var calls = cpRows.map(function(r){ return $.getJSON(API, {action:'get', id:r.id}); });
    $.when.apply($, calls).always(function(){
        var results = calls.length > 1 ? Array.prototype.slice.call(arguments) : [arguments];
        results.forEach(function(res, i){
            if (res && res[0] && res[0].ok) cpRows[i].items = res[0].instance.items || [];
        });
        renderAutoSignRows();
    });
}
function renderAutoSignRows(){
    var rowsHtml = AUTO_SIGN_ROWS.map(function(r, idx){
        if (r.form_type === 'competency') {
            var items = r.items || [];
            var itemsHtml = items.length ? items.map(function(it, ii){
                var d = it.data || {};
                return '<span style="display:inline-block;margin:2px 10px 2px 0;white-space:nowrap;">'+esc(d.skill_name||('項目'+(ii+1)))
                     + ' 操作'+scoreInputHtml('as-cp-op', d.score_op, ' data-idx="'+idx+'" data-ii="'+ii+'" style="width:34px;height:30px;font-size:12px;"')
                     + ' 異常'+scoreInputHtml('as-cp-ex', d.score_ex, ' data-idx="'+idx+'" data-ii="'+ii+'" style="width:34px;height:30px;font-size:12px;"')
                     + '</span>';
            }).join('') : '<span style="color:#8a6d45;">（無項目）</span>';
            return '<tr data-idx="'+idx+'"><td>'+esc(r.user_cname)+'</td><td>員工職能鑑定表</td><td colspan="3">'+itemsHtml+'</td></tr>';
        }
        if (r.form_type !== 'skill_assess') {
            return '<tr data-idx="'+idx+'"><td>'+esc(r.user_cname)+'</td><td>'+FORM_LABEL[r.form_type]+'（無評分欄，僅簽核）</td><td colspan="3">-</td></tr>';
        }
        function pair(gmCls, mgrCls){
            var mgrHtml = r.confirm_na ? '<span class="na-tag">NA</span>' : scoreInputHtml(mgrCls, null, ' data-idx="'+idx+'"');
            return scoreInputHtml(gmCls, null, ' data-idx="'+idx+'"') + ' / ' + mgrHtml;
        }
        return '<tr data-idx="'+idx+'"><td>'+esc(r.user_cname)+'</td><td>'+machineListCellHtml(r)+'</td>'
             + '<td>'+pair('as-q-gm','as-q-mgr')+'</td><td>'+pair('as-e-gm','as-e-mgr')+'</td><td>'+pair('as-p-gm','as-p-mgr')+'</td></tr>';
    }).join('');
    $('#autoSignRowsBody').html(rowsHtml);
    // 帶入既有分數當預設值（skill_assess 用；competency 已在 scoreInputHtml 產生時直接帶入 d.score_op/ex，不需另外補）
    AUTO_SIGN_ROWS.forEach(function(r, idx){
        if (r.form_type !== 'skill_assess') return;
        var $tr = $('#autoSignRowsBody tr[data-idx="'+idx+'"]');
        $tr.find('.as-q-gm').val(r.score_quality_gm||''); $tr.find('.as-e-gm').val(r.score_efficiency_gm||''); $tr.find('.as-p-gm').val(r.score_proficiency_gm||'');
        if (!r.confirm_na) { $tr.find('.as-q-mgr').val(r.score_quality_mgr||''); $tr.find('.as-e-mgr').val(r.score_efficiency_mgr||''); $tr.find('.as-p-mgr').val(r.score_proficiency_mgr||''); }
    });
}
function hfAutoSignApplyAll(){
    var v = {qGm:$('#fillQGm').val(), eGm:$('#fillEGm').val(), pGm:$('#fillPGm').val(), qMgr:$('#fillQMgr').val(), eMgr:$('#fillEMgr').val(), pMgr:$('#fillPMgr').val()};
    AUTO_SIGN_ROWS.forEach(function(r, idx){
        if (r.form_type !== 'skill_assess') return;
        var $tr = $('#autoSignRowsBody tr[data-idx="'+idx+'"]');
        if (v.qGm) $tr.find('.as-q-gm').val(v.qGm); if (v.eGm) $tr.find('.as-e-gm').val(v.eGm); if (v.pGm) $tr.find('.as-p-gm').val(v.pGm);
        if (!r.confirm_na) { if (v.qMgr) $tr.find('.as-q-mgr').val(v.qMgr); if (v.eMgr) $tr.find('.as-e-mgr').val(v.eMgr); if (v.pMgr) $tr.find('.as-p-mgr').val(v.pMgr); }
    });
}
function hfSubmitAutoSign(){
    var pwd = $('#autoSignPwd').val();
    if (!pwd){ $('#autoSignErr').text('請輸入操作確認密碼'); return; }
    var ids = AUTO_SIGN_ROWS.map(function(r){ return r.id; });
    var scoresByInstance = {};
    var itemsByInstance = {};
    AUTO_SIGN_ROWS.forEach(function(r, idx){
        var $tr = $('#autoSignRowsBody tr[data-idx="'+idx+'"]');
        if (r.form_type === 'skill_assess') {
            scoresByInstance[r.id] = {
                quality_gm: $tr.find('.as-q-gm').val()||null, efficiency_gm: $tr.find('.as-e-gm').val()||null, proficiency_gm: $tr.find('.as-p-gm').val()||null,
                quality_mgr: r.confirm_na ? null : ($tr.find('.as-q-mgr').val()||null),
                efficiency_mgr: r.confirm_na ? null : ($tr.find('.as-e-mgr').val()||null),
                proficiency_mgr: r.confirm_na ? null : ($tr.find('.as-p-mgr').val()||null),
            };
        } else if (r.form_type === 'competency' && r.items && r.items.length) {
            itemsByInstance[r.id] = r.items.map(function(it, ii){
                var d = it.data || {};
                return {id:it.id, item_no:it.item_no, data:{skill_name:d.skill_name||'', score_op:$tr.find('.as-cp-op[data-ii="'+ii+'"]').val()||null, score_ex:$tr.find('.as-cp-ex[data-ii="'+ii+'"]').val()||null}};
            });
        }
    });
    ajaxPost('auto_sign_bulk', {ids:JSON.stringify(ids), password:pwd, sign_date:$('#autoSignDate').val()||META.today, scores_by_instance:JSON.stringify(scoresByInstance), items_by_instance:JSON.stringify(itemsByInstance)}, function(res){
        if (!res.ok){ $('#autoSignErr').text(res.error||'執行失敗'); return; }
        var msg = '已補簽 '+res.done+' 筆';
        if (res.errors && res.errors.length) msg += '；失敗：'+res.errors.join('；');
        alert(msg);
        closeMask('autoSignMask');
        loadList(AUTO_SIGN_TYPE);
    });
}
</script>
<script>
/* ============================================================ 列印（逐筆各自開視窗；多筆時自動分批排隊，比照 type_id_ctrl_doc.php 手法；
   頁碼交給瀏覽器列印引擎算，JS只負責量高度決定要不要注入頁碼CSS，完全不影響分頁本身，只有超過一頁才顯示） ============================================================ */

// 圖章尺寸換算（比照 ai-rules/18 第6條、review_form.php 既有做法，2026-08-13 修正）：
// px = 實際外徑公分 × 96 ÷ 2.54，96px=1英吋=2.54公分；外徑沿用 review_form.php 同一顆公司章的 2.5cm。
var HF_STAMP_PX = (2.5 * 96 / 2.54).toFixed(1);
function hfPrintCss(){
    // 使用者實測：印出圖章比模板設定小，根因是表格內容略寬/略高於A4可印範圍，印表機/瀏覽器整頁等比縮小連圖章一起縮。
    // 對策：*{box-sizing:border-box} 防止padding疊加撐寬；所有表格 table-layout:fixed + word-break，內容一律
    // 只能在欄寬內換行，絕不撐寬版面；寬度不足時交給 openPrintWindow() 的 #pw-shrink 縮文字，不縮圖章。
    // padding/margin 一律用 em（不用px）：#pw-shrink 縮小 font-size 時這些值會跟著等比縮小，縮字才真正有效降低
    // 總高度（技能鑑定考核表比職能鑑定表多一列「平均」+一段說明文字，原本px版縮字對它幾乎沒用，只有這裡是px不隨字縮）。
    return '*{box-sizing:border-box;}'
         + 'table.hf-p-head{width:100%;max-width:100%;border-collapse:collapse;font-size:13px;table-layout:fixed;margin-bottom:0.5em;}'
         + 'table.hf-p-head th{background:#fff;font-weight:bold;border:1px solid #333;padding:0.6em 0.45em;width:90px;text-align:center;word-break:break-word;}'
         + 'table.hf-p-head td{border:1px solid #333;padding:0.6em 0.6em;text-align:left;word-break:break-word;}'
         + 'table.hf-p-items{width:100%;max-width:100%;border-collapse:collapse;font-size:11.5px;table-layout:fixed;margin-top:0.5em;}'
         + 'table.hf-p-items th,table.hf-p-items td{border:1px solid #333;padding:0.7em 0.45em;text-align:center;word-break:break-word;}'
         + 'table.hf-p-items td.t-left{text-align:left;}'
         + 'table.hf-p-foot{width:100%;max-width:100%;table-layout:fixed;margin-top:1.2em;margin-bottom:6mm;font-size:13px;}'
         + 'table.hf-p-foot td{padding:0.45em;width:33.33%;text-align:center;vertical-align:top;}'
         + 'table.hf-p-foot .foot-lbl{margin-bottom:0.3em;}'
         + '.hf-p-note{font-size:11px;color:#333;margin-top:0.6em;line-height:1.5;border:1px solid #ddd;padding:2px 4px;}'
         // 只有「沒有指定圖章模板」時才用換算出的固定尺寸覆蓋；有指定模板時完全尊重模板自己的「大小(px)」，
         // 不要像舊寫法直接選 table.hf-p-foot svg 把所有章(含模板章)一起蓋成固定值（ai-rules/18 第6條）。圖章尺寸
         // 固定用 px !important，不受 #pw-shrink 的 font-size 縮放影響，這是唯一不隨文字縮小的元素。
         + '.hf-stamp-defsize svg{width:'+HF_STAMP_PX+'px !important;height:'+HF_STAMP_PX+'px !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}';
}
/**
 * 找到真正根因（2026-08-17 實測比對）：本頁簽章欄是「有充足空間的簽核欄」，本該用模板設計的實際大小
 * （ai-rules/18 第10條），但範本若指定了帶 fillRatio 的圖章模板（例如「本人簽章(圓)」fillRatio=0.9），
 * eg_stamp.js 的 wrap() 會依 fillRatio 產生 stamp-fill + height:90% 讓圖章自動縮放去填滿所在儲存格高度
 * ——這是設計給「密集逐列表格」（一人一列蓋章）用的機制，本頁誤用同一顆共用範本才被壓小。不動共用圖章
 * 範本本身（其他頁面的密集列表用法仍需要 fillRatio），只在這裡呼叫時強制 noScale，一律用模板設計的
 * 實際大小；沒有自訂模板（schema為null）的情況本來就已經用固定94.5px不受影響。
 */
function stampOrName(name, date, isDeputy, schema){
    var effSchema = schema ? $.extend({}, schema, {noScale:true}) : schema;
    // 圖章上的日期也是「顯示用」日期，一律走 dispDate()→egFmtDate() 轉成 YYYY.MM.DD（ai-rules/20）；
    // 在這裡統一轉，呼叫端仍傳原始 Y-m-d 即可，比照 meeting_record.php／tool_calibration.php 的做法。
    var html = (window.EGStamp && EGStamp.stamp) ? EGStamp.stamp(name, dispDate(date), !!isDeputy, effSchema) : esc(name||'');
    if (!schema) html = '<span class="hf-stamp-defsize">'+html+'</span>';
    return html;
}

function jdPrintHtml(r){
    var h = '<div class="pt-head"><div class="co">'+esc(META.company_name||'')+'</div><div class="tt">職務說明書</div></div>';
    h += '<table class="hf-p-head"><tr><th>所屬部門</th><td>'+esc(r.dept_name||'')+'</td><th>職務名稱</th><td>'+esc(r.position_name||'')+'</td></tr>'
       + '<tr><th>日期</th><td colspan="3">'+dispDate(r.business_date)+'</td></tr></table>';
    h += '<table class="hf-p-items"><thead><tr><th>工作摘要</th><th>工作相關程序書</th><th>產出表單名稱</th><th>DPI 項目（績效標準計算方式）</th></tr></thead><tbody>';
    (r.items||[]).forEach(function(it){
        var d = it.data||{};
        h += '<tr><td class="t-left">'+esc(d.summary||'').replace(/\n/g,'<br>')+'</td><td class="t-left">'+esc(d.process||'').replace(/\n/g,'<br>')+'</td>'
           + '<td class="t-left">'+esc(d.form_name||'').replace(/\n/g,'<br>')+'</td><td class="t-left">'+esc(d.dpi||'').replace(/\n/g,'<br>')+'</td></tr>';
    });
    h += '</tbody></table>';
    return h;
}
function saPrintHtml(r, tpl){
    var h = '<div class="pt-head"><div class="co">'+esc(META.company_name||'')+'</div><div class="tt">專業技能鑑定考核表</div></div>';
    h += '<table class="hf-p-head"><tr><th>單位</th><td>'+esc(r.dept_name||'')+'</td><th>姓名</th><td>'+esc(r.user_cname||'')+'</td></tr>'
       + '<tr><th>機型</th><td>'+esc(machineNameLabel(r))+'</td><th>機台編號</th><td>'+esc(machineAssetNoText(r))+'</td></tr>'
       + '<tr><th>日期</th><td colspan="3">'+dispDate(r.business_date)+'</td></tr></table>';
    var mgrNA = !!r.confirm_na;
    h += '<table class="hf-p-items"><thead><tr><th style="width:25%;">分類項目</th><th>總經理考核</th><th>課長考核</th></tr></thead><tbody>'
       + ['quality','efficiency','proficiency'].map(function(k,i){
           var lbl = ['品質','效率','熟練度'][i];
           return '<tr><td>'+lbl+'</td><td>'+(r['score_'+k+'_gm']??'')+'</td><td>'+(mgrNA?'NA':(r['score_'+k+'_mgr']??''))+'</td></tr>';
         }).join('')
       + '<tr><td>平均</td><td>'+(scoreAvg(r.score_quality_gm,r.score_efficiency_gm,r.score_proficiency_gm)??'')+'</td><td>'+(mgrNA?'NA':(scoreAvg(r.score_quality_mgr,r.score_efficiency_mgr,r.score_proficiency_mgr)??''))+'</td></tr>'
       + '</tbody></table>';
    h += '<div class="hf-p-note">說明：品質：依合格率計算(1分=25%、2分=50%、3分=75%、4分=100%)　效率：依標準工時計算效率(1分60%以下、2分=60~74%、3分=75~84%、4分=85%以上)　熟練度：1分=略、2分=熟、3分=獨立作業、4分=可教學<br>考核分數：1~4分，評分2分以上才合格，總經理、課長均要3分以上才合格。NA表示該員工職等已無中間層可考核。</div>';
    h += printFootHtml(r, tpl);
    return h;
}
function cpPrintHtml(r, tpl){
    var h = '<div class="pt-head"><div class="co">'+esc(META.company_name||'')+'</div><div class="tt">員工職能鑑定表</div></div>';
    h += '<table class="hf-p-head"><tr><th>部門</th><td>'+esc(r.dept_name||'')+'</td><th>員工編號</th><td>'+esc(r.user_no||'')+'</td></tr>'
       + '<tr><th>姓名</th><td>'+esc(r.user_cname||'')+'</td><th>到職日</th><td>'+dispDate(r.onboard_date)+'</td></tr>'
       + '<tr><th>職務</th><td>'+esc(r.position_name||'')+'</td><th>主管</th><td>'+esc(r.supervisor_name||'')+'</td></tr>'
       + '<tr><th>首次登錄<br>日期</th><td>'+dispDate(r.business_date)+'</td><th>最新更新<br>日期</th><td>'+dispDate(r.cp_update_date||r.business_date)+'</td></tr></table>';
    h += '<table class="hf-p-items"><thead><tr><th style="width:50px;">編號</th><th>'+(deptHasSkillAssess(r.dept_id)?'機台設定':'項目名稱')+'</th><th style="width:90px;">操作</th><th style="width:90px;">異常排除</th></tr></thead><tbody>';
    (r.items||[]).forEach(function(it,i){
        var d = it.data||{};
        h += '<tr><td>'+(i+1)+'</td><td class="t-left">'+esc(d.skill_name||'').replace(/\n/g,'<br>')+'</td><td>'+(d.score_op||'')+'</td><td>'+(d.score_ex||'')+'</td></tr>';
    });
    h += '</tbody></table>';
    h += '<div class="hf-p-note">填寫說明：人員依技能項目其純熟度可分為： 1=略(大部分須人員指導)　2=熟(少部分須人員指導)　3=獨立作業　4=可教學。其鑑別方式，由主管依據教育訓練後評鑑方式依職能鑑定考核表確認。</div>';
    h += printFootHtml(r, tpl);
    return h;
}
function printFootHtml(r, tpl){
    var footSchema = tpl && tpl.footer_stamp ? tpl.footer_stamp.schema : null;
    var approveStamp = r.approve_user_name ? stampOrName(r.approve_user_name, r.approve_at?r.approve_at.substr(0,10):r.business_date, false, footSchema) : '';
    var confirmStamp = r.confirm_user_name ? stampOrName(r.confirm_user_name, r.confirm_at?r.confirm_at.substr(0,10):r.business_date, false, footSchema) : '';
    return '<table class="hf-p-foot"><tr>'
         + '<td><div class="foot-lbl">核准</div>'+approveStamp+'</td>'
         + '<td><div class="foot-lbl">確認</div>'+confirmStamp+'</td>'
         + '</tr></table>';
}

/* 原本卡 !META.perms.canAdmin，等於非管理員印表單時永遠拿不到範本設定(含圖章模板)，一律退回系統預設章
   （2026-08-13 使用者回報印出來圖章太小才追出來）。API 端 template_get 動作已同步從 canAdmin 放寬成
   canPrint（見 HrForm_API.php），這裡跟著放寬，寫入(template_save)仍然只有管理員可以，不受影響。 */
function fetchTplForPrint(r, cb){
    if (!r.template_id || !(META.perms.canPrint || META.perms.canAdmin)) { cb(null); return; }
    // 加時間戳防瀏覽器快取這個GET請求：管理員在「圖章管理」改了模板的fillRatio/noScale後，
    // 沒有防快取參數的話同一個 template_id 查詢可能吃到舊回應，印出來的圖章設定跟資料庫現況對不上。
    $.getJSON(API, {action:'template_get', id:r.template_id, _:Date.now()}, function(res){ cb(res.ok ? res.template : null); });
}

/** 開單一份文件的列印視窗；zero-JS版面計算鐵則：分頁100%交給列印引擎，JS只決定要不要插入頁碼CSS。
 * 圖章實際印出比模板設定值小的根因是印表機/瀏覽器在內容略超出一頁時自動「縮放至頁面」整頁等比縮小
 * （連圖章SVG一起縮），我方頁面本身沒有下 zoom/transform（ai-rules/18第8條已確認）。對策：內容包一層
 * #pw-shrink，超出一頁高度時只調整這層的 font-size（純文字縮小），圖章 SVG 尺寸是獨立 px !important，
 * 不受 font-size 影響，藉此讓整頁在 100% 列印比例下就能塞進一頁，不必依賴印表機自己觸發整頁縮放。
 * 縮到底仍塞不下（如職務說明書項目很多本來就會超過一頁）就放棄，交還瀏覽器原生分頁，不勉強壓字到不可讀。 */
function openPrintWindow(title, bodyHtml, docNo){
    var asCss = String(docNo||'').replace(/['\\]/g,'');
    var css = '@page{size:A4 portrait;margin:12mm 8mm 16mm;}'
            + 'html,body{margin:0;padding:0;width:194mm;max-width:194mm;overflow-x:hidden;}'
            + 'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            + '.pt-head{text-align:center;margin-bottom:6px;word-break:break-word;}.pt-head .co{font-size:22px;font-weight:bold;letter-spacing:2px;}.pt-head .tt{font-size:16px;font-weight:bold;margin-top:3px;letter-spacing:1px;}'
            + '.hf-as-doc{position:fixed;right:8mm;bottom:6mm;font-size:9pt;color:#333;}'
            // 使用者實測技能鑑定考核表圖章仍偏小、職能鑑定表已正常：兩者共用同一顆圖章模板，差異在
            // 技能鑑定表的說明文字(.hf-p-note)有多段「中文+數字+%」不易斷行的片段，之前只給表格
            // 加了 word-break，這段自由文字沒加到，可能是仍偶發撐寬的根因，這裡補齊、範圍擴大到整個
            // #pw-shrink（所有子元素一律禁止撐寬，寧可斷行/多印一頁，也不讓寬度有機會超出A4）。
            + '#pw-shrink{font-size:100%;width:100%;max-width:100%;word-break:break-word;overflow-wrap:break-word;}'
            + '#pw-shrink *{max-width:100%;word-break:break-word;overflow-wrap:break-word;}'
            + hfPrintCss();
    var w = window.open('', '_blank');
    if (!w){ alert('請允許彈出視窗'); return null; }
    // <!DOCTYPE html> 不可省略：少了它視窗會落入 Quirks Mode，scrollHeight 量出來永遠接近視窗高度而非實際內容
    // 高度，單頁判斷會失準導致只有一頁也印「第1頁/共1頁」（2026-08-14 比照 review_form.php 同批修正）。
    w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>'+esc(title)+'</title><style>'+css+'</style></head><body>'
        + '<div id="pw-shrink">' + bodyHtml + '</div>'
        + (asCss ? '<div class="hf-as-doc">'+asCss+'</div>' : '')
        + '<scr'+'ipt>window.onload=function(){'
        + 'var onePageA4=(297-28)*96/25.4;'
        + 'var wrap=document.getElementById("pw-shrink");'
        + 'var pct=100;'
        + 'while(document.body.scrollHeight>onePageA4*0.98 && pct>60){'
        + 'pct-=3; wrap.style.fontSize=pct+"%";}'
        + 'if(document.body.scrollHeight>onePageA4*0.92){'
        + 'var st=document.createElement("style");'
        + 'st.textContent="@page{ @bottom-left{ content: \'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; } }";'
        + 'document.head.appendChild(st);}'
        + 'setTimeout(function(){window.print();},300);};</scr'+'ipt></body></html>');
    w.document.close();
    return w;
}

/** 單一表單列印（也是「列印全部」批次排隊呼叫的基礎單位，onDone 給排隊機制用）。 */
function printDoc(ft, id, onDone){
    $.getJSON(API, {action:'get', id:id}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); if (onDone) onDone(); return; }
        var r = res.instance;
        // 頁尾 AS 文件編號直接用 get 回傳的 as_doc_no：已含版次、且版次是依這張表單的業務日期回推
        // （ai-rules/16 第三之四節）。原本另外呼叫 asdoc_get 只取 doc_no，改版成 A 版後仍印無版次的舊編號。
        var docNo = res.as_doc_no || '';
        fetchTplForPrint(r, function(tpl){
            var body = ft === 'job_desc' ? jdPrintHtml(r) : (ft === 'skill_assess' ? saPrintHtml(r, tpl) : cpPrintHtml(r, tpl));
            var titleWho = ft === 'job_desc' ? (r.dept_name+'-'+r.position_name) : r.user_cname;
            openPrintWindow(FORM_LABEL[ft]+' - '+titleWho, body, docNo);
            if (onDone) setTimeout(onDone, 500);
        });
    });
}
function printOne(ft, id){ printDoc(ft, id); }

/** 列印全部：依目前分頁籤的篩選結果（不受分頁限制），逐筆各自開視窗、各自獨立分頁計算頁碼；
 *  一張表單＝一份獨立文件＝一頁（結構上都在一頁內），絕對不會合併計算頁碼。比照型態識別文件管制表的做法。 */
var PRINT_ALL_BATCH_THRESHOLD = 15;
function printAll(ft){
    var ids = filteredRows(ft).map(function(r){ return r.id; });
    if (!ids.length){ alert('目前沒有可列印的資料'); return; }
    if (ids.length > PRINT_ALL_BATCH_THRESHOLD) {
        if (!confirm('目前共 '+ids.length+' 筆，數量較多，一次列印可能造成瀏覽器負擔。\n是否改為自動分批列印（依序逐筆觸發，不需手動操作）？\n（若瀏覽器跳出「已封鎖快顯視窗」提示，請允許本頁彈出視窗）')) return;
    }
    var idx = 0;
    function next(){
        if (idx >= ids.length){ alert('已完成列印 '+ids.length+' 筆。'); return; }
        printDoc(ft, ids[idx++], next);
    }
    next();
}

loadMeta(function(){ loadList('job_desc'); });
</script>
</body>
</html>
