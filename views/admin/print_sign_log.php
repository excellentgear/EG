<?php
/**
 * 列印與簽核紀錄（2026-08-21 使用者明確要求）
 *
 * 分頁一「列印紀錄」：誰、何時、在哪台電腦、印了哪份文件。
 *   來源＝新式 print_log（附件列印：料號主檔／BOM檢視器／料號檢視器／批圖編輯器）
 *       ＋既有各模組的列印紀錄表（報價單／PFMEA／產品開發評估表／文件制修申請單／表單簽核案件）。
 * 分頁二「簽核紀錄」：文件名稱、送件日期、簽核人、簽核日期時間、結果（許可／不許可／待簽核）與回覆。
 *   來源＝全站共用的 approval_record（含自動產生的簽核紀錄）。
 *   ※ 依使用者要求，畫面上一律不出現「自動簽核」字樣。
 *
 * 全部邏輯在 src/common/print_log_lib.php，資料一律走 src/store/PrintSignLog_API.php。
 * 權限：roles module='print_sign_log' → psl_admin(管理)／psl_view_all(查全部)；
 *       沒有角色的人只看得到自己的紀錄（後端強制綁 user_id，不靠前端）。
 * 規則見 ai-rules/23-列印與簽核紀錄.md。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/admin/print_sign_log.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/print_log_lib.php';

$db = (new DBConnection())->getPDO();
$u  = eg_printlog_current_user($db);
$perms = eg_printlog_perms($db, $u);

$roleLabel = $perms['isAdmin'] ? '管理者'
           : ($perms['canAdmin'] ? '紀錄管理' : ($perms['canViewAll'] ? '紀錄檢閱' : '僅本人紀錄'));

// 列印大標題的公司全名：一律取主檔設為「本公司」的那筆，禁寫死（ai-rules/16）
$companyName = '';
try {
    $st = $db->query("SELECT customer_full, customer FROM customer_list WHERE is_own_company=1 LIMIT 1");
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) $companyName = trim((string)($row['customer_full'] ?: $row['customer']));
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>列印與簽核紀錄</title>
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
        .help-doc ul { margin:4px 0 8px; padding-left:20px; }
        .help-doc li { margin:2px 0; }
        .help-doc .tip { background:#FFF7E8; border:1px dashed #F0A24B; border-radius:6px; padding:6px 10px; margin:6px 0; }
        .cov-box { border:1px solid #E8D5B5; border-radius:6px; padding:6px 10px; margin:4px 0 10px; background:#FDF8EF; max-height:230px; overflow-y:auto; }
        .cov-box ul { margin:2px 0; padding-left:18px; }
        .cov-yes { color:#5b7a2b; font-weight:bold; }
        .cov-no  { color:#C0563A; font-weight:bold; }
        .cov-note { font-size:12px; color:#8a6d45; }
        .ps-toolbar { clear:both; border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px;
            margin-bottom:10px; background:#FDF8EF; }
        /* 每一列各自 flex：日期區間才不會被自動換行拆成「起日在上一列、迄日在下一列」 */
        .ps-row { display:flex; flex-wrap:wrap; gap:6px 10px; align-items:center; }
        .ps-row + .ps-row { margin-top:8px; padding-top:8px; border-top:1px dashed #E8D5B5; }
        .ps-date { display:inline-flex; align-items:center; gap:6px; white-space:nowrap; }
        .ps-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .ps-toolbar select, .ps-toolbar button, .ps-toolbar input[type=date], .ps-toolbar input[type=text] {
            height:30px; font-size:13px; line-height:1; padding:0 8px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; }
        .ps-toolbar button { cursor:pointer; padding:0 12px; }
        .ps-toolbar button:hover { background:#F7E0BD; }
        .ps-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .ps-toolbar .btn-warm:hover { background:#d98a33; }
        .ps-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }
        .ps-role-badge .fa-question-circle { cursor:pointer; color:#b5762a; margin-left:5px; }
        .ps-tabs { display:flex; gap:4px; margin-bottom:8px; border-bottom:2px solid #E8D5B5; clear:both; }
        .ps-tab { border:1px solid #E8D5B5; border-bottom:none; background:#FBF3E5; color:#8a6d45; cursor:pointer;
            padding:7px 16px; font-size:14px; border-radius:6px 6px 0 0; margin-bottom:-2px; }
        .ps-tab.active { background:#fff; color:#5b3a1e; font-weight:bold; border-bottom:2px solid #fff; }
        .ps-cnt { display:inline-block; min-width:18px; padding:0 5px; margin-left:4px; border-radius:9px;
            background:#F0A24B; color:#fff; font-size:11px; line-height:16px; }
        .ps-cnt.zero { background:#E8D5B5; color:#8a6d45; }
        .ps-pagebar { display:flex; justify-content:flex-end; align-items:center; gap:6px; margin-bottom:6px; font-size:13px; color:#5b3a1e; }
        .ps-pagebar select { height:26px; font-size:12px; border:1px solid #D8BE93; border-radius:4px; }
        .ps-pagebar button { height:26px; font-size:12px; padding:0 8px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .ps-pagebar button:disabled { color:#c9bda9; cursor:default; }
        .ps-pagebar button.cur { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .ps-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.ps-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.ps-table th, table.ps-table td { border:1px solid #EADFC8; padding:5px 8px; text-align:center; }
        table.ps-table thead th { background:#F7E0BD; color:#5b3a1e; font-weight:bold; white-space:nowrap; }
        table.ps-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.ps-table tbody tr:hover { background:#FBF0DD; }
        table.ps-table td.t-left { text-align:left; word-break:break-all; }
        /* 月統計矩陣：列＝展開維度、欄＝1~12 月，最後一列與最後一欄是合計 */
        table.ps-table td.t-dim { text-align:left; font-weight:bold; white-space:nowrap; }
        table.ps-table td.st-zero { color:#CFC3AE; }
        table.ps-table td.st-sum, table.ps-table th.st-sum { background:#FBF0DD; font-weight:bold; }
        table.ps-table tr.st-foot td { background:#F7E0BD; font-weight:bold; }
        table.ps-table .st-rate { display:block; font-size:11px; color:#8a6d45; }
        table.ps-table .st-rate.bad { color:#DD5138; font-weight:bold; }
        .ps-stat-hint { font-size:12px; color:#8a6d45; margin:0 0 6px; }
        .src-pill { display:inline-block; font-size:11px; border-radius:10px; padding:1px 8px; background:#F7E0BD; color:#7a5217; white-space:nowrap; }
        .src-pill.legacy { background:#FFF3E2; color:#C77C1A; border:1px solid #E4D3BC; }
        .res-pill { display:inline-block; font-size:11px; border-radius:10px; padding:1px 10px; white-space:nowrap; font-weight:bold; }
        .res-ok   { background:#F0A24B; color:#fff; }
        .res-no   { background:#DD5138; color:#fff; }
        .res-wait { background:#F5EFE3; color:#8a6d45; border:1px solid #E8D5B5; }
        .ps-host { font-family:Consolas,monospace; font-size:12px; color:#5b3a1e; }
        .ps-host small { color:#a08c6a; }
        .ps-empty { padding:26px; text-align:center; color:#8a6d45; }
        .ps-noperm { margin:40px auto; max-width:520px; text-align:center; border:1.5px solid #E8D5B5; border-radius:10px;
            padding:30px; background:#FDF8EF; color:#5b3a1e; }
        .ps-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .ps-modal { background:#fff; border-radius:8px; max-width:820px; margin:36px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:90vh; display:flex; flex-direction:column; }
        .ps-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .ps-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .ps-modal .m-body { padding:15px; overflow-y:auto; font-size:13px; color:#5b3a1e; }
        .ps-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .ps-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer;
            background:#F0A24B; color:#fff; }
        .ps-scroll-top { display:none; position:fixed; right:24px; bottom:28px; z-index:900; width:42px; height:42px;
            border-radius:50%; border:1px solid #d98a33; background:#F0A24B; color:#fff; font-size:17px; cursor:pointer; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h3 style="margin:0;font-size:20px;color:#5b3a1e;">
                <i class="fa fa-history" style="color:#F0A24B;"></i> 列印與簽核紀錄
            </h3>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>

        <div class="ps-tabs">
            <div class="ps-tab active" data-tab="print"><i class="fa fa-print"></i> 列印紀錄<span class="ps-cnt zero" id="cntPrint">0</span></div>
            <div class="ps-tab" data-tab="sign"><i class="fa fa-check-square-o"></i> 簽核紀錄<span class="ps-cnt zero" id="cntSign">0</span></div>
            <div class="ps-tab" data-tab="qcop"><i class="fa fa-flask"></i> 檢驗作業<span class="ps-cnt zero" id="cntQcop">0</span></div>
            <div class="ps-tab" data-tab="qcstat"><i class="fa fa-bar-chart"></i> 月統計</div>
        </div>

        <div class="ps-toolbar">
            <!-- 第一列：下拉篩選（選了就即時查，不必按查詢） -->
            <div class="ps-row">
                <span id="wrapSource"><label>資料來源</label>
                <select id="fSource" data-eg-filter="輸入來源名稱篩選…" style="min-width:190px;"><option value="">全部</option></select></span>

                <span id="wrapKind" style="display:none;"><label>事件種類</label>
                <select id="fKind" style="min-width:150px;"><option value="">全部</option></select></span>

                <span id="wrapStat" style="display:none;"><label>年度</label>
                <select id="fYear" style="min-width:90px;"></select>
                <label>統計指標</label>
                <select id="fMetric" style="min-width:160px;"></select>
                <label>展開</label>
                <select id="fDim" style="min-width:150px;"></select></span>

                <label id="lblPerson">列印人</label>
                <select id="fUser" data-eg-filter="輸入姓名篩選…" style="min-width:190px;"><option value="">全部</option></select>

                <span class="ps-role-badge">目前身分：<b id="roleName"><?= htmlspecialchars($roleLabel) ?></b><i class="fa fa-question-circle" id="btnRoleHelp" title="各角色權限說明"></i></span>
            </div>

            <!-- 第二列：日期區間整組不拆行＋關鍵字＋動作鈕 -->
            <div class="ps-row">
                <span class="ps-date" id="wrapDate"><label>日期</label><input type="date" id="fFrom"> ～ <input type="date" id="fTo"></span>

                <span id="wrapProc" style="display:none;"><label>製程</label>
                <select id="fProc" data-eg-filter="輸入製程名稱篩選…" style="min-width:150px;"><option value="">全部</option></select></span>

                <span id="wrapMaker" style="display:none;"><label>廠商</label>
                <select id="fMaker" data-eg-filter="輸入廠商名稱篩選…" style="min-width:150px;"><option value="">全部</option></select></span>

                <input type="text" id="fKw" placeholder="文件名稱／料號／姓名…（邊打邊查）" style="min-width:220px;">

                <button id="btnReset"><i class="fa fa-refresh"></i> 本月</button>
                <button class="btn-warm" id="btnPrintAll"><i class="fa fa-print"></i> 列印全部篩選結果</button>
                <button id="btnRevealNote" style="display:none;" title="系統內部註記預設不顯示，要看必須輸入操作確認密碼"><i class="fa fa-lock"></i> 顯示內部註記</button>
                <label id="lblIncDel" style="display:none;margin:0 0 0 6px;font-weight:normal;white-space:nowrap;cursor:pointer;"
                       title="原始單據已經被刪掉的簽核紀錄，預設不列出（文件名稱只剩編號，看不出印的是什麼）。要查歷史才勾。">
                    <input type="checkbox" id="fIncDel" style="vertical-align:-1px;"> 含已刪除單據
                </label>
            </div>
        </div>

        <div class="ps-pagebar">
            <span id="pgInfo"></span>
            <label style="margin:0 2px 0 8px;">每頁</label>
            <select id="fPer"><option>5</option><option selected>10</option><option>20</option><option>50</option></select>
            <span id="pgBtns"></span>
        </div>

        <div class="ps-stat-hint" id="statHint" style="display:none;"></div>

        <div class="ps-table-wrap">
            <table class="ps-table">
                <thead id="tHead"></thead>
                <tbody id="tBody"></tbody>
            </table>
        </div>
        <div class="ps-empty" id="emptyBox" style="display:none;">沒有符合條件的紀錄</div>

    </div>
</div>
</div>

<button class="ps-scroll-top" id="btnTop" title="回到頂端"><i class="fa fa-arrow-up"></i></button>

<?php /* 內部註記解除遮蔽：管理員＋操作確認密碼。規則見 ai-rules/23「自動簽核／補簽核字樣一律不得外露」。
         這段刻意用 PHP 註解不用 HTML 註解——HTML 註解會原樣送到瀏覽器，view-source 就讀得到。 */ ?>
<div class="modal fade" id="revealMask" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-sm"><div class="modal-content">
    <div class="modal-header" style="background:#FFF8EE;border-bottom:1px solid #E4D3BC;">
      <button type="button" class="close" data-dismiss="modal">&times;</button>
      <h4 class="modal-title" style="color:#4A3524;"><i class="fa fa-lock"></i> 顯示內部註記</h4>
    </div>
    <div class="modal-body">
      <div style="font-size:12px;color:#8a6d45;margin-bottom:8px;">
        系統寫入的內部註記平常一律不顯示。輸入您的<b>操作確認密碼</b>後，
        本次登入可在畫面上看到 10 分鐘；<b>列印與匯出一律仍然遮蔽</b>。
      </div>
      <input type="password" class="form-control input-sm" id="revealPw" autocomplete="off" placeholder="操作確認密碼">
      <div id="revealErr" style="color:#DD5138;font-size:12px;margin-top:5px;display:none;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-default btn-sm" data-dismiss="modal">取消</button>
      <button class="btn btn-warning btn-sm" id="revealOk">確認</button>
    </div>
  </div></div>
</div>

<!-- 使用說明 -->
<div class="ps-mask" id="helpUseMask"><div class="ps-modal">
    <div class="m-head"><span><i class="fa fa-question-circle"></i> 作業紀錄（列印／簽核／檢驗） 使用說明</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>這個頁面在做什麼</h4>
        <ul>
            <li><b>列印紀錄</b>：誰、什麼時候、在哪一台電腦、印了哪一份文件。附件列印會一併記下相關料號。</li>
            <li><b>簽核紀錄</b>：文件名稱、送件日期、簽核人、簽核日期時間、結果（許可／不許可／待簽核）與簽核意見。</li>
            <li><b>檢驗作業</b>：線上檢驗相關的四種動作——<b>按下完成</b>（QC 待驗清單按「完成」）、<b>送出檢驗單</b>、
                <b>修改檢驗單</b>（已送出的事後被改）、<b>未送出草稿</b>（填到一半自動保存、一直沒送出的）。
                每一列都看得到時間、製令(BOM)、料號、客戶、製程、廠商、判定與操作人。</li>
            <li><b>月統計</b>：把檢驗作業依<b>年度</b>攤成「一列一個對象、一欄一個月份」的統計表，
                可選統計<b>完工筆數／檢驗單送出筆數／檢驗合格率／未送出草稿筆數</b>，並依<b>人員／製程／廠商</b>展開。</li>
        </ul>

        <h4>檢驗作業與月統計（2026-08-27 新增）</h4>
        <ul>
            <li><b>資料是本來就存在的，這裡只是讓它看得到</b>：完工的人與時間存在製令的製程資料上，
                檢驗單與草稿的建立人／最後修改人存在檢驗表單上。本頁只讀取與彙總，<b>不會另外再記一份</b>
                （依 ai-rules/23：禁止各模組各自再開一張紀錄表）。</li>
            <li><b>檢驗作業</b>分頁的篩選：<b>事件種類</b>（四種動作）、<b>製程</b>、<b>廠商</b>、<b>操作人</b>、<b>日期區間</b>、關鍵字
                （製令／料號／客戶／製程／廠商／姓名都會掃）。製程與廠商的下拉一樣只列這個區間內真的有資料的。</li>
            <li><b>月統計</b>分頁改用<b>年度</b>而不是日期區間（整年才看得出月份趨勢），所以切到這個分頁時日期欄會收起來；
                操作人／製程／廠商／關鍵字這幾個條件仍然有效。</li>
            <li><b>合格率</b>那個指標，每一格顯示「<b>合格數／總數</b>」與百分比，未滿 100% 會用紅字標出來。</li>
            <li>統計表的列會依<b>全年合計由多到少</b>排序，一眼看得出哪個人／哪個製程／哪家廠商量最大；
                最後一列與最後一欄是合計。</li>
            <li><b>未送出草稿</b>要特別看：草稿是每個人各自一份、只有本人載得回，
                量一多通常代表有人填到一半就離開、該批檢驗其實沒有完成。</li>
            <li><b>列印全部篩選結果</b>在這兩個分頁一樣可用：檢驗作業印的是全部符合條件的資料（不是只有這一頁），
                月統計印的是目前這張矩陣。</li>
        </ul>

        <h4>操作步驟</h4>
        <ul>
            <li>上方分頁切換「列印紀錄／簽核紀錄／檢驗作業／月統計」，共用同一組篩選條件；
                各分頁專屬的欄位（資料來源、事件種類、製程、廠商、年度與統計指標）會依分頁自動出現或收起。</li>
            <li>篩選：<b>資料來源</b>（列印分頁是頁面／模組，簽核分頁是單據種類）、<b>列印人／簽核人</b>、<b>日期區間</b>，另可用關鍵字查文件名稱、料號、姓名。</li>
            <li><b>全部篩選都是即時的</b>：下拉一選、日期一改、關鍵字邊打就邊查，不需要按任何查詢按鈕。</li>
            <li><b>資料來源與列印人／簽核人的下拉，只列這個日期區間內真的有紀錄的項目</b>——選下去是 0 筆的選項不會出現。
                改日期或切分頁時選項會跟著重算。若你已經選了某一項、換區間後它沒資料，它仍會留在清單裡並標示「（此區間無資料）」，
                不會憑空消失把你的篩選條件默默改掉。</li>
            <li>下拉選項多時可直接在篩選框打字過濾，不必用眼睛找。</li>
            <li><b>列印全部篩選結果</b>：印的是目前篩選條件下的<b>全部</b>資料，不是只有畫面這一頁。</li>
            <li><b>含已刪除單據</b>（簽核分頁）：原始單據已經被刪掉的簽核紀錄<b>預設不列出</b>——文件名稱只剩一個編號，
                看不出是什麼文件，留在清單上只是雜訊。紀錄本身沒有刪，要查歷史時把這個勾起來就會一起列出（並標示「單據已刪除」）。</li>
            <li><b>顯示內部註記</b>（僅管理員，簽核分頁）：部分簽核意見是系統寫的<b>內部註記</b>，平常一律不顯示，
                意見欄會是「—」。要查的話按這顆按鈕並輸入<b>操作確認密碼</b>，本次登入可在畫面上看 10 分鐘。</li>
            <li>清單分頁在表格右上角，預設每頁 10 筆（可改 5／20／50）；改成每頁超過 10 筆時，右下角會出現「回到頂端」按鈕。</li>
        </ul>

        <h4>重要行為／常見疑問</h4>
        <div class="tip">
            <b>「按下列印」就會留紀錄</b>：瀏覽器不會告訴系統使用者在列印對話框最後是按了確定還是取消，
            所以按取消也一樣留一筆。這是刻意的——否則按取消就能規避紀錄。
        </div>
        <div class="tip">
            <b>簽核意見顯示「—」不是壞掉</b>：那一筆的意見是系統寫的<b>內部註記</b>，
            <b>一律不顯示在畫面上，列印與匯出也永遠不會印出來</b>。
            紀錄本身有留（可追溯性），只是不對外顯示；管理員要查請用工具列的「顯示內部註記」。
        </div>
        <ul>
            <li><b>登入電腦</b>：顯示電腦名稱與 IP。電腦名稱由 IP 反查而來（內網 NetBIOS），查不到時只顯示 IP。</li>
            <li><b>預設區間</b>：每次進來預設顯示<b>本月</b>資料；按「本月」可隨時回到預設。</li>
            <li><b>舊的列印紀錄</b>（報價單、PFMEA 等模組本來就有的）沒有 IP 與電腦名稱欄位，那兩欄會顯示「—」。</li>
            <li><b>簽核紀錄的日期區間</b>：送件日期或簽核日期任一落在區間內都會列出。</li>
            <li>沒有被指派角色的人，只看得到<b>自己</b>的紀錄（這是後端擋的，不是只有畫面隱藏）。</li>
        </ul>

        <h4>目前涵蓋哪些表單（即時掃描，不是固定清單）</h4>
        <p class="cov-note">以下由系統當場掃描產生：列印是掃各頁面實際有沒有接上紀錄程式，簽核是查資料庫裡實際的簽核資料表，所以有人新增頁面或模組時這份清單會自己更新。</p>
        <div id="covBox" class="cov-box">載入中…</div>

        <h4>設定入口與權限角色</h4>
        <ul>
            <li>角色指派：<b>系統設定 → 使用者權限（user_permissions.php）</b>的「列印與簽核紀錄」區塊。</li>
            <li><b>紀錄管理（psl_admin）</b>：查全部紀錄、列印匯出。</li>
            <li><b>紀錄檢閱（psl_view_all）</b>：查全部紀錄（唯讀）。</li>
            <li><b>未指派角色</b>：只能查自己的紀錄。管理者固定擁有全部權限。</li>
        </ul>
    </div>
    <div class="m-foot"><button onclick="closeMask('helpUseMask')">我知道了</button></div>
</div></div>

<!-- 角色說明 -->
<div class="ps-mask" id="roleHelpMask"><div class="ps-modal" style="max-width:560px;">
    <div class="m-head"><span><i class="fa fa-users"></i> 角色權限說明</span><span class="m-close" onclick="closeMask('roleHelpMask')">✕</span></div>
    <div class="m-body" id="roleHelpBody">載入中…</div>
    <div class="m-foot"><button onclick="closeMask('roleHelpMask')">關閉</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});

var API = '../../src/store/PrintSignLog_API.php';
var COMPANY = <?= json_encode($companyName, JSON_UNESCAPED_UNICODE) ?>;
var META = null;
var META_QC = null;          // 檢驗作業／月統計分頁的篩選選項（跟著日期區間走）
var LASTSTAT = null;         // 月統計最後一次的結果（列印用）
var TAB  = 'print';
var PAGE = 1;
var LAST = { rows: [], total: 0 };

function esc(s){ return $('<div>').text(s == null ? '' : String(s)).html(); }
// 顯示用日期一律 YYYY.MM.DD（ai-rules/20，唯一實作在 eg_date_fmt.js）
function dispDate(d){ return egFmtDate(d); }
function dispDateTime(d){ return egFmtDate(d, true); }
function openMask(id){ document.getElementById(id).style.display='block'; }
function closeMask(id){ document.getElementById(id).style.display='none'; }

// 本月：1 號 ~ 今天
function monthRange(){
    var d = new Date(), p = function(n){ return String(n).padStart(2,'0'); };
    var y = d.getFullYear(), m = p(d.getMonth()+1);
    return { from: y+'-'+m+'-01', to: y+'-'+m+'-'+p(d.getDate()) };
}
function resetRange(){
    var r = monthRange();
    $('#fFrom').val(r.from); $('#fTo').val(r.to);
}

/** $tab 省略＝目前分頁。算「別的分頁有幾筆」時要用別的分頁的條件，所以要能指定。 */
function filters(extra, tab){
    tab = tab || TAB;
    var f = {
        user_id  : $('#fUser').val() || '',
        date_from: $('#fFrom').val() || '',
        date_to  : $('#fTo').val() || '',
        kw       : $.trim($('#fKw').val() || ''),
        per      : $('#fPer').val() || 10,
        page     : PAGE
    };
    // 原始單據已刪除的簽核紀錄預設不列出（使用者要求），勾了才含進來
    if (tab === 'sign' && $('#fIncDel').prop('checked')) f.include_deleted = 1;
    // 「來源」欄位在每個分頁是不同的東西，各自帶各自的參數
    if (tab === 'print') f.source = $('#fSource').val() || '';
    else if (tab === 'sign') f.module = $('#fSource').val() || '';
    else {                                   // qcop / qcstat
        f.kind    = (tab === 'qcop') ? ($('#fKind').val() || '') : '';
        f.process = $('#fProc').val()  || '';
        f.maker   = $('#fMaker').val() || '';
        if (tab === 'qcstat') {
            f.year   = $('#fYear').val()   || '';
            f.metric = $('#fMetric').val() || '';
            f.dim    = $('#fDim').val()    || '';
            delete f.date_from; delete f.date_to;   // 月統計以整年為單位，日期區間不適用
        }
    }
    return $.extend(f, extra || {});
}

// ── 來源下拉依分頁重建 ──────────────────────────────────────────────────
// 選項只列「目前日期區間內真的有紀錄」的（使用者要求）：選了 8 月卻列出一堆選下去是 0 筆的來源，
// 那個下拉等於在騙人。**目前選中的值例外**——就算它在新區間裡沒資料也要留著，
// 否則選項一消失，select 會掉回「全部」，篩選條件被默默改掉、畫面上的筆數也跟著變。
function fillSourceSel(){
    var list = (TAB === 'print') ? (META.sources || []) : (META.modules || []);
    var cur  = $('#fSource').val() || '';
    var $s = $('#fSource').html('<option value="">全部</option>');
    var seen = false;
    list.forEach(function(x){
        if (String(x.code) === String(cur)) seen = true;
        $s.append('<option value="'+esc(x.code)+'">'+esc(x.label)+'</option>');
    });
    if (cur && !seen) $s.append('<option value="'+esc(cur)+'">'+esc(curSourceLabel(cur))+'（此區間無資料）</option>');
    $s.val(cur);
    // 人員欄的標題統一由 syncToolbar() 決定（分頁已經不只兩個，寫在這裡會蓋掉它算好的結果）
}

/** 已選但這個區間沒資料的來源，名稱要從完整登錄表拿，不然只剩一個看不懂的代碼 */
function curSourceLabel(code){
    var all = (TAB === 'print') ? (META.all_sources || []) : (META.all_modules || []);
    for (var i = 0; i < all.length; i++) if (String(all[i].code) === String(code)) return all[i].label;
    return code;
}

function fillPeopleSel(){
    var cur = $('#fUser').val() || '';
    var $s = $('#fUser').html('<option value="">全部</option>');
    if (!META.perms.canViewAll) {
        // 只能看自己的人不給選別人（後端也擋），下拉直接鎖成本人
        $s.html('<option value="'+META.me.id+'">'+esc(META.me.name)+'（本人）</option>');
        $s.prop('disabled', true);
        return;
    }
    var seen = false;
    (META.people || []).forEach(function(p){
        // 這一頁的人只列該分頁真的有紀錄的（列印分頁看列印人、簽核分頁看簽核人）
        if (TAB === 'print' ? !Number(p.in_print) : !Number(p.in_sign)) return;
        if (String(p.id) === String(cur)) seen = true;
        // 欄位順序固定「部門／職稱／姓名」（人員列表鐵則）
        var t = [p.dept_name || '－', p.position_name || '－', p.user_cname].join('　');
        if (String(p.state) === '0') t += '（已離職）';
        $s.append('<option value="'+p.id+'">'+esc(t)+'</option>');
    });
    if (cur && !seen) {
        var nm = cur;
        (META.people || []).forEach(function(p){ if (String(p.id) === String(cur)) nm = p.user_cname; });
        $s.append('<option value="'+esc(cur)+'">'+esc(nm)+'（此區間無資料）</option>');
    }
    $s.val(cur);
}

/** 日期區間改變／切分頁時重載下拉選項（選項是跟著區間走的，不能只載一次） */
function reloadMeta(cb){
    $.get(API, { action:'meta', date_from: $('#fFrom').val() || '', date_to: $('#fTo').val() || '' }, function(res){
        if (res && res.ok) {
            // 完整登錄表只在第一次拿（用來把「已選但無資料」那一筆的名稱顯示出來）
            if (META && META.all_sources) { res.all_sources = META.all_sources; res.all_modules = META.all_modules; }
            META = res;
            fillSourceSel();
            fillPeopleSel();
        }
        if (cb) cb();
    }, 'json').fail(function(){ if (cb) cb(); });
}

// 內部註記目前是不是已解除遮蔽（由 list_sign 回傳，權限與時效都在後端判定，前端只是照著顯示）
var NOTE_REVEALED = false;

// ── 清單 ────────────────────────────────────────────────────────────────
var HEAD_PRINT = ['列印時間','資料來源','文件名稱','相關料號','列印人','登入電腦','備註'];
var HEAD_SIGN  = ['文件名稱','單據種類','送件日期','送件人','簽核關卡','簽核人','簽核日期時間','結果','回覆意見'];
var HEAD_QCOP  = ['時間','動作','製令(BOM)','料號','客戶','製程','廠商','判定','操作人'];
var HEADS      = { print: HEAD_PRINT, sign: HEAD_SIGN, qcop: HEAD_QCOP };

function renderHead(){
    var cols = HEADS[TAB] || HEAD_PRINT;
    $('#tHead').html('<tr>' + cols.map(function(c){ return '<th>'+esc(c)+'</th>'; }).join('') + '</tr>');
}

function rowHtmlPrint(r){
    var host = r.client_host ? esc(r.client_host) + (r.client_ip ? ' <small>('+esc(r.client_ip)+')</small>' : '')
             : (r.client_ip ? esc(r.client_ip) : '—');
    return '<tr>'
        + '<td style="white-space:nowrap;">' + esc(dispDateTime(r.printed_at)) + '</td>'
        + '<td><span class="src-pill' + (r.client_ip ? '' : ' legacy') + '">' + esc(r.source_label) + '</span></td>'
        + '<td class="t-left">' + esc(r.doc_name) + '</td>'
        + '<td>' + esc(r.part_no || '—') + '</td>'
        + '<td>' + esc(r.printed_by_name || '—') + '</td>'
        + '<td class="ps-host">' + host + '</td>'
        + '<td>' + esc(r.note || '') + '</td>'
        + '</tr>';
}

function rowHtmlSign(r){
    var cls = r.status === 'approved' ? 'res-ok' : (r.status === 'rejected' ? 'res-no' : 'res-wait');
    return '<tr>'
        + '<td class="t-left">' + esc(r.doc_name) + '</td>'
        + '<td>' + esc(r.module_label) + '</td>'
        + '<td style="white-space:nowrap;">' + esc(dispDate(r.doc_date || r.submitted_at)) + '</td>'
        + '<td>' + esc(r.submitted_by_name || '—') + '</td>'
        + '<td>' + esc(r.level_label) + '</td>'
        + '<td>' + esc(r.approver_name || '—') + '</td>'
        + '<td style="white-space:nowrap;">' + (r.decided_at ? esc(dispDateTime(r.decided_at)) : '—') + '</td>'
        + '<td><span class="res-pill ' + cls + '">' + esc(r.result_label) + '</span></td>'
        + '<td class="t-left">' + signNoteCell(r) + '</td>'
        + '</tr>';
}

/** 檢驗作業一列：時間／做了什麼／哪一張製令與製程／誰做的 */
function rowHtmlQcop(r){
    var res = r.result === 'OK' ? '<span class="res-pill res-ok">合格</span>'
            : r.result === 'NG' ? '<span class="res-pill res-no">不合格</span>'
            : (r.result ? esc(r.result) : '—');
    return '<tr>'
        + '<td style="white-space:nowrap;">' + esc(dispDateTime(r.ev_at)) + '</td>'
        + '<td><span class="src-pill' + (r.kind === 'draft' ? ' legacy' : '') + '">' + esc(r.kind_label) + '</span></td>'
        + '<td>' + esc(r.bom || '—') + '</td>'
        + '<td>' + esc(r.part_no || '—') + '</td>'
        + '<td>' + esc(r.client || '—') + '</td>'
        + '<td>' + esc(r.process || '—') + '</td>'
        + '<td>' + esc(r.maker || '—') + '</td>'
        + '<td>' + res + '</td>'
        + '<td>' + esc(r.ev_user_name || ('（帳號 ' + r.ev_uid + '）')) + '</td>'
        + '</tr>';
}

/** 簽核意見欄：系統內部註記預設遮蔽，只留一個看不出內容的灰字提示（規則見 ai-rules/23） */
function signNoteCell(r){
    if (r.note_hidden && !NOTE_REVEALED) return '<span style="color:#c9bda9;">—</span>';
    return esc(r.note || '');
}

function renderPager(total, per, page){
    var pages = per > 0 ? Math.max(1, Math.ceil(total / per)) : 1;
    if (page > pages) page = pages;
    var from = total ? (page - 1) * per + 1 : 0;
    var to   = Math.min(total, page * per);
    $('#pgInfo').text('共 ' + total + ' 筆' + (total ? '（第 ' + from + '～' + to + ' 筆）' : ''));
    var h = '';
    h += '<button ' + (page <= 1 ? 'disabled' : '') + ' data-p="1">«</button>';
    h += '<button ' + (page <= 1 ? 'disabled' : '') + ' data-p="' + (page - 1) + '">‹</button>';
    var s = Math.max(1, page - 2), e = Math.min(pages, s + 4);
    s = Math.max(1, e - 4);
    for (var i = s; i <= e; i++) h += '<button class="' + (i === page ? 'cur' : '') + '" data-p="' + i + '">' + i + '</button>';
    h += '<button ' + (page >= pages ? 'disabled' : '') + ' data-p="' + (page + 1) + '">›</button>';
    h += '<button ' + (page >= pages ? 'disabled' : '') + ' data-p="' + pages + '">»</button>';
    $('#pgBtns').html(h);
}

function loadList(){
    if (TAB === 'qcstat') { loadStat(); return; }
    var act = (TAB === 'print') ? 'list_print' : (TAB === 'sign' ? 'list_sign' : 'list_qcop');
    $('#tBody').html('<tr><td colspan="9" style="padding:20px;color:#8a6d45;">載入中…</td></tr>');
    $.post(API, $.extend({ action: act }, filters()), function(res){
        if (!res || !res.ok) { $('#tBody').html('<tr><td colspan="9" style="padding:20px;color:#DD5138;">'+esc((res&&res.error)||'查詢失敗')+'</td></tr>'); return; }
        LAST = { rows: res.rows || [], total: res.total || 0 };
        PAGE = res.page || 1;
        // 內部註記的解除狀態一律以後端為準（權限＋10 分鐘時效都在後端判定）
        NOTE_REVEALED = !!res.note_revealed;
        $('#btnRevealNote').toggle(TAB === 'sign' && !!res.can_reveal)
            .html(NOTE_REVEALED ? '<i class="fa fa-eye-slash"></i> 隱藏內部註記'
                                : '<i class="fa fa-lock"></i> 顯示內部註記');
        renderHead();
        var f = (TAB === 'print') ? rowHtmlPrint : (TAB === 'sign' ? rowHtmlSign : rowHtmlQcop);
        $('#tBody').html(LAST.rows.map(f).join(''));
        $('#emptyBox').toggle(LAST.rows.length === 0);
        renderPager(LAST.total, parseInt(res.per, 10) || 10, PAGE);
        var $c = $({ print:'#cntPrint', sign:'#cntSign', qcop:'#cntQcop' }[TAB]);
        $c.text(LAST.total).toggleClass('zero', LAST.total === 0);
    }, 'json').fail(function(){
        $('#tBody').html('<tr><td colspan="9" style="padding:20px;color:#DD5138;">查詢失敗（連線異常）</td></tr>');
    });
}

// 每個分頁的筆數徽章都要即時（點開即刷新鐵則：切分頁連帶刷新其他分頁的計數）
var COUNT_TABS = [['print','list_print','#cntPrint'], ['sign','list_sign','#cntSign'], ['qcop','list_qcop','#cntQcop']];
function refreshOtherCount(){
    COUNT_TABS.forEach(function(t){
        if (t[0] === TAB) return;                       // 目前分頁的數字由 loadList 自己更新
        var f = filters({ per: 5, page: 1 }, t[0]);
        // 各分頁自己的「來源」條件不通用，計數時一律不套
        delete f.source; delete f.module; delete f.kind; delete f.process; delete f.maker;
        $.post(API, $.extend({ action: t[1] }, f), function(res){
            if (!res || !res.ok) return;
            $(t[2]).text(res.total || 0).toggleClass('zero', (res.total || 0) === 0);
        }, 'json');
    });
}

// ── 月統計：列＝展開維度、欄＝1~12 月（像統計資料表那樣一列一個對象）──────
function statCell(c, isRate, cls){
    cls = cls || '';
    if (!c || !c.n) return '<td class="st-zero ' + cls + '">—</td>';
    if (!isRate) return '<td class="' + cls + '">' + c.n + '</td>';
    var pct = Math.round(c.ok / c.n * 1000) / 10;
    return '<td class="' + cls + '">' + c.ok + '／' + c.n
         + '<span class="st-rate' + (pct < 100 ? ' bad' : '') + '">' + pct + '%</span></td>';
}
function renderStat(res){
    LASTSTAT = res;
    var isRate = !!res.is_rate;
    var dimLabel = '對象';
    (res.dims || []).forEach(function(d){ if (d.code === res.dim) dimLabel = d.label.replace(/^依/, ''); });
    if (res.dim === 'none') dimLabel = '項目';

    var h = '<tr><th style="text-align:left;">' + esc(dimLabel) + '</th>';
    for (var m = 1; m <= 12; m++) h += '<th>' + m + '月</th>';
    h += '<th class="st-sum">全年合計</th></tr>';
    $('#tHead').html(h);

    var rows = res.rows || [];
    var b = '';
    rows.forEach(function(r){
        b += '<tr><td class="t-dim">' + esc(r.dim) + '</td>';
        for (var m = 1; m <= 12; m++) b += statCell(r.months[m], isRate);
        b += statCell({ n: r.sum_n, ok: r.sum_ok }, isRate, 'st-sum') + '</tr>';
    });
    if (rows.length) {
        b += '<tr class="st-foot"><td class="t-dim">合計</td>';
        for (var m2 = 1; m2 <= 12; m2++) b += statCell({ n: res.foot.n[m2], ok: res.foot.ok[m2] }, isRate);
        b += statCell({ n: res.foot.sum_n, ok: res.foot.sum_ok }, isRate, 'st-sum') + '</tr>';
    }
    $('#tBody').html(b);
    $('#emptyBox').toggle(rows.length === 0);

    var hint = '';
    (res.metrics || []).forEach(function(x){ if (x.code === res.metric) hint = x.hint; });
    $('#statHint').html('<i class="fa fa-info-circle"></i> ' + esc(res.year) + ' 年　'
        + esc(hint) + (isRate ? '（格內為「合格數／總數」與百分比）' : '') ).show();
}
function loadStat(){
    $('#tBody').html('<tr><td colspan="14" style="padding:20px;color:#8a6d45;">統計中…</td></tr>');
    $.post(API, $.extend({ action:'stat_qcop' }, filters()), function(res){
        if (!res || !res.ok) { $('#tBody').html('<tr><td colspan="14" style="padding:20px;color:#DD5138;">'+esc((res&&res.error)||'統計失敗')+'</td></tr>'); return; }
        renderStat(res);
    }, 'json').fail(function(){
        $('#tBody').html('<tr><td colspan="14" style="padding:20px;color:#DD5138;">統計失敗（連線異常）</td></tr>');
    });
}

// ── 檢驗作業／月統計分頁的篩選選項（跟著目前日期區間走）──────────────────
function fillQcSel(){
    if (!META_QC) return;
    var fill = function (sel, list, valOf, txtOf) {
        var $s = $(sel), cur = $s.val() || '';
        var seen = false;
        $s.html('<option value="">全部</option>');
        list.forEach(function(x){
            var v = valOf(x);
            if (String(v) === String(cur)) seen = true;
            $s.append('<option value="'+esc(v)+'">'+esc(txtOf(x))+'</option>');
        });
        // 已選但這個區間沒資料的，仍要留著；不然選項一消失篩選條件會被默默改掉
        if (cur && !seen) $s.append('<option value="'+esc(cur)+'">'+esc(cur)+'（此區間無資料）</option>');
        $s.val(cur);
    };
    fill('#fKind',  META_QC.kinds || [],     function(x){ return x.code; }, function(x){ return x.label; });
    fill('#fProc',  META_QC.processes || [], function(x){ return x; },      function(x){ return x; });
    fill('#fMaker', META_QC.makers || [],    function(x){ return x; },      function(x){ return x; });

    var $y = $('#fYear'), cy = $y.val() || '';
    $y.html((META_QC.years || []).map(function(y){ return '<option value="'+y+'">'+y+'</option>'; }).join(''));
    if (cy && $y.find('option[value="'+cy+'"]').length) $y.val(cy);
    var $m = $('#fMetric'), cm = $m.val() || '';
    $m.html((META_QC.metrics || []).map(function(x){ return '<option value="'+esc(x.code)+'">'+esc(x.label)+'</option>'; }).join(''));
    if (cm) $m.val(cm);
    var $d = $('#fDim'), cd = $d.val() || '';
    $d.html((META_QC.dims || []).map(function(x){ return '<option value="'+esc(x.code)+'">'+esc(x.label)+'</option>'; }).join(''));
    $d.val(cd || 'user');
}
function reloadMetaQc(cb){
    $.get(API, { action:'meta_qcop', date_from: $('#fFrom').val() || '', date_to: $('#fTo').val() || '' }, function(res){
        if (res && res.ok) { META_QC = res; fillQcSel(); }
        if (cb) cb();
    }, 'json').fail(function(){ if (cb) cb(); });
}

/** 依目前分頁決定工具列哪些欄位要出現（欄位不通用，硬留著只會讓人以為篩得到） */
function syncToolbar(){
    var isQc = (TAB === 'qcop' || TAB === 'qcstat');
    $('#wrapSource').toggle(!isQc);
    $('#wrapKind').toggle(TAB === 'qcop');
    $('#wrapProc, #wrapMaker').toggle(isQc);
    $('#wrapStat').toggle(TAB === 'qcstat');
    $('#wrapDate').toggle(TAB !== 'qcstat');          // 月統計以整年為單位
    $('#lblIncDel').toggle(TAB === 'sign');
    $('.ps-pagebar').toggle(TAB !== 'qcstat');
    $('#statHint').toggle(TAB === 'qcstat');
    $('#lblPerson').text(TAB === 'print' ? '列印人' : (TAB === 'sign' ? '簽核人' : '操作人'));
}

// ── 列印全部篩選結果（ai-rules/16：大標題本公司名、頁碼左下、只有多頁才印頁碼）──
function printAll(){
    if (TAB === 'qcstat') { printStat(); return; }
    var act = (TAB === 'print') ? 'list_print' : (TAB === 'sign' ? 'list_sign' : 'list_qcop');
    var $b = $('#btnPrintAll').prop('disabled', true).text('整理中…');
    $.post(API, $.extend({ action: act }, filters({ per: 0, page: 1 })), function(res){
        $b.prop('disabled', false).html('<i class="fa fa-print"></i> 列印全部篩選結果');
        if (!res || !res.ok) { alert((res && res.error) || '取得資料失敗'); return; }
        var rows = res.rows || [];
        if (!rows.length) { alert('目前篩選條件下沒有資料可列印'); return; }

        var cols = HEADS[TAB] || HEAD_PRINT;
        var title = { print:'列印紀錄', sign:'簽核紀錄', qcop:'檢驗作業紀錄' }[TAB] || '紀錄';
        var cond = [];
        var srcTxt = $('#fSource option:selected').text();
        if (TAB !== 'qcop' && $('#fSource').val()) cond.push('資料來源：' + srcTxt);
        if (TAB === 'qcop') {
            if ($('#fKind').val())  cond.push('事件種類：' + $.trim($('#fKind option:selected').text()));
            if ($('#fProc').val())  cond.push('製程：' + $('#fProc').val());
            if ($('#fMaker').val()) cond.push('廠商：' + $('#fMaker').val());
        }
        if ($('#fUser').val())   cond.push($('#lblPerson').text() + '：' + $.trim($('#fUser option:selected').text()));
        if ($('#fFrom').val() || $('#fTo').val())
            cond.push('日期：' + (dispDate($('#fFrom').val()) || '不限') + ' ～ ' + (dispDate($('#fTo').val()) || '不限'));
        if ($.trim($('#fKw').val())) cond.push('關鍵字：' + $.trim($('#fKw').val()));

        var body = '';
        body += '<div class="p-title">' + esc(COMPANY || '') + '</div>';
        body += '<div class="p-sub">' + esc(title) + '</div>';
        body += '<div class="p-cond">' + esc(cond.length ? cond.join('　｜　') : '全部資料') + '　｜　共 ' + rows.length + ' 筆　｜　列印日期：' + esc(dispDate(new Date().toISOString().substring(0,10))) + '</div>';
        body += '<table class="p-tb"><thead><tr>' + cols.map(function(c){ return '<th>'+esc(c)+'</th>'; }).join('') + '</tr></thead><tbody>';
        rows.forEach(function(r){
            if (TAB === 'print') {
                var host = r.client_host ? r.client_host + (r.client_ip ? ' (' + r.client_ip + ')' : '') : (r.client_ip || '—');
                body += '<tr>'
                     + '<td>' + esc(dispDateTime(r.printed_at)) + '</td>'
                     + '<td>' + esc(r.source_label) + '</td>'
                     + '<td class="tl">' + esc(r.doc_name) + '</td>'
                     + '<td>' + esc(r.part_no || '—') + '</td>'
                     + '<td>' + esc(r.printed_by_name || '—') + '</td>'
                     + '<td>' + esc(host) + '</td>'
                     + '<td class="tl">' + esc(r.note || '') + '</td></tr>';
            } else if (TAB === 'qcop') {
                body += '<tr>'
                     + '<td>' + esc(dispDateTime(r.ev_at)) + '</td>'
                     + '<td>' + esc(r.kind_label) + '</td>'
                     + '<td>' + esc(r.bom || '—') + '</td>'
                     + '<td>' + esc(r.part_no || '—') + '</td>'
                     + '<td>' + esc(r.client || '—') + '</td>'
                     + '<td>' + esc(r.process || '—') + '</td>'
                     + '<td>' + esc(r.maker || '—') + '</td>'
                     + '<td>' + esc(r.result === 'OK' ? '合格' : (r.result === 'NG' ? '不合格' : '—')) + '</td>'
                     + '<td>' + esc(r.ev_user_name || '—') + '</td></tr>';
            } else {
                body += '<tr>'
                     + '<td class="tl">' + esc(r.doc_name) + '</td>'
                     + '<td>' + esc(r.module_label) + '</td>'
                     + '<td>' + esc(dispDate(r.doc_date || r.submitted_at)) + '</td>'
                     + '<td>' + esc(r.submitted_by_name || '—') + '</td>'
                     + '<td>' + esc(r.level_label) + '</td>'
                     + '<td>' + esc(r.approver_name || '—') + '</td>'
                     + '<td>' + (r.decided_at ? esc(dispDateTime(r.decided_at)) : '—') + '</td>'
                     + '<td>' + esc(r.result_label) + '</td>'
                     // 列印一律遮蔽內部註記，即使畫面上已經解除——文件會離開系統，沒有「已驗證過」這回事
                     + '<td class="tl">' + (r.note_hidden ? '' : esc(r.note || '')) + '</td></tr>';
            }
        });
        body += '</tbody></table>';

        psPrintWindow(title, body);
    }, 'json').fail(function(){
        $b.prop('disabled', false).html('<i class="fa fa-print"></i> 列印全部篩選結果');
        alert('取得資料失敗');
    });
}

/** 開列印視窗（ai-rules/16：大標題本公司名、頁碼左下、只有多頁才印頁碼）。
 *  清單與月統計共用同一份版面規則，不要各印各的。 */
function psPrintWindow(title, body){
        var css = 'body{font-family:"Microsoft JhengHei",sans-serif;color:#222;margin:0;}'
            + '.p-title{text-align:center;font-size:16pt;font-weight:bold;margin-bottom:2mm;}'
            + '.p-sub{text-align:center;font-size:13pt;margin-bottom:2mm;}'
            + '.p-cond{font-size:9pt;color:#444;margin-bottom:3mm;}'
            + 'table.p-tb{width:100%;border-collapse:collapse;font-size:9pt;}'
            + 'table.p-tb th,table.p-tb td{border:1px solid #999;padding:2px 4px;text-align:center;}'
            + 'table.p-tb thead th{background:#eee;}'
            + 'table.p-tb td.tl{text-align:left;word-break:break-all;}'
            + 'table.p-tb tr{break-inside:avoid;}'
            + 'table.p-tb thead{display:table-header-group;}'   // 跨頁時表頭自然重複，不自算分頁
            + '@page{size:A4 landscape;margin:12mm 10mm 18mm;}';
        var w = window.open('', '_blank');
        if (!w) { alert('列印視窗被瀏覽器攔截，請允許本頁彈出視窗'); return; }
        w.document.write('<html><head><meta charset="utf-8"><title>' + esc(title) + '</title><style>' + css + '</style></head><body>' + body
            + '<scr'+'ipt>window.onload=function(){'
            // 內容超過一頁才加頁碼（counter(pages) 由列印引擎在列印當下計算，不用 JS 自算分頁）
            + 'var onePage=(210-30)*96/25.4;'
            + 'if(document.body.scrollHeight>onePage*0.92){'
            + 'var st=document.createElement(\'style\');'
            + 'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; } }";'
            + 'document.head.appendChild(st);}'
            + 'setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>');
        w.document.close();
}

/** 月統計列印：印的是目前畫面上這張矩陣（列＝展開維度、欄＝1~12 月） */
function printStat(){
    if (!LASTSTAT || !(LASTSTAT.rows || []).length) { alert('目前沒有統計資料可列印'); return; }
    var res = LASTSTAT, isRate = !!res.is_rate;
    var mLabel = '', dLabel = '';
    (res.metrics || []).forEach(function(x){ if (x.code === res.metric) mLabel = x.label; });
    (res.dims || []).forEach(function(x){ if (x.code === res.dim) dLabel = x.label; });

    var cond = [res.year + ' 年', mLabel, dLabel];
    if ($('#fUser').val())  cond.push('操作人：' + $.trim($('#fUser option:selected').text()));
    if ($('#fProc').val())  cond.push('製程：' + $('#fProc').val());
    if ($('#fMaker').val()) cond.push('廠商：' + $('#fMaker').val());
    if ($.trim($('#fKw').val())) cond.push('關鍵字：' + $.trim($('#fKw').val()));

    var cell = function(c){
        if (!c || !c.n) return '<td>—</td>';
        if (!isRate) return '<td>' + c.n + '</td>';
        return '<td>' + c.ok + '／' + c.n + '<br>' + (Math.round(c.ok / c.n * 1000) / 10) + '%</td>';
    };
    var body = '';
    body += '<div class="p-title">' + esc(COMPANY || '') + '</div>';
    body += '<div class="p-sub">檢驗作業月統計</div>';
    body += '<div class="p-cond">' + esc(cond.join('　｜　')) + '　｜　列印日期：'
          + esc(dispDate(new Date().toISOString().substring(0,10))) + '</div>';
    body += '<table class="p-tb"><thead><tr><th>' + esc(dLabel.replace(/^依/, '') || '項目') + '</th>';
    for (var m = 1; m <= 12; m++) body += '<th>' + m + '月</th>';
    body += '<th>全年合計</th></tr></thead><tbody>';
    (res.rows || []).forEach(function(r){
        body += '<tr><td class="tl">' + esc(r.dim) + '</td>';
        for (var m = 1; m <= 12; m++) body += cell(r.months[m]);
        body += cell({ n: r.sum_n, ok: r.sum_ok }) + '</tr>';
    });
    body += '<tr><td class="tl"><b>合計</b></td>';
    for (var m2 = 1; m2 <= 12; m2++) body += cell({ n: res.foot.n[m2], ok: res.foot.ok[m2] });
    body += cell({ n: res.foot.sum_n, ok: res.foot.sum_ok }) + '</tr>';
    body += '</tbody></table>';
    psPrintWindow('檢驗作業月統計', body);
}

// ── 使用說明的「涵蓋範圍」：一律即時掃描 ────────────────────────────────
var COV_LOADED = false;
function loadCoverage(){
    if (COV_LOADED) return;
    $.get(API, { action: 'coverage' }, function(res){
        if (!res || !res.ok) { $('#covBox').html('<span style="color:#DD5138;">掃描失敗</span>'); return; }
        COV_LOADED = true;
        var h = '';
        var p = res.print || {}, s = res.sign || {};
        h += '<div><span class="cov-yes">列印：已涵蓋（' + (p.covered || []).length + ' 支頁面）</span><ul>';
        (p.covered || []).forEach(function(x){
            h += '<li>' + esc(x.label) + ' <span class="cov-note">' + esc(x.page)
               + (x.via === 'legacy' ? '（該模組原本就有自己的列印紀錄）' : '') + '</span></li>';
        });
        h += '</ul></div>';
        h += '<div><span class="cov-no">列印：尚未涵蓋（' + (p.uncovered || []).length + ' 支頁面）</span>'
           + '<div class="cov-note">這些頁面有列印功能但還沒接上紀錄，印了不會留紀錄。</div><ul>';
        (p.uncovered || []).forEach(function(x){ h += '<li class="cov-note">' + esc(x.page) + '</li>'; });
        h += '</ul></div>';

        h += '<div><span class="cov-yes">簽核：已涵蓋（' + (s.covered || []).length + ' 種單據）</span><ul>';
        (s.covered || []).forEach(function(x){
            h += '<li>' + esc(x.label) + ' <span class="cov-note">（目前 ' + x.rows + ' 筆）</span></li>';
        });
        h += '</ul></div>';
        h += '<div><span class="cov-no">簽核：尚未涵蓋（' + (s.uncovered || []).length + ' 種）</span>'
           + '<div class="cov-note">這些模組把簽核存在自己的資料表，沒有寫進共用的簽核紀錄，所以查不到。</div><ul>';
        (s.uncovered || []).forEach(function(x){
            h += '<li class="cov-note">' + esc(x.note || x.table) + '（' + esc(x.table) + '，約 ' + x.rows + ' 筆）</li>';
        });
        h += '</ul></div>';
        $('#covBox').html(h);
    }, 'json');
}

// ── 角色說明：一律查目前實際的角色設定，不放寫死清單（鐵律4）────────────
function loadRoleHelp(){
    var h = '<ul style="padding-left:18px;line-height:1.9;">'
          + '<li><b>紀錄管理</b>（psl_admin）：查全部人的紀錄、列印匯出。</li>'
          + '<li><b>紀錄檢閱</b>（psl_view_all）：查全部人的紀錄（唯讀）。</li>'
          + '<li><b>未指派角色</b>：只看得到自己的紀錄。</li>'
          + '<li><b>管理者</b>：固定擁有全部權限。</li></ul>'
          + '<div style="margin-top:8px;color:#8a6d45;">目前你的身分：<b>' + esc($('#roleName').text()) + '</b></div>';
    $('#roleHelpBody').html(h);
}

// ── 事件 ────────────────────────────────────────────────────────────────
$('.ps-tab').on('click', function(){
    var t = $(this).data('tab');
    if (t === TAB) return;
    TAB = t; PAGE = 1;
    $('.ps-tab').removeClass('active'); $(this).addClass('active');
    syncToolbar();
    fillSourceSel();
    fillPeopleSel();   // 列印人／簽核人／檢驗操作人不是同一批人，切分頁要跟著換
    // 檢驗作業的選項第一次進去才載（沒人點就不必多打一次 API）
    if ((TAB === 'qcop' || TAB === 'qcstat') && !META_QC) { reloadMetaQc(function(){ loadList(); }); }
    else { fillQcSel(); loadList(); }
    refreshOtherCount();
});
// 即時搜尋：任何篩選一改就查，不必按查詢鈕（使用者要求）。
// 關鍵字要 debounce——每打一個字就發一次請求，慢的那次回來會覆蓋掉快的那次，畫面會跳成舊結果。
var _kwTimer = null;
function liveSearch(delay){
    clearTimeout(_kwTimer);
    _kwTimer = setTimeout(function(){ PAGE = 1; loadList(); refreshOtherCount(); }, delay || 0);
}
$('#fSource, #fUser').on('change', function(){ liveSearch(0); });
$('#fKind, #fProc, #fMaker, #fYear, #fMetric, #fDim').on('change', function(){ liveSearch(0); });
$('#fIncDel').on('change', function(){ liveSearch(0); });
$('#fFrom, #fTo').on('change', function(){
    reloadMeta(function(){
        if (META_QC) reloadMetaQc(function(){ liveSearch(0); });
        else liveSearch(0);
    });
});
$('#fKw').on('input', function(){ liveSearch(350); });
$('#btnReset').on('click', function(){
    resetRange(); $('#fSource').val(''); $('#fKw').val('');
    $('#fKind').val(''); $('#fProc').val(''); $('#fMaker').val('');
    if (META.perms.canViewAll) $('#fUser').val('');
    reloadMeta(function(){                       // 區間變了，下拉選項要跟著重算
        if (META_QC) reloadMetaQc(function(){ liveSearch(0); });
        else liveSearch(0);
    });
});
$('#fPer').on('change', function(){ PAGE = 1; syncTopBtn(); loadList(); });
$('#pgBtns').on('click', 'button', function(){
    var p = parseInt($(this).data('p'), 10);
    if (!p || p === PAGE) return;
    PAGE = p; loadList();
});
$('#btnPrintAll').on('click', printAll);

// ── 內部註記解除遮蔽 ────────────────────────────────────────────────────
$('#btnRevealNote').on('click', function(){
    if (NOTE_REVEALED) {
        $.post(API, { action:'hide_note' }, function(){ NOTE_REVEALED = false; load(); }, 'json');
        return;
    }
    $('#revealPw').val(''); $('#revealErr').hide();
    $('#revealMask').modal('show');
    setTimeout(function(){ $('#revealPw').focus(); }, 350);
});
$('#revealOk').on('click', function(){
    var pw = $('#revealPw').val();
    if (!pw) { $('#revealErr').text('請輸入操作確認密碼').show(); return; }
    var $b = $(this).prop('disabled', true);
    $.post(API, { action:'reveal_note', password:pw }, function(r){
        $b.prop('disabled', false);
        if (!r || !r.ok) { $('#revealErr').text((r && r.error) || '驗證失敗').show(); return; }
        $('#revealMask').modal('hide');
        NOTE_REVEALED = true;
        load();
    }, 'json').fail(function(x){
        $b.prop('disabled', false);
        var m = '驗證失敗';
        try { m = JSON.parse(x.responseText).error || m; } catch(e){}
        $('#revealErr').text(m).show();
    });
});
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); loadCoverage(); });
$('#btnRoleHelp').on('click', function(){ loadRoleHelp(); openMask('roleHelpMask'); });
$('.ps-mask').on('click', function(e){ if (e.target === this) this.style.display='none'; });

// 回頂端鈕：每頁 10 筆時整頁看得完、不需要，改成「每頁超過 10 筆才出現」（使用者要求）
function syncTopBtn(){ $('#btnTop').toggle((parseInt($('#fPer').val(), 10) || 10) > 10); }
$('#btnTop').on('click', function(){ $('html,body').animate({ scrollTop: 0 }, 200); });

// ── 起始 ────────────────────────────────────────────────────────────────
resetRange();
$.get(API, { action: 'meta', date_from: $('#fFrom').val() || '', date_to: $('#fTo').val() || '',
             with_all: 1 }, function(res){
    if (!res || !res.ok) { $('#tBody').html('<tr><td colspan="9" style="padding:20px;color:#DD5138;">載入失敗</td></tr>'); return; }
    META = res;
    fillSourceSel();
    fillPeopleSel();
    if (!META.perms.canViewAll) $('#roleName').text('僅本人紀錄');
    syncToolbar();
    syncTopBtn();
    loadList();
    refreshOtherCount();
}, 'json');
</script>
</body>
</html>
