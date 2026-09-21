<?php
/**
 * qa_abnormal_form.php — 品質異常處理單 單張處理頁（2-QA-01-01）
 * 建立：2026-09-18
 *
 * 一張單從頭到尾都在這一頁完成，版面分區與紙本一致，各段由有權限的人接力填：
 *   ① 填寫區（表頭、責任單位、量測值、異常現象）  ② 異常原因分類（三層，結案前可改）
 *   ③ 相關單位意見（一次送一個單位，收到回覆再決定下一個或進決策）
 *   ④ 決策（業務／品管主管）  ⑤ 總經理裁示（含「是否扣款」）
 *   ⑥ 扣款確認（製程金額自動帶入＋加成、其他列、合計、三格簽章、核准）  ⑦ 結案（配發報廢單號）
 *
 * 資料一律走 src/store/QaAbnormal_API.php；共用規則在 src/common/qa_abnormal_lib.php。
 */
session_start();
if (!isset($_SESSION['id'])) {
    $_SESSION['lastpage'] = '/EGsystem/views/QA/qa_abnormal_form.php?id=' . (int)($_GET['id'] ?? 0);
    header('Location: /EGsystem/index.php');
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/qa_abnormal_lib.php';

$db = (new DBConnection())->getPDO();
qab_ensure_schema($db);
$uid   = (int)$_SESSION['id'];
$perms = qab_perms($db, $uid);
if (empty($_SESSION['qab_csrf'])) $_SESSION['qab_csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['qab_csrf'];
$oid  = (int)($_GET['id'] ?? 0);
// 沒有模組檢視權時，只要「跟這張單有關」（被徵詢的人、開單人、共編、追蹤人）就看得到
if (!$perms['canView'] && !qab_can_view_order($db, $perms, $oid)) {
    http_response_code(403);
    exit('沒有這張品質異常單的檢視權限');
}
$roleLabel = $perms['isAdmin'] ? '系統管理者' : ($perms['canAdmin'] ? '異常單管理員'
            : ($perms['canGm'] ? '最終決策者' : ($perms['canDecide'] ? '決策主管'
            : ($perms['canCreate'] ? '開單／填寫' : ($perms['canView'] ? '檢閱' : '無權限')))));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>品質異常處理單</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        /* 側欄：CSS 藏起來、ready 時再顯示（鐵律6，CSS 與 JS 必須成對） */
        #sidebar-menu { visibility: hidden; }
        :root{ --ink:#4A3524; --ink2:#6B4423; --cream:#FCF7F0; --sand:#F7E0BD; --amber:#F0A24B;
               --amber-d:#C77C1A; --coral:#DD5138; --line:#E4D3BC; }
        body { background:#F6F1EA; }
        .right_col .page-title { margin:8px 0 6px; overflow:hidden; clear:both; }
        .page-title h3 { color:var(--ink); margin:0; display:flex; align-items:center; gap:10px; flex-wrap:wrap; font-size:20px; }
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid var(--amber-d);
                         border-radius:15px; background:#fff; color:var(--amber-d); }
        .page-help-btn:hover { background:var(--amber-d); color:#fff; }
        @media print { .page-help-btn { display:none !important; } }
        .as-tag,.role-tag { font-size:12px; background:var(--sand); color:var(--ink2); border-radius:10px; padding:2px 10px; font-weight:normal; }
        .role-tag { background:#EFE3CF; }
        .btn-warm { background:var(--amber); border:1px solid var(--amber-d); color:var(--ink); font-weight:bold; }
        .btn-warm:hover,.btn-warm:focus { background:var(--amber-d); color:#fff; }
        .btn-warm-o { background:#fff; border:1px solid var(--amber-d); color:var(--amber-d); }
        .btn-warm-o:hover { background:var(--sand); color:var(--ink); }
        .sec { background:#fff; border:1px solid var(--line); border-radius:8px; margin-bottom:12px; }
        .sec > h4 { margin:0; padding:8px 12px; background:var(--sand); color:var(--ink2); font-size:14px;
                    font-weight:bold; border-radius:7px 7px 0 0; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .sec > h4 .sub { font-size:12px; font-weight:normal; color:#8a7560; }
        .sec > h4 .spacer { margin-left:auto; }
        .sec-body { padding:10px 12px; }
        .fgrid { display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:8px 14px; }
        .fld label { display:block; font-size:12px; color:#8a7560; margin:0 0 2px; font-weight:normal; }
        .fld input[type=text], .fld input[type=number], .fld input[type=date], .fld select, .fld textarea {
            width:100%; border:1px solid var(--line); border-radius:4px; padding:3px 6px; font-size:13px; background:#fff; }
        .fld textarea { resize:vertical; }
        .fld input[readonly], .fld textarea[readonly] { background:#F5F0E8; color:#5b4a36; }
        .ro-note { font-size:12px; color:#8a7560; }
        .muted-help { font-size:12px; color:#8a7560; }
        .note-box { font-size:12px; color:var(--ink2); background:var(--cream); border:1px solid var(--line);
                    border-radius:6px; padding:7px 10px; margin-bottom:8px; line-height:1.7; }
        .stat-bar { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:10px; }
        .st { border-radius:12px; padding:2px 12px; font-size:12.5px; border:1px solid var(--line); background:#fff; }
        .st-closed { background:#EFE3CF; color:var(--ink2); }
        .st-reply  { background:var(--sand); color:var(--ink2); }
        .st-decide { background:var(--amber); color:#3b2a18; }
        .st-gm     { background:var(--coral); color:#fff; }
        .st-deduct { background:#F0A24B; color:#3b2a18; }
        .st-ready  { background:#DDEBD6; color:#2c5c2c; }
        /* 量測表 */
        table.mtb { width:100%; border-collapse:collapse; font-size:12px; table-layout:fixed; }
        table.mtb th, table.mtb td { border:1px solid var(--line); padding:1px 2px; text-align:center; }
        table.mtb th { background:var(--cream); color:var(--ink2); font-weight:normal; }
        table.mtb input { width:100%; border:0; text-align:center; font-size:12px; padding:2px 1px; background:transparent; }
        table.mtb input:focus { background:#FFF8EC; outline:1px solid var(--amber); }
        /* 原因分類樹 */
        .ctree { max-height:260px; overflow:auto; border:1px solid var(--line); border-radius:6px; padding:6px 10px; background:#fff; }
        .ctree .lv1 { margin-top:4px; font-weight:bold; color:var(--ink2); }
        .ctree .lv2 { margin-left:20px; }
        .ctree .lv3 { margin-left:40px; }
        .ctree label { font-weight:normal; margin:0; cursor:pointer; display:inline-flex; align-items:center; gap:5px; font-size:13px; }
        .chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px; }
        .chip { display:inline-flex; align-items:center; gap:6px; background:var(--cream); border:1px solid var(--line);
                border-radius:12px; padding:2px 10px; font-size:12.5px; color:var(--ink2); line-height:18px; }
        .chip .x { color:var(--coral); cursor:pointer; font-weight:bold; }
        /* 勾選選項 */
        .opts { display:flex; flex-wrap:wrap; gap:8px; }
        .opts label { font-weight:normal; margin:0; padding:4px 12px; border:1px solid var(--line); border-radius:15px;
                      background:#fff; cursor:pointer; font-size:13px; display:inline-flex; align-items:center; gap:6px; }
        .opts label.on { background:var(--sand); border-color:var(--amber-d); color:var(--ink2); font-weight:bold; }
        /* 意見輪次 */
        .rnd { border:1px solid var(--line); border-radius:6px; margin-bottom:8px; }
        .rnd .hd { background:var(--cream); padding:5px 10px; font-size:13px; color:var(--ink2);
                   display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
        .rnd .bd { padding:8px 10px; font-size:13px; white-space:pre-wrap; }
        .rnd .hd .spacer { margin-left:auto; }
        /* 類別名稱刻意不叫 .tag：Gentelella 的 custom.css 有一個全域 .tag 元件，
           它的 .tag:after 會在右側畫一個 11px 的三角形（left:100% 絕對定位），
           實測讓整頁多出 11px 橫向捲動，而且 color:#fff !important 會蓋掉暖色系配色。 */
        .saved{font-size:11px;color:#7a8f5a;font-weight:normal;margin-right:8px;}
.ask-pos{display:flex;flex-wrap:wrap;gap:2px 8px;}
.ask-pos label{font-weight:normal;margin:0;font-size:11.5px;}
.sg-res label{font-weight:normal;margin:0 8px 0 0;font-size:12px;white-space:nowrap;}
.sg-res{display:flex;flex-wrap:wrap;align-items:center;gap:2px;}
.bom-chip{display:inline-flex;align-items:center;gap:4px;background:var(--cream);border:1px solid var(--line);
    border-radius:10px;padding:1px 8px;font-size:12px;margin:2px 4px 2px 0;}
.bom-chip .x{cursor:pointer;color:var(--coral);}
.rtg { font-size:11px; border-radius:9px; padding:1px 8px; line-height:17px; display:inline-block; }
        .rtg-wait { background:var(--amber); color:#3b2a18; }
        .rtg-done { background:#DDEBD6; color:#2c5c2c; }
        /* 扣款表 */
        table.dtb { width:100%; border-collapse:collapse; font-size:12.5px; }
        table.dtb th, table.dtb td { border:1px solid var(--line); padding:3px 6px; }
        table.dtb th { background:var(--cream); color:var(--ink2); font-weight:normal; text-align:center; }
        table.dtb td.c { text-align:center; }
        table.dtb td.r { text-align:right; }
        table.dtb input[type=text], table.dtb input[type=number] { width:100%; border:1px solid var(--line);
                      border-radius:3px; padding:1px 4px; font-size:12.5px; }
        table.dtb tr.off { background:#F7F3EC; color:#9b8a75; }
        .sumline { display:flex; gap:16px; flex-wrap:wrap; align-items:center; justify-content:flex-end;
                   font-size:13px; margin-top:6px; color:var(--ink2); }
        .sumline b { font-size:16px; color:var(--ink); }
        /* 自動完成 */
        .ac-wrap { position:relative; }
        .ac-list { position:fixed; z-index:10400; background:#fff; border:1px solid var(--line); border-radius:4px;
                   box-shadow:0 4px 14px rgba(120,90,50,.22); max-height:240px; overflow:auto; display:none; min-width:240px; }
        .ac-list div { padding:5px 10px; font-size:13px; cursor:pointer; border-bottom:1px solid #F3EADC; }
        .ac-list div:hover { background:var(--cream); }
        .ac-list .hit { color:var(--amber-d); font-weight:bold; }
        /* 跳窗 */
        .m-mask { position:fixed; inset:0; background:rgba(74,53,36,.45); z-index:10300; display:none; }
        .m-box { position:absolute; left:50%; top:6vh; transform:translateX(-50%); background:#fff; border-radius:8px;
                 box-shadow:0 10px 30px rgba(0,0,0,.3); display:flex; flex-direction:column; max-height:88vh; }
        .m-hd { padding:10px 14px; border-bottom:1px solid var(--line); font-weight:bold; color:var(--ink2);
                display:flex; align-items:center; gap:10px; }
        .m-hd .x { margin-left:auto; cursor:pointer; color:#8a7560; }
        .m-bd { padding:12px 14px; overflow:auto; }
        .m-ft { padding:9px 14px; border-top:1px solid var(--line); text-align:right; }
        .help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc b { color:#8A5A2B; }
        .help-doc ul { margin:4px 0 8px; padding-left:20px; }
        .err { color:var(--coral); font-size:12px; margin-top:2px; }
        .locked { opacity:.72; }
    </style>
</head>
<body class="nav-sm">
<div class="container body"><div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html'; ?>

    <div class="right_col" role="main">
        <div class="page-title">
            <h3><i class="fa fa-exclamation-triangle" style="color:var(--coral);"></i>
                品質異常處理單
                <span class="as-tag" id="asTag">2-QA-01-01</span>
                <span class="role-tag"><?= htmlspecialchars($roleLabel) ?></span>
                <span id="noTag" style="font-size:15px;color:var(--amber-d);"></span>
                <button class="page-help-btn" id="btnPageHelp" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
            </h3>
        </div>

        <div class="stat-bar">
            <a href="qa_abnormal_list.php" class="btn btn-warm-o btn-sm"><i class="fa fa-list"></i> 回清單</a>
            <span id="stBox"></span>
            <span id="scrapBox"></span>
            <span style="margin-left:auto;"></span>
            <button class="btn btn-warm-o btn-sm" id="btnPrint"><i class="fa fa-print"></i> 列印</button>
            <button class="btn btn-warm btn-sm" id="btnClose2"><i class="fa fa-archive"></i> 結案</button>
            <button class="btn btn-warm-o btn-sm" id="btnReopen" style="display:none;"><i class="fa fa-undo"></i> 取消結案</button>
        </div>

        <div id="loadBox" class="note-box">載入中…</div>
        <div id="bfBox" class="note-box" style="display:none;border-color:var(--amber-d);background:#FFF6E8;"></div>
        <div id="formBox" style="display:none;">

            <!-- 補登簽章（只有補資料的單、且只有異常單管理員看得到） -->
            <div class="sec" id="secSign" style="display:none;">
                <h4><i class="fa fa-pencil-square-o"></i> 補登簽章
                    <span class="sub">補舊資料時指定「當時是誰簽的、蓋的是哪一天」，結果與內容也在這裡補</span>
                    <span class="spacer"></span><span class="saved" id="savedSign"></span>
                </h4>
                <div class="sec-body">
                    <div class="note-box">補舊資料時，<b>結果與簽章都在這一張表填完</b>（不必再到下面的決策區）。
                        人員清單依你填的<b>印章日期</b>只列<b>該格該簽的部門、當天在職、而且當天沒有請整天假或整天外出</b>的人；
                        找不到人時可以勾「顯示全部人員」放寬。印章日期不可以是未來；同一天有多格時系統會把時間依序錯開，
                        不會出現「核准早於承辦」這種順序。<b>改完就自動存檔，不必按存檔鈕。</b></div>
                    <label style="font-weight:normal;font-size:12px;"><input type="checkbox" id="sgAll"> 顯示全部人員（不限該格的部門）</label>
                    <table class="dtb" id="signTb">
                        <thead><tr><th style="width:150px;">簽章格</th><th style="width:150px;">目前</th>
                            <th style="width:135px;">印章日期</th><th style="width:210px;">補章人員</th>
                            <th>結果與內容</th><th style="width:84px;">動作</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

            <!-- ① 填寫區 -->
            <div class="sec" id="secHead">
                <h4><i class="fa fa-file-text-o"></i> 基本資料
                    <span class="sub">表頭與責任單位</span>
                    <span class="spacer"></span>
                    <span class="saved" id="savedHead"></span>
                    <button class="btn btn-warm-o btn-xs" id="btnSaveHead" title="欄位改完就會自動存，這顆只是要立刻存的時候用"><i class="fa fa-save"></i> 立即儲存</button>
                </h4>
                <div class="sec-body">
                    <div class="fgrid">
                        <div class="fld"><label>填寫日期</label><input type="date" id="f_fill_date"></div>
                        <div class="fld"><label>異常發生日期</label><input type="date" id="f_occ_date"></div>
                        <div class="fld"><label>客戶 <span class="muted-help" id="clientSrc"></span></label>
                            <input type="text" id="f_client"></div>
                        <div class="fld"><label>料號 <span class="muted-help" id="partSrc"></span></label><input type="text" id="f_part"></div>
                        <div class="fld"><label>製令編號 <span class="muted-help">（主製令；綁了才能自動帶客戶、料號與扣款金額）</span></label>
                            <div class="ac-wrap"><input type="text" id="f_bom" autocomplete="off" placeholder="輸入製令／料號／客戶後從清單選"></div>
                            <div class="err" id="bomErr" style="display:none;"></div></div>
                        <div class="fld"><label>客退單號 (IR)</label>
                            <div class="ac-wrap"><input type="text" id="f_ir" autocomplete="off" placeholder="輸入單號／客戶／料號後從清單選"></div>
                            <input type="hidden" id="f_ir_id">
                            <div class="err" id="irErr" style="display:none;"></div></div>
                        <div class="fld"><label>批量</label><input type="number" id="f_batch"></div>
                        <div class="fld"><label>檢驗數 <span class="muted-help" id="sampleHint"></span></label><input type="number" id="f_insp"></div>
                        <div class="fld"><label>不良數</label><input type="number" id="f_ng"><div class="ro-note" id="ngRate"></div></div>
                    </div>

                    <div style="margin-top:10px;border-top:1px dashed var(--line);padding-top:8px;" id="bomMoreBox">
                        <div class="muted-help" style="margin-bottom:4px;"><b>相關製令</b>（選填）：
                            退貨的如果是<b>組合件</b>，底下會有好幾張製令，這裡可以一起綁起來——
                            扣款的製程金額會把這幾張<b>一起加總</b>，責任製程也可以從這幾張的製程裡挑。
                            清單依這張單的料號自動列出（含組合件的子件），也可以打字搜尋。</div>
                        <div class="chips" id="bomChips"></div>
                        <div style="display:flex;gap:6px;align-items:center;margin-top:4px;">
                            <input type="text" id="bomKw" placeholder="輸入製令／料號／客戶搜尋…" style="flex:1;max-width:320px;">
                            <button class="btn btn-warm-o btn-xs" id="btnBomPick"><i class="fa fa-list"></i> 列出可綁的製令</button>
                        </div>
                        <div id="bomCandBox" style="display:none;margin-top:6px;max-height:190px;overflow:auto;border:1px solid var(--line);padding:6px;border-radius:4px;"></div>
                    </div>

                    <div style="margin-top:10px;border-top:1px dashed var(--line);padding-top:8px;">
                        <div class="muted-help" style="margin-bottom:4px;"><b>責任單位</b>：先選製程，再選廠商；選到的廠商若是<b>廠內加工廠商</b>（主檔管理→廠商編輯的「廠內加工廠商」）才會出現部門與人員（可複選、非必填）。</div>
                        <div class="fgrid">
                            <div class="fld"><label>製程 <span class="muted-help" id="procSrc"></span></label>
                                <select id="f_proc_pick" data-eg-skip style="margin-bottom:4px;display:none;"></select>
                                <div class="ac-wrap"><input type="text" id="f_proc" placeholder="輸入製程編號或名稱" autocomplete="off"></div>
                                <input type="hidden" id="f_proc_no"></div>
                            <div class="fld"><label>廠商 <span class="muted-help" id="vendorSrc"></span></label>
                                <div class="ac-wrap"><input type="text" id="f_vendor" placeholder="輸入廠商編號或名稱" autocomplete="off"></div>
                                <input type="hidden" id="f_vendor_id"><div class="ro-note" id="vendorNote"></div></div>
                        </div>
                        <div id="respPeopleBox" style="display:none;margin-top:8px;">
                            <div class="fgrid">
                                <div class="fld"><label>部門</label><select id="f_resp_dept"><option value="">選擇部門…</option></select></div>
                                <div class="fld"><label>人員（選填）</label>
                                    <div style="display:flex;gap:6px;">
                                        <select id="f_resp_user" style="flex:1;"><option value="">整個部門</option></select>
                                        <button class="btn btn-warm-o btn-xs" id="btnAddResp" style="white-space:nowrap;"><i class="fa fa-plus"></i> 加入</button>
                                    </div></div>
                            </div>
                            <div class="chips" id="respChips"></div>
                        </div>
                    </div>

                    <div style="margin-top:10px;border-top:1px dashed var(--line);padding-top:8px;">
                        <div class="muted-help" style="margin-bottom:4px;"><b>決策者</b>：這張單要送給誰做處置判定（業務主管／品管主管）；可選的範圍由管理員在清單頁的「設定」維護。主管無法決定時，在決策區勾「轉總經理裁示」即送最終決策者。</div>
                        <div class="fgrid">
                            <div class="fld"><label>決策者（部門／職稱）</label><select id="f_decider"><option value="">未指定</option></select></div>
                            <div class="fld"><label>指定人員（選填）</label><select id="f_decider_user"><option value="">該範圍任一人皆可</option></select></div>
                        </div>
                    </div>

                    <div style="margin-top:10px;">
                        <div class="muted-help" style="margin-bottom:3px;"><b>量測尺寸與實測值</b>（比照紙本三列 × 12 值，沒有量測值就留空）</div>
                        <table class="mtb" id="mtb">
                            <thead><tr><th style="width:16%">量測尺寸</th>
                                <?php for ($i = 1; $i <= 12; $i++) echo '<th>' . $i . '</th>'; ?></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>

                    <div class="fgrid" style="margin-top:10px;grid-template-columns:1fr;">
                        <div class="fld"><label>異常現象</label><textarea id="f_phe" rows="3"></textarea></div>
                    </div>
                    <div class="fgrid" style="grid-template-columns:1fr 1fr;">
                        <div class="fld"><label>原因分析</label><textarea id="f_detail" rows="2"></textarea></div>
                        <div class="fld"><label>品管備註</label><textarea id="f_qaps" rows="2"></textarea></div>
                    </div>
                </div>
            </div>

            <!-- ② 異常原因分類 -->
            <div class="sec" id="secCause">
                <h4><i class="fa fa-sitemap"></i> 異常原因分類
                    <span class="sub">可複選、結案前都可以改（後面的異常分析與報告會用這一欄）</span>
                    <span class="spacer"></span>
                    <span class="saved" id="savedCause"></span>
                </h4>
                <div class="sec-body">
                    <div class="ctree" id="causeTree"></div>
                    <div class="chips" id="causeChips"></div>
                </div>
            </div>

            <!-- ③ 相關單位意見（勾部門即可，一次可勾好幾個） -->
            <div class="sec" id="secRound">
                <h4><i class="fa fa-comments-o"></i> <span id="roundTitle">相關單位意見</span>
                    <span class="sub" id="roundSub">左側勾部門就會通知該部門的預設回覆職稱，多人只要一人回覆並簽章即可</span>
                    <span class="spacer"></span>
                    <span class="saved" id="savedRound"></span>
                    <button class="btn btn-warm btn-xs" id="btnAskSendAll"><i class="fa fa-paper-plane"></i> 送出勾選的徵詢</button>
                </h4>
                <div class="sec-body">
                    <div class="note-box" id="roundNote"></div>
                    <table class="dtb" id="askTb">
                        <thead><tr><th style="width:52px;">徵詢</th><th style="width:130px;">部門</th>
                            <th style="width:230px;">回覆職稱／指定人員</th><th style="width:96px;">狀態</th>
                            <th>回覆內容</th><th style="width:96px;">動作</th></tr></thead>
                        <tbody></tbody>
                    </table>
                    <div style="margin-top:6px;display:flex;gap:6px;align-items:center;">
                        <select id="askAddDept" data-eg-filter="輸入部門名稱篩選…" style="max-width:260px;"><option value="">加入其他部門…</option></select>
                        <span class="muted-help">沒有設定預設職稱的部門＝通知整個部門。</span>
                    </div>
                </div>
            </div>

            <!-- ④ 決策 -->
            <div class="sec" id="secDisp">
                <h4><i class="fa fa-gavel"></i> 異常處置方式
                    <span class="sub">(業務/品管) 主管決策</span>
                    <span class="spacer"></span>
                    <span class="saved" id="savedDisp"></span>
                </h4>
                <div class="sec-body">
                    <div id="dispNoPerm" class="note-box" style="display:none;color:var(--coral);"></div>
                    <div class="opts" id="dispOpts"></div>
                    <div class="fld" style="margin-top:8px;"><label>處置說明</label><textarea id="f_disp_note" rows="2"></textarea></div>
                    <div class="ro-note" id="dispWho"></div>
                </div>
            </div>

            <!-- ⑤ 總經理裁示 -->
            <div class="sec" id="secGm">
                <h4><i class="fa fa-university"></i> 總經理裁示
                    <span class="sub">有裁示時以裁示為最終決策</span>
                    <span class="spacer"></span>
                    <span class="saved" id="savedGm"></span>
                    <button class="btn btn-warm-o btn-xs" id="btnSaveGm" style="display:none;"></button>
                </h4>
                <div class="sec-body">
                    <div id="gmWho2" class="note-box"></div>
                    <div id="gmNoPerm" class="note-box" style="display:none;color:var(--coral);"></div>
                    <div class="opts" id="gmOpts"></div>
                    <div class="fgrid" style="margin-top:8px;grid-template-columns:1fr 240px;">
                        <div class="fld"><label>裁示說明</label><textarea id="f_gm_note" rows="2"></textarea></div>
                        <div class="fld"><label>矯正單號</label><input type="text" id="f_capa"></div>
                    </div>
                    <div class="ro-note" id="gmWho"></div>
                </div>
            </div>

            <!-- ⑥ 扣款確認 -->
            <div class="sec" id="secDeduct">
                <h4><i class="fa fa-calculator"></i> 扣款確認
                    <span class="sub">判定報廢、或總經理裁示勾了「扣款」時才要填</span>
                    <span class="spacer"></span>
                    <button class="btn btn-warm-o btn-xs" id="btnAutoPreview"><i class="fa fa-search"></i> 看自動帶入哪些金額</button>
                    <button class="btn btn-warm-o btn-xs" id="btnAutoFill"><i class="fa fa-download"></i> 自動帶入製程金額</button>
                    <span class="saved" id="savedDeduct"></span>
                    <button class="btn btn-warm-o btn-xs" id="btnSaveDeduct" title="欄位改完就會自動存，這顆只是要立刻存的時候用"><i class="fa fa-save"></i> 立即儲存</button>
                </h4>
                <div class="sec-body">
                    <div id="deductHint" class="note-box"></div>
                    <table class="dtb" id="dtb">
                        <thead><tr>
                            <th style="width:34px;">計入</th><th style="width:110px;">項目</th><th style="width:130px;">製程／名稱</th>
                            <th style="width:110px;">金額(未稅)</th><th>說明</th><th style="width:34px;"></th>
                        </tr></thead>
                        <tbody></tbody>
                    </table>
                    <div style="margin-top:6px;">
                        <button class="btn btn-warm-o btn-xs" id="btnAddOther"><i class="fa fa-plus"></i> 新增「其他」一列</button>
                        <span class="muted-help">　「其他」由生管或業務直接填金額與說明；製程列請用上方「自動帶入製程金額」。</span>
                    </div>
                    <div class="fgrid" style="margin-top:10px;">
                        <div class="fld"><label>加成（1.1＝總金額×110%）</label><input type="number" step="0.01" id="f_rate"></div>
                        <div class="fld"><label>數量 (PCS)</label><input type="number" step="0.001" id="f_dqty"></div>
                        <div class="fld"><label>核准扣款金額 (元/PCS)</label><input type="number" step="0.01" id="f_dunit"></div>
                        <div class="fld"><label>執行</label><input type="text" id="f_dexec"></div>
                        <div class="fld" style="grid-column:1/-1;"><label>通知扣款（移轉單號，可加註備註）</label><input type="text" id="f_dnotify" data-eg-hint="J-1130101001"></div>
                    </div>
                    <div class="sumline" id="dSum"></div>
                    <div style="margin-top:10px;border-top:1px dashed var(--line);padding-top:8px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                        <button class="btn btn-warm-o btn-xs" id="btnSignPm"><i class="fa fa-pencil"></i> (生管) 簽章</button>
                        <span class="ro-note" id="pmWho"></span>
                        <button class="btn btn-warm-o btn-xs" id="btnSignQc"><i class="fa fa-pencil"></i> (品管) 簽章</button>
                        <span class="ro-note" id="qcWho"></span>
                        <button class="btn btn-warm btn-xs" id="btnApprove"><i class="fa fa-check"></i> 核准扣款金額（管理課 會計／主管）</button>
                        <span class="ro-note" id="apprWho"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include '../partPage/footer.html'; ?>
</div></div>

<!-- 回覆 -->
<div class="m-mask" id="rplMask">
    <div class="m-box" style="width:560px;">
        <div class="m-hd"><i class="fa fa-reply"></i> 回覆相關單位意見<span class="x" data-close="rplMask">&times;</span></div>
        <div class="m-bd">
            <div class="fld"><label>回覆內容 <span style="color:var(--coral)">*</span></label><textarea id="r_content" rows="5"></textarea></div>
            <div class="err" id="rplErr"></div>
        </div>
        <div class="m-ft">
            <button class="btn btn-default btn-sm" data-close="rplMask">取消</button>
            <button class="btn btn-warm btn-sm" id="btnRplSend"><i class="fa fa-check"></i> 送出回覆</button>
        </div>
    </div>
</div>

<!-- 自動帶入預覽 -->
<div class="m-mask" id="pvMask">
    <div class="m-box" style="width:760px;">
        <div class="m-hd"><i class="fa fa-search"></i> 這張製令會自動帶入的製程金額<span class="x" data-close="pvMask">&times;</span></div>
        <div class="m-bd">
            <div class="note-box">來源＝製程移轉一覽表讀的同一張表（<code>bom_ing_transfer_log</code>）。金額優先取憑單上的加工金額，為 0 才用「數量 × 單價」回推。</div>
            <div id="pvBody"></div>
        </div>
        <div class="m-ft"><button class="btn btn-default btn-sm" data-close="pvMask">關閉</button></div>
    </div>
</div>

<!-- 使用說明（鐵律7） -->
<div class="m-mask" id="helpUseMask">
    <div class="m-box" style="width:820px;">
        <div class="m-hd"><i class="fa fa-question-circle"></i> 使用說明－品質異常處理單<span class="x" data-close="helpUseMask">&times;</span></div>
        <div class="m-bd help-doc">
            <h4>這一頁在做什麼</h4>
            <p>紙本 <b>2-QA-01-01 品質異常處理單</b> 的線上版。一張單從開立、徵詢相關單位意見、主管決策、總經理裁示、扣款確認到結案都在這一頁完成，列印版與紙本欄位一致。</p>
            <h4>操作步驟</h4>
            <ul>
                <li><b>① 基本資料</b>：填表頭、責任單位（先製程再廠商；廠商是「廠內加工廠商」時才可再指定部門與人員）、量測值與異常現象，按「儲存填寫內容」。</li>
                <li><b>② 異常原因分類</b>：勾選分類（可複選、可到第三層）。<b>結案前都能改</b>，一開始判斷錯了可以回來修正。</li>
                <li><b>③ 相關單位意見</b>：按「送出徵詢」選一個部門（可指定職稱或某一位）→ 對方收到通知後到這一頁回覆 → 再決定下一個問誰，或直接進決策。<b>同一時間只會有一個未回覆的徵詢。</b></li>
                <li><b>④ 異常處置方式</b>：由決策者（業務／品管主管）勾選。主管無法決定時勾「轉總經理裁示」，系統會通知最終決策者。</li>
                <li><b>⑤ 總經理裁示</b>：有裁示時<b>以裁示為最終決策</b>（優先於主管的處置方式）。裁示區的「扣款」勾了就可以填左下角的扣款確認，與報廢與否無關。</li>
                <li><b>⑥ 扣款確認</b>：有綁製令時按「自動帶入製程金額」，可先按「看自動帶入哪些金額」確認來源；每一列都能改金額或取消計入，下方加成（例 1.1＝×110%）只作用在製程小計。「其他」列由生管或業務自行填金額與說明，合計自動加總。</li>
                <li><b>補舊資料</b>：填寫日期在<b>今天往前 N 天（預設 10 天）以前</b>的單，系統自動視為「補資料」。
                    這種單會多出「補登簽章」區，<b>只有「異常單管理員」</b>可以逐格指定<b>當時是誰簽的、印章蓋哪一天</b>；
                    人員清單依印章日期回推當時在職者（當時在職、現已離職的人也選得到），印章日期不可以是未來。
                    相關單位意見在補資料模式下也改成直接補登（填回覆人、回覆日期與內容，<b>不會發通知</b>）。天數可在設定調整。</li>
                <li><b>⑦ 結案</b>：原因分類與處置（或裁示）都要先選好、沒有未回覆的徵詢才能結案。<b>最終決策含「報廢」時，結案當下自動配發報廢單號</b>（F＋民國年3碼＋MMDD＋流水3碼）並寫入資料庫，此號需登記到不合格品管制記錄表。</li>
            </ul>
            <h4>重要行為</h4>
            <ul>
                <li><b>製令編號與客退單號一定要從清單選</b>：只打字不選就存不進去（客戶、料號、扣款金額都是靠這個綁定帶出來的）。
                    同一個客退單號可能有好幾筆明細，所以一定要選到是哪一筆。</li>
                <li><b>檢驗數</b>依線上檢驗的<b>抽樣規則</b>自動帶建議值（與 QC 用同一份規則）；自己改過就不再自動蓋掉。
                    填了不良數會即時算出<b>不良率</b>。</li>
                <li><b>客戶與料號不給手打</b>：綁了製令或客退單，兩者都由來源的<b>料號主檔</b>自動帶（同一個料號文字在主檔常分屬好幾家客戶，手打一定會歪）；兩者都沒綁才可以自行填。</li>
                <li>已結案的單一律不可修改，要改請管理員先「取消結案」；<b>取消結案不會收回已配發的報廢單號</b>（號碼可能已被其他單據引用）。</li>
                <li>所有選項（原因分類／處置方式／總經理裁示）都<b>存 id 不存文字</b>，管理員改名不會讓舊單失去連動。</li>
                <li>本單的狀態與內容會自動出現在<b>不合格品管制記錄表</b>（2-QA-01-03），那一頁只顯示、不可修改。</li>
            </ul>
            <h4>設定入口</h4>
            <ul>
                <li>清單頁右上「設定」（限管理員）：異常原因分類三層、異常處置方式、總經理裁示選項、決策者可選的部門與職稱、扣款加成預設值、AS 文件綁定。</li>
                <li>廠商是否為「廠內加工廠商」＝主檔管理 → 廠商編輯 → 勾選「廠內加工廠商」。</li>
            </ul>
            <h4>權限角色</h4>
            <ul>
                <li><b>開單／填寫</b>：品管或業務部門成員、或指派 <code>qab_fill</code> 角色。</li>
                <li><b>決策主管</b>：落在管理員設定的「決策者」部門＋職稱範圍內，或指派 <code>qab_decide</code>。</li>
                <li><b>最終決策者</b>：組織角色的「最高核准人員」，或設定為「最高決策者」範圍，或指派 <code>qab_gm</code>。</li>
                <li><b>扣款填寫</b>：生管或業務部門，或 <code>qab_deduct_fill</code>；<b>扣款核准</b>：會計部門，或 <code>qab_deduct_approve</code>。</li>
            </ul>
        </div>
        <div class="m-ft"><button class="btn btn-default btn-sm" data-close="helpUseMask">關閉</button></div>
    </div>
</div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_date_fmt.js') ?>"></script>
<script>
var API  = '../../src/store/QaAbnormal_API.php';
var CSRF = '<?= $CSRF ?>';
var OID  = <?= (int)$oid ?>;
var D = null;          // 後端回來的整包（order / perms / 代碼表）
var CAUSE_SEL = [];    // 目前勾選的原因分類 id
var DEDUCT_ROWS = [];  // 畫面上的扣款列
var RPL_FLOW = 0;
var BOM_OK = false;                    // 製令欄位現在的值是不是「從清單選到的」

function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }
function dispDate(s){ try { return window.egFmtDate ? egFmtDate(s) : (s || ''); } catch(e){ return s || ''; } }
function openMask(id){ $('#' + id).show(); }
function closeMask(id){ $('#' + id).hide(); }
$(document).on('click', '[data-close]', function(){ closeMask($(this).data('close')); });

/* quiet=true：自動存檔用——存好只更新資料、**不重畫畫面**。
   重畫會把使用者正在打字的欄位連同游標一起洗掉（自動存檔一定要避開這件事）。 */
function post(action, data, cb, quiet){
    data = data || {};
    data.action = action; data.csrf = CSRF;
    $.post(API, data, function(res){
        if (!res || !res.success) { alert((res && res.message) || '操作失敗'); return; }
        if (res.order) { D.order = res.order; if (!quiet) render(); }
        if (cb) cb(res);
    }, 'json').fail(function(){ alert('連線失敗，請稍後再試'); });
}

/* ───────── 載入 ───────── */
function load(){
    $.get(API, { action:'get', id:OID }, function(res){
        if (!res || !res.success) { $('#loadBox').text((res && res.message) || '載入失敗'); return; }
        D = res;
        $('#loadBox').hide(); $('#formBox').show();
        buildStaticOpts();
        render();
    }, 'json').fail(function(){ $('#loadBox').text('連線失敗'); });
}

function buildStaticOpts(){
    // 決策者下拉
    var h = '<option value="">未指定</option>';
    (D.deciders || []).forEach(function(c){ h += '<option value="' + c.cfg_id + '">' + esc(c.show_name) + '</option>'; });
    $('#f_decider').html(h);
    // 原因分類樹
    var t = '';
    function walk(nodes, lv){
        nodes.forEach(function(n){
            t += '<div class="lv' + lv + '"><label><input type="checkbox" class="cchk" value="' + n.cat_id + '"> ' + esc(n.name) + '</label></div>';
            if (n.children && n.children.length) walk(n.children, Math.min(lv + 1, 3));
        });
    }
    walk(D.causes || [], 1);
    $('#causeTree').html(t || '<span class="muted-help">管理員尚未建立任何異常原因分類（清單頁 → 設定）</span>');
    // 處置／裁示選項
    $('#dispOpts').html((D.disp_opts || []).map(function(o){
        return '<label data-opt="' + o.opt_id + '"><input type="checkbox" class="dchk" value="' + o.opt_id + '"> ' + esc(o.name) + '</label>';
    }).join(''));
    $('#gmOpts').html((D.gm_opts || []).map(function(o){
        return '<label data-opt="' + o.opt_id + '"><input type="checkbox" class="gchk" value="' + o.opt_id + '"> ' + esc(o.name) + '</label>';
    }).join('') + '<label data-opt="deduct" style="border-color:var(--coral);"><input type="checkbox" id="g_deduct"> 扣款</label>');
    // 量測三列
    var mt = '';
    for (var r = 0; r < 3; r++){
        mt += '<tr><td><input type="text" class="m-dim" data-r="' + r + '"></td>';
        for (var i = 0; i < 12; i++) mt += '<td><input type="text" class="m-val" data-r="' + r + '" data-i="' + i + '"></td>';
        mt += '</tr>';
    }
    $('#mtb tbody').html(mt);
    // 部門下拉（徵詢用）
    $.get(API, { action:'depts' }, function(res){
        if (!res || !res.success) return;
        var hh = '<option value="">請選擇…</option>';
        res.rows.forEach(function(d){ hh += '<option value="' + d.id + '">' + esc(d.department_name) + '</option>'; });
        var keep = $('#f_resp_dept').val();
        $('#f_resp_dept').html(hh.replace('請選擇…', '選擇部門…')).val(keep);
    }, 'json');
}

/* ───────── 畫面 ───────── */
function render(){
    var o = D.order, p = D.perms, canEdit = D.can_edit && !o.is_closed;
    $('#noTag').text(o.abnormal_order_no || '');
    document.title = '品質異常處理單 ' + (o.abnormal_order_no || '');

    var st = o.status || { code:'', label:'' };
    $('#stBox').html('<span class="st st-' + st.code + '">' + esc(st.label) + '</span>'
        + (o.final && o.final.names.length ? ' <span class="st">最終處置：' + esc(o.final.names.join('、'))
            + (o.final.from === 'gm' ? '（總經理裁示）' : '（主管處置）') + '</span>' : ''));
    $('#scrapBox').html(o.scrap_no ? '<span class="st" style="background:var(--coral);color:#fff;">報廢單號 ' + esc(o.scrap_no) + '</span>' : '');
    $('#btnClose2').toggle(!o.is_closed);
    $('#btnReopen').toggle(!!o.is_closed && !!p.canAdmin);

    // ① 表頭
    $('#f_fill_date').val(o.fill_date || '');
    $('#f_occ_date').val(o.occurrence_date || '');
    $('#f_client').val(o.client_name || '');
    $('#f_part').val(o.part_no || '');
    $('#f_bom').val(o.bom_no || '');
    // 客戶由來源綁定：綁了製令或客退單就唯讀（同一個料號文字在料號主檔常分屬不同客戶，手打一定會歪）
    var bound = Number(o.client_bound) === 1;
    $('#f_client').prop('readonly', bound);
    // 料號跟客戶一樣：綁了來源就由來源決定並鎖起來（使用者要求）
    $('#f_part').prop('readonly', bound);
    $('#partSrc').text(bound ? ('（由' + (o.ir_id ? '客退單' : '製令') + '自動綁定'
        + (o.part_d_id ? '：主檔 #' + o.part_d_id : '') + '）') : '（未綁來源，可自行填寫）');
    $('#clientSrc').text(bound ? ('（由' + (o.ir_id ? '客退單' : '製令') + '自動綁定'
        + (o.client_id ? '：' + o.client_id : '') + '，要改請改上面的來源單號）') : '（未綁來源，可自行填寫）');
    $('#f_ir').val(o.ir_no || '');
    $('#f_ir_id').val(o.ir_id || '');
    BOM_OK = !!(o.bom_no || '');          // 存在資料庫裡的一定是綁定過的
    $('#bomErr,#irErr').hide();
    $('#f_batch').val(o.batch_qty == null ? '' : o.batch_qty);
    $('#f_insp').val(o.insp_qty == null ? '' : o.insp_qty);
    $('#f_ng').val(o.ng_qty == null ? '' : o.ng_qty);
    calcRate();
    refreshSampleHint(false);
    $('#f_proc_no').val(o.resp_process_no || '');
    $('#f_proc').val(o.resp_process_name || '');
    $('#f_vendor_id').val(o.responsible_vendor_id || '');
    $('#f_vendor').val(o.resp_vendor_name || '');
    $('#vendorNote').html(Number(o.resp_is_internal) === 1
        ? '<span style="color:var(--amber-d);"><i class="fa fa-home"></i> 廠內加工廠商 — 可再指定部門與人員</span>' : '');
    $('#respPeopleBox').toggle(Number(o.resp_is_internal) === 1);
    renderRespChips();
    $('#f_decider').val(o.decider_cfg_id || '');
    loadDeciderUsers(o.decider_user_id || '');
    $('#f_phe').val(o.abnormal_phenomenon || '');
    $('#f_detail').val(o.defect_detail || '');
    $('#f_qaps').val(o.qa_ps || '');
    (o.measures || []).forEach(function(m){
        var r = m.seq - 1;
        $('.m-dim[data-r="' + r + '"]').val(m.dim_name || '');
        (m.vals || []).forEach(function(v, i){ $('.m-val[data-r="' + r + '"][data-i="' + i + '"]').val(v); });
    });
    // 補資料模式（業務日期在 N 天以前）
    var bf = Number(o.is_backfill) === 1;
    $('#bfBox').toggle(bf).html(!bf ? '' :
        ('<b><i class="fa fa-clock-o"></i> 這是補資料</b>：填寫日期 ' + dispDate(o.fill_date)
         + ' 已超過 ' + o.backfill_days + ' 天，'
         + (p.canBackfill ? '可以在下方「補登簽章」逐格指定當時是誰簽的、蓋哪一天；相關單位意見也改成直接補登（不發通知）。'
                          : '只有「異常單管理員」可以補登簽章與補登單位意見。')));
    $('#secSign').toggle(bf && !!p.canBackfill && !o.is_closed);
    if (bf && p.canBackfill) renderSignTable();

    renderBoms();
    renderProcPick();

    $('#secHead').toggleClass('locked', !canEdit);
    $('#secHead input,#secHead textarea,#secHead select').prop('disabled', !canEdit);
    if (bound) $('#f_client').prop('readonly', true);
    $('#btnSaveHead,#btnAddResp').toggle(canEdit);

    // ② 原因分類
    CAUSE_SEL = (o.cause_ids || []).slice();
    $('.cchk').prop('checked', false).prop('disabled', !(canEdit || (p.canDecide && !o.is_closed)));
    CAUSE_SEL.forEach(function(id){ $('.cchk[value="' + id + '"]').prop('checked', true); });
    renderCauseChips();
    $('#btnSaveCause').toggle(!o.is_closed && (D.can_edit || p.canDecide));

    // ③ 相關單位意見
    renderRounds();

    // ④ 決策
    $('.dchk').prop('checked', false);
    (o.disp_ids || []).forEach(function(id){ $('.dchk[value="' + id + '"]').prop('checked', true); });
    syncOptStyle();
    $('#f_disp_note').val(o.disposition_note || '');
    var canDisp = p.canDecide && !o.is_closed;
    $('#dispOpts input,#f_disp_note').prop('disabled', !canDisp);
    $('#btnSaveDisp').toggle(canDisp);
    $('#dispNoPerm').toggle(!p.canDecide).text('您不在可決策的名單內（由管理員在清單頁「設定 → 決策者」指定部門與職稱）。');
    $('#dispWho').text(o.decided_name ? ('決策：' + o.decided_name + '　' + dispDate(o.disp_decided_at)) : '');

    // ⑤ 總經理裁示
    $('.gchk').prop('checked', false);
    (o.gm_ids || []).forEach(function(id){ $('.gchk[value="' + id + '"]').prop('checked', true); });
    $('#g_deduct').prop('checked', Number(o.gm_deduct) === 1);
    syncOptStyle();
    $('#f_gm_note').val(o.gm_note || '');
    $('#f_capa').val(o.capa_order_no || '');
    var canGm = p.canGm && !o.is_closed;
    $('#gmOpts input,#f_gm_note,#f_capa').prop('disabled', !canGm);
    $('#btnSaveGm').toggle(canGm);
    var gp = o.gm_person || D.gm_person || {};
    $('#gmWho2').html(!gp.bound
        ? '<span style="color:var(--coral);">全站的「組織角色綁定 → 最高核准人員」還沒設定，所以現在沒有人可以做最終裁示。</span>'
        : ('最終決策者：<b>' + esc(gp.name || '') + '</b>'
           + (gp.is_delegated ? '（' + esc(gp.base_name || '') + ' 目前不在，由代理人簽，圖章會加「代」字）' : '')
           + '　<span class="muted-help">取自全站統一的「組織角色綁定 → 最高核准人員」，要換人請到那一頁改，本模組不另外設定。</span>'));
    $('#gmNoPerm').toggle(!p.canGm).text('您不是最終決策者（最終決策者＝全站「組織角色綁定」的最高核准人員，或其代理人）。');
    $('#gmWho').text(o.gm_name ? ('裁示：' + o.gm_name + '　' + dispDate(o.gm_decided_at)) : '');

    // ⑥ 扣款
    DEDUCT_ROWS = (o.deducts || []).map(function(r){ return $.extend({}, r); });
    $('#f_rate').val(o.surcharge_rate == null ? (D.rate_default || 1) : o.surcharge_rate);
    $('#f_dqty').val(o.deduct_qty == null ? '' : o.deduct_qty);
    $('#f_dunit').val(o.deduct_unit_amt == null ? '' : o.deduct_unit_amt);
    $('#f_dexec').val(o.deduct_exec || '');
    $('#f_dnotify').val(o.deduct_notify_no || '');
    renderDeduct();
}

/* ───────── 自動存檔（使用者要求：輸入完就存，不要存檔鈕，免得忘記按） ─────────
   同一個區塊連續改很多欄時只送最後一次；存好在該區塊標題右邊標「已自動儲存 hh:mm:ss」。 */
var AS_TIMER = {};
function autoSave(key, fn, ms){
    clearTimeout(AS_TIMER[key]);
    AS_TIMER[key] = setTimeout(fn, ms === undefined ? 700 : ms);
}
function savedAt(sel){
    var t = new Date();
    $(sel).text('已自動儲存 ' + ('0' + t.getHours()).slice(-2) + ':' + ('0' + t.getMinutes()).slice(-2) + ':' + ('0' + t.getSeconds()).slice(-2));
}

/* ───────── 製令：可綁多張（退貨的是組合件時底下好幾張） ───────── */
function renderBoms(){
    var o = D.order, canEdit = D.can_edit && !o.is_closed;
    var main = (o.bom_no || '').trim();
    var extra = (o.bom_list || []).filter(function(b){ return b !== main; });
    var h = '';
    if (main) h += '<span class="bom-chip"><b>主</b> ' + esc(main) + '</span>';
    extra.forEach(function(b){
        h += '<span class="bom-chip">' + esc(b)
           + (canEdit ? ('<span class="x" data-bomdel="' + esc(b) + '" title="解除綁定">&times;</span>') : '') + '</span>';
    });
    if (!h) h = '<span class="muted-help">尚未綁定任何製令</span>';
    $('#bomChips').html(h);
    $('#bomMoreBox').toggle(!!main || extra.length > 0 || canEdit);
    $('#btnBomPick,#bomKw').prop('disabled', !canEdit);
}
function bindBoms(list){
    post('bom_bind', { id:OID, boms:JSON.stringify(list) }, function(){ toast('製令綁定已更新'); savedAt('#savedHead'); });
}
$('#btnBomPick').on('click', function(){
    var $box = $('#bomCandBox');
    $box.show().html('<span class="muted-help">查詢中…</span>');
    $.get(API, { action:'bom_candidates', id:OID, kw:$('#bomKw').val() }, function(res){
        var rows = (res && res.rows) || [];
        if (!rows.length) { $box.html('<span class="muted-help">查不到相關製令（可以改用上面的關鍵字搜尋）</span>'); return; }
        var cur = D.order.bom_list || [];
        $box.html(rows.map(function(r){
            return '<label style="display:block;font-weight:normal;font-size:12px;margin:1px 0;">'
                 + '<input type="checkbox" class="bom-cand" value="' + esc(r.bom) + '"'
                 + (cur.indexOf(r.bom) >= 0 ? ' checked' : '') + '> '
                 + '<b>' + esc(r.bom) + '</b>　' + esc(r.d_id || '') + '　' + esc(r.Client_Name || '')
                 + '　<span class="muted-help">' + esc(r.rel_note) + (r.sqty ? ('／' + r.sqty + ' 支') : '') + '</span></label>';
        }).join('') + '<div style="margin-top:6px;"><button class="btn btn-warm btn-xs" id="btnBomApply">套用勾選的製令</button> '
          + '<span class="muted-help">主製令（表頭那一張）一律保留。</span></div>');
    }, 'json');
});
$('#bomKw').on('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); $('#btnBomPick').click(); } });
$(document).on('click', '#btnBomApply', function(){
    var list = $('.bom-cand:checked').map(function(){ return this.value; }).get();
    var main = (D.order.bom_no || '').trim();
    if (main && list.indexOf(main) < 0) list.unshift(main);
    bindBoms(list);
    $('#bomCandBox').hide();
});
$(document).on('click', '[data-bomdel]', function(){
    var b = $(this).data('bomdel');
    bindBoms((D.order.bom_list || []).filter(function(x){ return x !== b; }));
});

/* ───────── 責任單位：有綁製令時，製程直接從製令的製程挑，廠商跟著自動帶 ───────── */
function renderProcPick(){
    var o = D.order, rows = o.bom_processes || [];
    var $sel = $('#f_proc_pick');
    if (!rows.length) { $sel.hide(); $('#procSrc').text(''); return; }
    var multi = (o.bom_list || []).length > 1;
    var h = '<option value="">從製令的製程挑…（' + rows.length + ' 站）</option>';
    rows.forEach(function(r, i){
        h += '<option value="' + i + '"' + (Number(r.process_no) === Number(o.resp_process_no) ? ' selected' : '') + '>'
           + esc((multi ? (r.bom + '　') : '') + '第' + r.bom_sn + '站　' + (r.process_name || '(未命名製程)')
                 + (r.vendor_name ? ('　' + r.vendor_name) : '')) + '</option>';
    });
    $sel.html(h).show();
    $('#procSrc').text(Number(o.resp_process_manual) ? '（人工指定，不在製令的製程裡）' : '');
    $('#vendorSrc').html(Number(o.resp_vendor_manual)
        ? '<span style="color:var(--coral);">（人工修改，非製令自動帶入）</span>'
        : (o.responsible_vendor_id ? '（由製令的該站自動帶）' : ''));
}
$(document).on('change', '#f_proc_pick', function(){
    var i = $(this).val();
    if (i === '') return;
    var r = (D.order.bom_processes || [])[Number(i)];
    if (!r) return;
    $('#f_proc').val(r.process_name || '');
    $('#f_proc_no').val(r.process_no || '');
    if (r.vendor_id) { $('#f_vendor').val(r.vendor_name || ''); $('#f_vendor_id').val(r.vendor_id); }
    autoSave('head', saveHead);
});

/* ───────── 補登簽章（結果與簽章一次補完） ───────── */
var PEOPLE_CACHE = {};                 // 日期 -> 當時在職人員（一天只跟後端要一次）
function peopleAsOf(date, cb){
    if (PEOPLE_CACHE[date]) { cb(PEOPLE_CACHE[date]); return; }
    $.get(API, { action:'people_asof', date:date }, function(res){
        PEOPLE_CACHE[date] = (res && res.rows) || [];
        cb(PEOPLE_CACHE[date]);
    }, 'json');
}
function fillPeopleSelect($sel, date, sel){
    peopleAsOf(date, function(rows){
        var h = '<option value="">請選擇…</option>';
        rows.forEach(function(u){
            h += '<option value="' + u.id + '"' + (String(u.id) === String(sel || '') ? ' selected' : '') + '>'
               + esc((u.dept_name || '') + '　' + (u.position_name || '') + '　' + u.name) + '</option>';
        });
        $sel.html(h);
    });
}
/* 每個簽章格的候選人：後端依「這一格該簽的部門＋那天在職＋那天沒請整天假／整天外出」篩好 */
function fillSlotPeople($sel, slot, date, sel, $note){
    $sel.html('<option value="">載入中…</option>');
    $.get(API, { action:'sign_candidates', id:OID, slot:slot, date:date, all:$('#sgAll').prop('checked') ? 1 : '' }, function(res){
        var rows = (res && res.rows) || [];
        var h = '<option value="">請選擇…</option>';
        rows.forEach(function(u){
            h += '<option value="' + u.id + '"' + (String(u.id) === String(sel || '') ? ' selected' : '') + '>'
               + esc((u.dept_name || '') + '　' + (u.position_name || '') + '　' + u.name) + '</option>';
        });
        $sel.html(h);
        if ($note) {
            var scope = (res && res.scope) || [];
            $note.text(!rows.length
                ? (scope.length ? ('「' + scope.join('、') + '」當天沒有可簽的人，可勾上面「顯示全部人員」') : '當天沒有可簽的人')
                : (scope.length ? ('範圍：' + scope.join('、')) : '不限部門'));
        }
    }, 'json');
}
/* 這一格要不要多出「結果與內容」（使用者要求：補登時不要再跑到下面的決策區填一次） */
function slotResultHtml(k){
    var o = D.order;
    if (k === 'disp') {
        return '<div class="sg-res">' + (D.disp_opts || []).map(function(op){
                return '<label><input type="checkbox" class="sg-disp" value="' + op.opt_id + '"'
                     + ((o.disp_ids || []).indexOf(Number(op.opt_id)) >= 0 ? ' checked' : '') + '> ' + esc(op.name) + '</label>'; }).join('')
             + '</div><textarea class="sg-dispnote" rows="2" placeholder="處置說明">' + esc(o.disposition_note || '') + '</textarea>';
    }
    if (k === 'gm') {
        return '<div class="sg-res">' + (D.gm_opts || []).map(function(op){
                return '<label><input type="checkbox" class="sg-gm" value="' + op.opt_id + '"'
                     + ((o.gm_ids || []).indexOf(Number(op.opt_id)) >= 0 ? ' checked' : '') + '> ' + esc(op.name) + '</label>'; }).join('')
             + '<label style="margin-left:6px;"><input type="checkbox" class="sg-gmded"' + (Number(o.gm_deduct) ? ' checked' : '') + '> 需扣款</label>'
             + '</div><textarea class="sg-gmnote" rows="2" placeholder="裁示說明">' + esc(o.gm_note || '') + '</textarea>';
    }
    return '<span class="muted-help">（這一格只有簽章）</span>';
}
function renderSignTable(){
    var o = D.order, biz = o.fill_date || '';
    var h = '';
    Object.keys(o.signs || {}).forEach(function(k){
        var sg = o.signs[k];
        var d = sg.at ? String(sg.at).substring(0, 10) : biz;
        h += '<tr data-slot="' + k + '">'
           + '<td>' + esc(sg.label) + '</td>'
           + '<td>' + (sg.name ? (esc(sg.name) + '<br><span class="muted-help">' + dispDate(sg.at) + '</span>')
                              : '<span class="muted-help">（未簽）</span>') + '</td>'
           + '<td><input type="date" class="sg-date" value="' + esc(d) + '"></td>'
           + '<td><select class="sg-who" data-eg-skip><option value="">載入中…</option></select>'
           + '<div class="muted-help sg-scope" style="font-size:11px;"></div></td>'
           + '<td>' + slotResultHtml(k) + '</td>'
           + '<td class="c">' + (sg.user_id ? '<button class="btn btn-warm-o btn-xs sg-clear">清除</button>' : '<span class="muted-help">自動存</span>') + '</td></tr>';
    });
    $('#signTb tbody').html(h);
    $('#signTb tbody tr').each(function(){
        var $tr = $(this), k = $tr.data('slot');
        fillSlotPeople($tr.find('.sg-who'), k, $tr.find('.sg-date').val() || biz,
                       (D.order.signs[k] || {}).user_id, $tr.find('.sg-scope'));
    });
}
$(document).on('change', '#sgAll', function(){ renderSignTable(); });
$(document).on('change', '.sg-date', function(){
    var $tr = $(this).closest('tr');
    if (!this.value) return;
    fillSlotPeople($tr.find('.sg-who'), $tr.data('slot'), this.value, $tr.find('.sg-who').val(), $tr.find('.sg-scope'));
});
/* 日期、人員、結果任何一個改了就自動存（補登不必按鈕） */
function saveSignRow($tr){
    var slot = $tr.data('slot'), date = $tr.find('.sg-date').val(), who = $tr.find('.sg-who').val();
    if (!date) return;
    if (slot === 'disp') {
        var ids = $tr.find('.sg-disp:checked').map(function(){ return Number(this.value); }).get();
        post('save_disposition', { id:OID, opt_ids:JSON.stringify(ids), disposition_note:$tr.find('.sg-dispnote').val(),
                                   sign_date:date, sign_by:who || '' },
            function(){ savedAt('#savedSign'); }, true);
        return;
    }
    if (slot === 'gm') {
        var gids = $tr.find('.sg-gm:checked').map(function(){ return Number(this.value); }).get();
        post('save_gm', { id:OID, opt_ids:JSON.stringify(gids), gm_note:$tr.find('.sg-gmnote').val(),
                          gm_deduct:$tr.find('.sg-gmded').prop('checked') ? 1 : '',
                          sign_date:date, sign_by:who || '' },
            function(){ savedAt('#savedSign'); }, true);
        return;
    }
    if (!who) return;                    // 其他格只有簽章，沒選人就先不存
    post('sign_set', { id:OID, slot:slot, date:date, user_id:who }, function(){ savedAt('#savedSign'); }, true);
}
$(document).on('change', '#signTb .sg-date, #signTb .sg-who, #signTb .sg-disp, #signTb .sg-gm, #signTb .sg-gmded', function(){
    var $tr = $(this).closest('tr');
    autoSave('sign' + $tr.data('slot'), function(){ saveSignRow($tr); });
});
$(document).on('input', '#signTb .sg-dispnote, #signTb .sg-gmnote', function(){
    var $tr = $(this).closest('tr');
    autoSave('sign' + $tr.data('slot'), function(){ saveSignRow($tr); }, 1200);
});
$(document).on('click', '.sg-clear', function(){
    post('sign_set', { id:OID, slot:$(this).closest('tr').data('slot'), clear:1 }, function(){ toast('已清除'); });
});

/* 檢驗數：依線上檢驗的「抽樣規則設定」自動帶建議值（同一支 qc_suggest_sample_qty）。
   人工改過就不再自動蓋掉，只留建議值提示——那是他刻意填的數字。 */
var SAMPLE_TOUCHED = false, SAMPLE_LAST = null;
function refreshSampleHint(autoFill){
    var q = parseInt($('#f_batch').val(), 10);
    if (!(q > 0)) { $('#sampleHint').text(''); return; }
    $.get(API, { action:'suggest_sample', qty:q }, function(res){
        if (!res || !res.success) return;
        var sug = Number(res.sample) || 0;
        $('#sampleHint').text(sug ? ('（抽樣規則建議 ' + sug + ' 件）') : '');
        var cur = $('#f_insp').val();
        if (autoFill && sug && (!cur || !SAMPLE_TOUCHED || String(cur) === String(SAMPLE_LAST))) {
            $('#f_insp').val(sug);
            SAMPLE_LAST = sug;
            calcRate();
        }
        if (SAMPLE_LAST === null) SAMPLE_LAST = sug;
    }, 'json');
}
$(document).on('change', '#f_batch', function(){ refreshSampleHint(true); });
$(document).on('input', '#f_insp', function(){ SAMPLE_TOUCHED = true; });

function calcRate(){
    var iq = parseFloat($('#f_insp').val()), ng = parseFloat($('#f_ng').val());
    if (!(iq > 0) || isNaN(ng)) { $('#ngRate').text(''); return; }
    var pct = ng / iq * 100;
    $('#ngRate').html('不良率 <b style="color:' + (pct > 0 ? 'var(--coral)' : 'var(--ink2)') + ';">'
        + pct.toFixed(2) + '%</b>（' + ng + ' / ' + iq + '）');
}
$(document).on('input', '#f_insp,#f_ng', calcRate);

function syncOptStyle(){
    $('.opts label').each(function(){ $(this).toggleClass('on', $(this).find('input').prop('checked')); });
}
$(document).on('change', '.opts input', syncOptStyle);

/* 原因分類 */
$(document).on('change', '.cchk', function(){
    var id = parseInt($(this).val(), 10);
    if ($(this).prop('checked')) { if (CAUSE_SEL.indexOf(id) < 0) CAUSE_SEL.push(id); }
    else CAUSE_SEL = CAUSE_SEL.filter(function(x){ return x !== id; });
    renderCauseChips();
});
function causePath(id){
    var found = '';
    function walk(nodes, prefix){
        nodes.forEach(function(n){
            var p = prefix ? (prefix + ' → ' + n.name) : n.name;
            if (Number(n.cat_id) === Number(id)) found = p;
            if (n.children) walk(n.children, p);
        });
    }
    walk(D.causes || [], '');
    return found || ('#' + id);
}
function renderCauseChips(){
    $('#causeChips').html(CAUSE_SEL.length
        ? CAUSE_SEL.map(function(id){ return '<span class="chip">' + esc(causePath(id)) + '</span>'; }).join('')
        : '<span class="muted-help">尚未勾選（結案前一定要勾）</span>');
}
function saveCause(){
    post('save_cause', { id:OID, cause_ids: JSON.stringify(CAUSE_SEL) }, function(){ savedAt('#savedCause'); }, true);
}
$(document).on('change', '.cchk', function(){ autoSave('cause', saveCause, 400); });

/* 責任單位：部門／人員 */
var RESP = [];
function renderRespChips(){
    RESP = (D.order.resp_people || []).map(function(r){
        return { dept_id:Number(r.dept_id), user_id: r.user_id ? Number(r.user_id) : 0,
                 label: (r.department_name || '') + (r.user_cname ? ('　' + r.user_cname) : '（整個部門）') };
    });
    drawResp();
}
function drawResp(){
    $('#respChips').html(RESP.length ? RESP.map(function(r, i){
        return '<span class="chip">' + esc(r.label) + (D.can_edit && !D.order.is_closed ? ' <span class="x" data-resp="' + i + '">×</span>' : '') + '</span>';
    }).join('') : '<span class="muted-help">未指定（非必填）</span>');
}
$(document).on('click', '[data-resp]', function(){ RESP.splice(parseInt($(this).data('resp'), 10), 1); drawResp(); });
$('#f_resp_dept').on('change', function(){
    var d = $(this).val();
    $('#f_resp_user').html('<option value="">整個部門</option>');
    if (!d) return;
    $.get(API, { action:'dept_people', dept_id:d }, function(res){
        if (!res || !res.success) return;
        var h = '<option value="">整個部門</option>';
        res.rows.forEach(function(u){ h += '<option value="' + u.id + '">' + esc(u.name + (u.position_name ? '（' + u.position_name + '）' : '')) + '</option>'; });
        $('#f_resp_user').html(h);
    }, 'json');
});
$('#btnAddResp').on('click', function(){
    var d = $('#f_resp_dept').val(), u = $('#f_resp_user').val();
    if (!d) { alert('請先選部門'); return; }
    var label = $('#f_resp_dept option:selected').text() + (u ? ('　' + $('#f_resp_user option:selected').text()) : '（整個部門）');
    if (RESP.some(function(r){ return r.dept_id === Number(d) && r.user_id === Number(u || 0); })) return;
    RESP.push({ dept_id:Number(d), user_id:Number(u || 0), label:label });
    drawResp();
});

/* 決策者指定人員 */
$('#f_decider').on('change', function(){ loadDeciderUsers(''); });
function loadDeciderUsers(sel){
    var cfg = $('#f_decider').val();
    if (!cfg) { $('#f_decider_user').html('<option value="">該範圍任一人皆可</option>'); return; }
    $.get(API, { action:'decider_people', cfg_id:cfg }, function(res){
        var h = '<option value="">該範圍任一人皆可</option>';
        ((res && res.rows) || []).forEach(function(u){
            h += '<option value="' + u.id + '">' + esc(u.name + '（' + (u.dept_name || '') + ' ' + (u.position_name || '') + '）') + '</option>';
        });
        $('#f_decider_user').html(h).val(sel || '');
    }, 'json');
}

/* 儲存填寫區 */
function checkBind(){
    var ok = true;
    var bom = $('#f_bom').val().trim();
    if (bom && !BOM_OK) {
        $('#bomErr').show().text('請從清單中選擇既有的製令（只打字不選，客戶、料號與扣款金額都帶不出來）');
        ok = false;
    } else $('#bomErr').hide();
    var ir = $('#f_ir').val().trim();
    if (ir && !$('#f_ir_id').val()) {
        $('#irErr').show().text('請從清單中選擇既有的客退單（同一個單號可能有好幾筆，一定要選到是哪一筆）');
        ok = false;
    } else $('#irErr').hide();
    return ok;
}
$(document).on('input', '#f_bom', function(){ BOM_OK = false; $('#bomErr').hide(); });
$(document).on('input', '#f_ir', function(){ $('#f_ir_id').val(''); $('#irErr').hide(); });
$(document).on('blur', '#f_bom, #f_ir', function(){ checkBind(); });

function saveHead(silent){
    if (!checkBind()) { if (!silent) alert('製令編號或客退單號要從清單中選擇綁定'); return; }
    var ms = [];
    for (var r = 0; r < 3; r++){
        var vals = [];
        for (var i = 0; i < 12; i++) vals.push($('.m-val[data-r="' + r + '"][data-i="' + i + '"]').val() || '');
        ms.push({ dim_name: $('.m-dim[data-r="' + r + '"]').val() || '', vals: vals });
    }
    post('save_head', {
        id:OID,
        fill_date: $('#f_fill_date').val(), occurrence_date: $('#f_occ_date').val(),
        client_name: $('#f_client').val(), part_no: $('#f_part').val(),
        bom_no: $('#f_bom').val(), ir_id: $('#f_ir_id').val(),
        batch_qty: $('#f_batch').val(), insp_qty: $('#f_insp').val(), ng_qty: $('#f_ng').val(),
        abnormal_phenomenon: $('#f_phe').val(), defect_detail: $('#f_detail').val(), qa_ps: $('#f_qaps').val(),
        resp_process_no: $('#f_proc_no').val(), responsible_vendor_id: $('#f_vendor_id').val(),
        resp_people: JSON.stringify(RESP),
        decider_cfg_id: $('#f_decider').val(), decider_user_id: $('#f_decider_user').val(),
        measures: JSON.stringify(ms)
    }, function(){ savedAt('#savedHead'); if (!silent) toast('已儲存'); }, !!silent);
}
$('#btnSaveHead').on('click', function(){ saveHead(false); });
/* 欄位改完（離開欄位或改選）就自動存——使用者要求不要再有「忘記按存檔」這種事。
   打字中的欄位用 input 事件延後久一點再存，免得每打一個字就送一次。 */
$(document).on('change', '#secHead input, #secHead select', function(){
    if ($(this).is('#f_bom, #f_ir')) return;          // 這兩個要先從清單選到才算數，由 acSetup 存
    autoSave('head', function(){ saveHead(true); });
});
$(document).on('input', '#secHead textarea', function(){ autoSave('head', function(){ saveHead(true); }, 1500); });

/* ③ 相關單位意見：左側勾部門就送出（可一次勾好幾個）。
   **未勾選的部門一樣列出來**（使用者要求），這樣每張單的版面固定，也看得出問過誰、沒問誰。 */
function askDeptName(id){
    var d = (D.depts || []).filter(function(x){ return Number(x.id) === Number(id); })[0];
    return d ? d.department_name : ('部門#' + id);
}
function renderRounds(){
    var o = D.order, rows = o.rounds || [];
    var bf = Number(o.is_backfill) === 1 && !!D.perms.canBackfill;
    var canAsk = !o.is_closed && (D.can_edit || D.perms.canDecide);

    /* 要列出來的部門＝管理員設定過的 ∪ 這張單已經問過的（設定後來被拿掉也不能讓舊資料消失） */
    var cfg = D.ask_cfg || {};
    var order = [], seen = {};
    Object.keys(cfg).forEach(function(d){ if (!seen[d]) { seen[d] = 1; order.push(Number(d)); } });
    rows.forEach(function(r){ if (!seen[r.dept_id]) { seen[r.dept_id] = 1; order.push(Number(r.dept_id)); } });
    (ASK_EXTRA || []).forEach(function(d){ if (!seen[d]) { seen[d] = 1; order.push(Number(d)); } });

    var h = '';
    order.forEach(function(deptId){
        var r = rows.filter(function(x){ return Number(x.dept_id) === deptId; }).slice(-1)[0];
        var done = r && r.status === 'Returned';
        var defs = cfg[deptId] || [];
        var sel = r ? (r.position_id_list || []) : defs.map(function(x){ return x.position_id; });
        var posHtml = defs.length
            ? ('<div class="ask-pos">' + defs.map(function(x){
                  return '<label><input type="checkbox" class="ak-pos" value="' + x.position_id + '"'
                       + (sel.indexOf(Number(x.position_id)) >= 0 ? ' checked' : '')
                       + (r ? ' disabled' : '') + '> ' + esc(x.position_name) + '</label>'; }).join('') + '</div>')
            : '<span class="muted-help">（未設定預設職稱＝通知整個部門）</span>';
        if (r && (r.position_names || []).length) posHtml = esc(r.position_names.join('／')) + (r.user_cname ? ('　' + esc(r.user_cname)) : '');

        h += '<tr data-dept="' + deptId + '"' + (r ? (' data-flow="' + r.flow_id + '"') : '') + '>'
           + '<td class="c"><input type="checkbox" class="ak-on"' + (r ? ' checked' : '') + (canAsk ? '' : ' disabled') + '></td>'
           + '<td>' + esc(askDeptName(deptId)) + '</td>'
           + '<td>' + posHtml + '</td>'
           + '<td class="c">' + (r ? ('<span class="rtg ' + (done ? 'rtg-done' : 'rtg-wait') + '">' + (done ? '已回覆' : '等待回覆') + '</span>'
                                      + '<div class="muted-help" style="font-size:11px;">' + dispDate(r.asked_at) + '</div>')
                                   : '<span class="muted-help">未徵詢</span>') + '</td>'
           + '<td>' + (done
                ? ('<div>' + esc(r.reply_content || '') + '</div><div class="muted-help">' + dispDate(r.return_date) + '　' + esc(r.replied_name || '') + '</div>')
                : (bf && !r
                    ? ('<div style="display:flex;gap:4px;flex-wrap:wrap;">'
                       + '<input type="date" class="ak-rdate" value="' + esc(o.fill_date || '') + '" style="width:130px;">'
                       + '<select class="ak-rby" data-eg-skip style="width:190px;"><option value="">回覆人…</option></select>'
                       + '</div><textarea class="ak-rtext" rows="2" placeholder="當時這個單位回了什麼"></textarea>')
                    : '<span class="muted-help">—</span>')) + '</td>'
           + '<td class="c">'
           + (r && !done && !o.is_closed
                ? ((canReply(r) ? '<button class="btn btn-warm btn-xs" data-reply="' + r.flow_id + '">回覆</button> ' : '')
                   + (canAsk ? '<button class="btn btn-warm-o btn-xs" data-cancel="' + r.flow_id + '">取消</button>' : ''))
                : '') + '</td></tr>';
    });
    $('#askTb tbody').html(h || '<tr><td colspan="6" class="c muted-help">還沒有可以徵詢的部門——請管理員到清單頁「設定 → 相關單位意見」加上部門，或用下方「加入其他部門」。</td></tr>');

    /* 補登模式：回覆人依回覆日期回推當時在職者 */
    if (bf) $('#askTb tbody tr').each(function(){
        var $tr = $(this);
        if ($tr.find('.ak-rby').length) fillPeopleSelect($tr.find('.ak-rby'), $tr.find('.ak-rdate').val() || o.fill_date, '');
    });

    $('#roundTitle').text(bf ? '相關單位意見（補登）' : '相關單位意見');
    $('#roundSub').text(bf ? '直接把「當時哪個單位回了什麼、誰回的、哪一天回的」補進去，不會發通知'
                           : '左側勾部門就會通知該部門的預設回覆職稱，多人只要一人回覆並簽章即可');
    $('#roundNote').html(bf
        ? '<b>補資料</b>：勾起要補的部門，填回覆日期、回覆人與內容，按「補登勾選的意見」。<b>不會發通知</b>——幾年前的事件再發一次通知只會吵到人，對方也無從回覆。'
        : '勾起來的部門會收到通知；同一個部門<b>被通知的可能有好幾位，其中一位回覆並簽章即可</b>。已經送出、對方還沒回覆的可以「取消」。');
    $('#btnAskSendAll').html(bf ? '<i class="fa fa-check"></i> 補登勾選的意見' : '<i class="fa fa-paper-plane"></i> 送出勾選的徵詢').toggle(canAsk);

    /* 「加入其他部門」：設定以外的部門偶爾也要問 */
    var opt = '<option value="">加入其他部門…</option>';
    (D.depts || []).forEach(function(d){ if (!seen[d.id]) opt += '<option value="' + d.id + '">' + esc(d.department_name) + '</option>'; });
    $('#askAddDept').html(opt).toggle(canAsk);
}
var ASK_EXTRA = [];
$(document).on('change', '#askAddDept', function(){
    var v = Number($(this).val());
    if (v > 0 && ASK_EXTRA.indexOf(v) < 0) { ASK_EXTRA.push(v); renderRounds(); }
});
$(document).on('change', '#askTb .ak-rdate', function(){
    var $tr = $(this).closest('tr');
    if (this.value) fillPeopleSelect($tr.find('.ak-rby'), this.value, $tr.find('.ak-rby').val());
});
function canReply(r){
    var p = D.perms;
    if (p.canAdmin) return true;
    if (Number(r.user_id) > 0) return Number(r.user_id) === Number(p.uid);
    return (D.my_dept_ids || []).indexOf(Number(r.dept_id)) >= 0;
}
$('#btnAskSendAll').on('click', function(){
    var bf = Number(D.order.is_backfill) === 1 && !!D.perms.canBackfill;
    var items = [], bad = '';
    $('#askTb tbody tr[data-dept]').each(function(){
        var $tr = $(this);
        if (!$tr.find('.ak-on').prop('checked')) return;
        if ($tr.data('flow')) return;                       // 已經送出過的那幾列不重送
        var it = { dept_id:Number($tr.data('dept')),
                   position_ids:$tr.find('.ak-pos:checked').map(function(){ return Number(this.value); }).get() };
        if (bf) {
            it.replied_on = $tr.find('.ak-rdate').val();
            it.replied_by = $tr.find('.ak-rby').val();
            it.reply_content = $tr.find('.ak-rtext').val();
            if (!it.reply_content || !it.reply_content.trim()) { bad = askDeptName(it.dept_id) + '：請填回覆內容'; return; }
            if (!it.replied_on) { bad = askDeptName(it.dept_id) + '：請選回覆日期'; return; }
            if (!it.replied_by) { bad = askDeptName(it.dept_id) + '：請選回覆人'; return; }
        }
        items.push(it);
    });
    if (bad) { alert(bad); return; }
    if (!items.length) { alert('請先勾選要徵詢的部門（已經送出過的不會重送）'); return; }
    post('round_add', { id:OID, items:JSON.stringify(items) }, function(res){
        toast(bf ? ('已補登 ' + res.count + ' 則意見') : ('已送出 ' + res.count + ' 個單位的徵詢並通知'));
        savedAt('#savedRound');
    });
});
/* 取消勾選＝取消那一輪（還沒回覆的才可以） */
$(document).on('change', '#askTb .ak-on', function(){
    var $tr = $(this).closest('tr');
    if (this.checked || !$tr.data('flow')) return;
    var r = (D.order.rounds || []).filter(function(x){ return Number(x.flow_id) === Number($tr.data('flow')); })[0];
    if (r && r.status === 'Returned') { alert('已經回覆的不可以取消（要改內容請由該單位重新回覆）'); $(this).prop('checked', true); return; }
    if (!confirm('取消對「' + askDeptName($tr.data('dept')) + '」的徵詢？')) { $(this).prop('checked', true); return; }
    post('round_cancel', { id:OID, flow_id:$tr.data('flow') }, function(){ toast('已取消'); });
});
$(document).on('click', '[data-cancel]', function(){
    if (!confirm('確定取消這一輪徵詢？（尚未回覆的才可以取消）')) return;
    post('round_cancel', { id:OID, flow_id:$(this).data('cancel') }, function(){ toast('已取消'); });
});

$(document).on('click', '[data-reply]', function(){ RPL_FLOW = $(this).data('reply'); $('#r_content').val(''); $('#rplErr').text(''); openMask('rplMask'); });
$('#btnRplSend').on('click', function(){
    if (!$('#r_content').val().trim()) { $('#rplErr').text('請填寫回覆內容'); return; }
    post('round_reply', { id:OID, flow_id:RPL_FLOW, reply_content:$('#r_content').val() },
        function(){ closeMask('rplMask'); toast('已回覆'); });
});
$(document).on('click', '[data-cancel]', function(){
    if (!confirm('確定取消這一輪徵詢？（尚未回覆的才可以取消）')) return;
    post('round_cancel', { id:OID, flow_id:$(this).data('cancel') }, function(){ toast('已取消'); });
});

/* ④⑤ 決策／裁示 */
function saveDisp(){
    var ids = $('.dchk:checked').map(function(){ return parseInt(this.value, 10); }).get();
    post('save_disposition', { id:OID, opt_ids: JSON.stringify(ids), disposition_note: $('#f_disp_note').val() },
        function(){ savedAt('#savedDisp'); }, true);
}
function saveGm(){
    var ids = $('.gchk:checked').map(function(){ return parseInt(this.value, 10); }).get();
    post('save_gm', { id:OID, opt_ids: JSON.stringify(ids), gm_note: $('#f_gm_note').val(),
                      capa_order_no: $('#f_capa').val(), gm_deduct: $('#g_deduct').prop('checked') ? 1 : '' },
        function(){ savedAt('#savedGm'); renderDeduct(); }, true);
}
$('#btnSaveDisp').on('click', saveDisp);
$('#btnSaveGm').on('click', saveGm);
$(document).on('change', '.dchk', function(){ autoSave('disp', saveDisp, 400); });
$(document).on('input', '#f_disp_note', function(){ autoSave('disp', saveDisp, 1500); });
$(document).on('change', '.gchk, #g_deduct', function(){ autoSave('gm', saveGm, 400); });
$(document).on('input', '#f_gm_note, #f_capa', function(){ autoSave('gm', saveGm, 1500); });

/* ⑥ 扣款 */
function renderDeduct(){
    var o = D.order, p = D.perms;
    var canFill = p.canDeductFill && !o.is_closed;
    var need = Number(o.gm_deduct) === 1 || (o.final && o.final.is_scrap);
    $('#deductHint').html(need
        ? '這張單<b>需要填扣款確認</b>（' + (o.final && o.final.is_scrap ? '最終決策含報廢' : '') + (Number(o.gm_deduct) === 1 ? (o.final && o.final.is_scrap ? '，且' : '') + '總經理裁示勾了扣款' : '') + '）。'
          + (o.bom_no ? '' : '<br><span style="color:var(--coral);">這張單沒有綁製令，製程金額無法自動帶入，請在上方基本資料補上製令編號。</span>')
        : '目前不需要扣款確認（總經理裁示沒有勾「扣款」，最終決策也不是報廢）。仍可先填，結案不會被擋。');

    var h = '';
    DEDUCT_ROWS.forEach(function(r, i){
        var isProc = (r.kind || 'process') !== 'other';
        h += '<tr class="' + (Number(r.included) ? '' : 'off') + '">'
           + '<td class="c"><input type="checkbox" class="d-inc" data-i="' + i + '" ' + (Number(r.included) ? 'checked' : '') + '></td>'
           + '<td class="c">' + (isProc ? '製程' : '其他') + '</td>'
           + '<td>' + (isProc
                ? esc((r.process_name || '') + (r.vendor_name ? ('／' + r.vendor_name) : ''))
                : '<input type="text" class="d-nm" data-i="' + i + '" value="' + esc(r.process_name || '') + '">') + '</td>'
           + '<td class="r"><input type="number" step="0.01" class="d-amt" data-i="' + i + '" value="' + (r.amount == null ? '' : r.amount) + '"></td>'
           + '<td>' + (isProc
                ? '<span class="muted-help">' + esc(r.transfer_no || '') + (r.amount_auto != null ? ('　自動帶入 ' + Number(r.amount_auto).toLocaleString()) : '') + '</span>'
                  + '<input type="text" class="d-note" data-i="' + i + '" value="' + esc(r.note || '') + '" style="margin-top:2px;">'
                : '<input type="text" class="d-note" data-i="' + i + '" value="' + esc(r.note || '') + '">') + '</td>'
           + '<td class="c">' + (isProc ? '' : '<span class="x" style="color:var(--coral);cursor:pointer;" data-drm="' + i + '">×</span>') + '</td>'
           + '</tr>';
    });
    if (!DEDUCT_ROWS.length) h = '<tr><td colspan="6" class="c muted-help">尚未有扣款明細</td></tr>';
    $('#dtb tbody').html(h);
    $('#dtb input, #f_rate, #f_dqty, #f_dexec, #f_dnotify').prop('disabled', !canFill);
    $('#f_dunit').prop('disabled', !(p.canDeductApprove && !o.is_closed));
    $('#btnAddOther,#btnSaveDeduct,#btnAutoFill').toggle(canFill);
    $('#btnSignPm').toggle(p.canDeductFill && !o.is_closed);
    $('#btnSignQc').toggle(p.canQcSign && !o.is_closed);
    $('#btnApprove').toggle(p.canDeductApprove && !o.is_closed);
    $('#pmWho').text(o.deduct_pm_name ? (o.deduct_pm_name + '　' + dispDate(o.deduct_pm_at)) : '（未簽）');
    $('#qcWho').text(o.deduct_qc_name ? (o.deduct_qc_name + '　' + dispDate(o.deduct_qc_at)) : '（未簽）');
    $('#apprWho').text(o.deduct_appr_name ? (o.deduct_appr_name + '　' + dispDate(o.deduct_appr_at)) : '（未核准）');
    calcSum();
}
function calcSum(){
    var rate = parseFloat($('#f_rate').val()); if (!(rate > 0)) rate = 1;
    var proc = 0, other = 0;
    DEDUCT_ROWS.forEach(function(r){
        if (!Number(r.included)) return;
        var a = parseFloat(r.amount); if (isNaN(a)) return;
        if ((r.kind || 'process') === 'other') other += a; else proc += a;
    });
    var pr = Math.round(proc * rate * 100) / 100;
    $('#dSum').html('製程小計 ' + proc.toLocaleString() + '　× 加成 ' + rate + ' ＝ <b>' + pr.toLocaleString() + '</b>'
        + '　｜　其他 <b>' + other.toLocaleString() + '</b>'
        + '　｜　合計 <b>' + (Math.round((pr + other) * 100) / 100).toLocaleString() + '</b>');
}
$(document).on('input', '#f_rate', calcSum);
$(document).on('change', '.d-inc', function(){ DEDUCT_ROWS[$(this).data('i')].included = $(this).prop('checked') ? 1 : 0; renderDeduct(); });
$(document).on('input', '.d-amt', function(){ DEDUCT_ROWS[$(this).data('i')].amount = this.value; calcSum(); });
$(document).on('input', '.d-note', function(){ DEDUCT_ROWS[$(this).data('i')].note = this.value; });
$(document).on('input', '.d-nm', function(){ DEDUCT_ROWS[$(this).data('i')].process_name = this.value; });
$(document).on('click', '[data-drm]', function(){ DEDUCT_ROWS.splice(parseInt($(this).data('drm'), 10), 1); renderDeduct(); });
$('#btnAddOther').on('click', function(){
    DEDUCT_ROWS.push({ id:0, kind:'other', process_name:'', amount:'', note:'', included:1 });
    renderDeduct();
});
$('#btnAutoPreview').on('click', function(){
    if (!(D.order.bom_list || []).length) { alert('這張單沒有綁製令'); return; }
    $('#pvBody').html('載入中…'); openMask('pvMask');
    $.get(API, { action:'deduct_preview', id:OID }, function(res){
        if (!res || !res.success) { $('#pvBody').text('查詢失敗'); return; }
        if (!res.rows.length) { $('#pvBody').html('<div class="muted-help">這張製令目前沒有任何製程移轉憑單。</div>'); return; }
        var t = '<table class="dtb"><thead><tr><th>製令</th><th>站別</th><th>製程</th><th>廠商</th><th>移轉單號</th><th>數量</th><th>金額</th></tr></thead><tbody>';
        var sum = 0;
        res.rows.forEach(function(r){
            sum += Number(r.amount) || 0;
            t += '<tr><td class="c">' + esc(r.bom_no || '') + '</td><td class="c">' + r.bom_sn + '</td><td>' + esc(r.process_name) + '</td><td>' + esc(r.vendor_name) + '</td>'
               + '<td class="c">' + esc(r.transfer_no) + '</td><td class="r">' + Number(r.qty).toLocaleString() + '</td>'
               + '<td class="r">' + Number(r.amount).toLocaleString() + '</td></tr>';
        });
        t += '</tbody></table><div class="sumline">合計 <b>' + sum.toLocaleString() + '</b></div>';
        $('#pvBody').html(t);
    }, 'json');
});
$('#btnAutoFill').on('click', function(){
    if (!confirm('會用這張製令的製程移轉金額重建「製程」明細（手動加的「其他」列不受影響）。要繼續嗎？')) return;
    post('deduct_autofill', { id:OID }, function(res){ toast('已帶入 ' + res.count + ' 站'); });
});
function saveDeduct(silent){
    post('deduct_save', { id:OID, rows: JSON.stringify(DEDUCT_ROWS), surcharge_rate:$('#f_rate').val(),
                          deduct_qty:$('#f_dqty').val(), deduct_unit_amt:$('#f_dunit').val(),
                          deduct_exec:$('#f_dexec').val(), deduct_notify_no:$('#f_dnotify').val() },
        function(){ savedAt('#savedDeduct'); if (!silent) toast('扣款明細已儲存'); }, !!silent);
}
$('#btnSaveDeduct').on('click', function(){ saveDeduct(false); });
$(document).on('change', '#secDeduct input, #secDeduct select', function(){
    autoSave('deduct', function(){ saveDeduct(true); });
});
$(document).on('input', '#secDeduct textarea', function(){ autoSave('deduct', function(){ saveDeduct(true); }, 1500); });
$('#btnSignPm').on('click', function(){ post('deduct_sign', { id:OID, who:'pm', clear: D.order.deduct_pm_at ? 1 : '' }); });
$('#btnSignQc').on('click', function(){ post('deduct_sign', { id:OID, who:'qc', clear: D.order.deduct_qc_at ? 1 : '' }); });
$('#btnApprove').on('click', function(){
    post('deduct_approve', { id:OID, clear: D.order.deduct_appr_at ? 1 : '',
                             deduct_unit_amt:$('#f_dunit').val(), deduct_qty:$('#f_dqty').val() });
});

/* ⑦ 結案 */
$('#btnClose2').on('click', function(){
    if (!confirm('結案後這張單就不能再修改（要改須由管理員取消結案）。\n最終決策含「報廢」時會在此刻配發報廢單號。確定結案？')) return;
    post('close', { id:OID }, function(res){
        toast(res.scrap_no ? ('已結案，報廢單號 ' + res.scrap_no) : '已結案');
    });
});
$('#btnReopen').on('click', function(){
    if (!confirm('取消結案？（已配發的報廢單號不會收回）')) return;
    post('reopen', { id:OID }, function(){ toast('已取消結案'); });
});
$('#btnPrint').on('click', function(){ window.open('qa_abnormal_print.php?id=' + OID + '&auto=1', '_blank'); });
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });

/* 自動完成：製程／廠商／製令／IR */
function acSetup(inputSel, action, fmt, pick){
    var $in = $(inputSel), tmr = null, $list = $('<div class="ac-list"></div>').appendTo('body');
    function place(){
        var r = $in[0].getBoundingClientRect();
        $list.css({ left:r.left + 'px', top:(r.bottom + 2) + 'px', width:Math.max(r.width, 240) + 'px' });
    }
    $in.on('input focus', function(){
        var kw = $in.val().trim();
        clearTimeout(tmr);
        tmr = setTimeout(function(){
            $.get(API, { action:action, kw:kw }, function(res){
                if (!res || !res.success || !res.rows.length) { $list.hide(); return; }
                $list.html(res.rows.map(function(r, i){ return '<div data-i="' + i + '">' + fmt(r) + '</div>'; }).join(''));
                $list.data('rows', res.rows); place(); $list.show();
            }, 'json');
        }, 220);
    });
    $list.on('mousedown', 'div', function(e){
        e.preventDefault();
        var rows = $list.data('rows') || [];
        pick(rows[$(this).data('i')]); $list.hide();
    });
    $in.on('blur', function(){ setTimeout(function(){ $list.hide(); }, 180); });
    $(window).on('scroll resize', function(){ if ($list.is(':visible')) place(); });
}
$(function(){
    $('#sidebar-menu').css('visibility', 'visible');
    acSetup('#f_proc', 'search_process',
        function(r){ return '<span class="hit">' + esc(r.ProcessNo) + '</span>　' + esc(r.ProcessName); },
        function(r){ $('#f_proc').val(r.ProcessName); $('#f_proc_no').val(r.ProcessNo); });
    acSetup('#f_vendor', 'search_vendor',
        function(r){ return '<span class="hit">' + esc(r.maker_id_no) + '</span>　' + esc(r.maker_id)
                            + (Number(r.internal) === 1 ? ' <span style="color:#C77C1A;">[廠內]</span>' : ''); },
        function(r){
            $('#f_vendor').val(r.maker_id); $('#f_vendor_id').val(r.maker_id_no);
            var isIn = Number(r.internal) === 1;
            $('#vendorNote').html(isIn ? '<span style="color:var(--amber-d);"><i class="fa fa-home"></i> 廠內加工廠商 — 可再指定部門與人員</span>' : '');
            $('#respPeopleBox').toggle(isIn);
            if (!isIn) { RESP = []; drawResp(); }
        });
    acSetup('#f_bom', 'search_bom',
        function(r){ return '<span class="hit">' + esc(r.bom) + '</span>　' + esc(r.d_id) + '　' + esc(r.Client_Name); },
        function(r){
            $('#f_bom').val(r.bom);
            BOM_OK = true; $('#bomErr').hide();
            $('#f_ir').val(''); $('#f_ir_id').val('');
            // 客戶與料號一律由來源覆蓋（存檔時後端會再以料號主檔解析一次，畫面只是先讓人看到）
            $('#f_client').val(r.Client_Name || '').prop('readonly', true);
            $('#clientSrc').text('（由製令自動綁定，存檔後以料號主檔的客戶為準）');
            $('#f_part').val(r.d_id || '').prop('readonly', true);
            $('#partSrc').text('（由製令自動綁定）');
            if (r.sqty) { $('#f_batch').val(r.sqty); refreshSampleHint(true); }
        });
    acSetup('#f_ir', 'search_ir',
        function(r){ return '<span class="hit">' + esc(r.IR_no) + '</span>　' + esc(r.Client_name) + '　' + esc(r.d_id); },
        function(r){
            $('#f_ir').val(r.IR_no);
            $('#f_ir_id').val(r.IR_id);           // 同一個 IR 單號可能有好幾筆，一定要記住是哪一筆
            $('#irErr').hide();
            $('#f_client').val(r.Client_name || '').prop('readonly', true);
            $('#clientSrc').text('（由客退單自動綁定，存檔後以料號主檔的客戶為準）');
            $('#f_part').val(r.d_id || '').prop('readonly', true);
            $('#partSrc').text('（由客退單自動綁定）');
            if (r.Qty) { $('#f_batch').val(r.Qty); refreshSampleHint(true); }
        });
    load();
});

function toast(msg){
    var $t = $('#egToast');
    if (!$t.length) $t = $('<div id="egToast" style="position:fixed;left:50%;transform:translateX(-50%);bottom:40px;'
        + 'background:#4A3524;color:#fff;padding:8px 18px;border-radius:18px;z-index:10600;font-size:14px;display:none;"></div>').appendTo('body');
    $t.text(msg).fadeIn(120);
    clearTimeout($t.data('t'));
    $t.data('t', setTimeout(function(){ $t.fadeOut(200); }, 1800));
}
</script>
</body>
</html>
