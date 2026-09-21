<?php
/**
 * 不合格品管制記錄表（2-QA-01-03）— 2026-09-18 建立
 *
 * 這不是一張要從頭打字的表單，而是一本**登錄簿**：把四種來源的不合格品事件彙整成一份
 * 可追溯的清單（程序書 2-QA-01 第 5 節的五個過程輸入），人只補「原因／責任單位／
 * 處理方式／報廢單號／結案」這幾格。
 *
 * 資料一律走 src/store/QaNcr_API.php；共用邏輯 src/common/qa_ncr_lib.php
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/QA/ncr_control_log.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/qa_ncr_lib.php';

$db = (new DBConnection())->getPDO();
ncr_ensure_schema($db);
$perms = ncr_perms($db, ncr_current_user($db));
if (empty($_SESSION['ncr_csrf'])) $_SESSION['ncr_csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['ncr_csrf'];
$roleLabel = $perms['isAdmin'] ? '系統管理者' : ($perms['canAdmin'] ? '管制記錄管理員' : ($perms['canView'] ? '檢閱' : '無權限'));
$thisYear = (int)date('Y');
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>不合格品管制記錄表</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        /* 側欄：CSS 藏起來、ready 時再顯示（鐵律6，CSS 與 JS 必須成對） */
        #sidebar-menu { visibility: hidden; }
        :root{ --ink:#4A3524; --cream:#FCF7F0; --sand:#F7E0BD; --amber:#F0A24B;
               --amber-d:#C77C1A; --coral:#DD5138; --line:#E4D3BC; }
        body { background:#F6F1EA; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .page-title h3 { color:var(--ink); margin:0; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid var(--amber-d);
                         border-radius:15px; background:#fff; color:var(--amber-d); }
        .page-help-btn:hover { background:var(--amber-d); color:#fff; }
        @media print { .page-help-btn { display:none !important; } }
        .warm-panel { background:#fff; border:1px solid var(--line); border-radius:8px; padding:12px; margin-bottom:12px; }
        .btn-warm { background:var(--amber); border:1px solid var(--amber-d); color:var(--ink); font-weight:bold; }
        .btn-warm:hover,.btn-warm:focus { background:var(--amber-d); color:#fff; }
        .btn-warm-o { background:#fff; border:1px solid var(--amber-d); color:var(--amber-d); }
        .btn-warm-o:hover { background:var(--sand); }
        .as-tag { font-size:12px; background:var(--sand); color:#6B4423; border-radius:10px; padding:2px 10px; font-weight:normal; }
        .role-tag { font-size:12px; background:#EFE3CF; color:#6B4423; border-radius:10px; padding:2px 10px; font-weight:normal; }
        .ncr-bar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .muted-help { font-size:12px; color:#8a7560; }
        .note-box { font-size:12px; color:#6B4423; background:var(--cream); border:1px solid var(--line);
                    border-radius:6px; padding:8px 10px; margin-bottom:8px; line-height:1.7; }
        .sc-row { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:8px; }
        .sc { background:#fff; border:1px solid var(--line); border-left:4px solid var(--amber);
              border-radius:6px; padding:6px 14px; min-width:110px; }
        .sc .v { font-size:20px; font-weight:700; color:var(--ink); }
        .sc .l { font-size:11px; color:#8a7560; }
        /* 表格自動換行、不出現左右捲軸（table-layout:fixed 擋不住儲存格內容溢出，所以要加 break-word） */
        .ncr-scroll { max-height:64vh; overflow-y:auto; overflow-x:hidden; border:1px solid var(--line); border-radius:6px; }
        table.ncr-tb { width:100%; table-layout:fixed; border-collapse:collapse; font-size:12px; background:#fff; }
        table.ncr-tb th, table.ncr-tb td { border:1px solid var(--line); padding:3px 5px; text-align:center;
                                            overflow-wrap:break-word; word-break:break-word; vertical-align:top; }
        table.ncr-tb thead th { background:var(--sand); color:#6B4423; position:sticky; top:0; z-index:2; }
        table.ncr-tb td.tl { text-align:left; }
        table.ncr-tb tbody tr:hover { background:#FFFDF8; }
        .src-badge { font-size:10px; border-radius:8px; padding:1px 6px; white-space:nowrap; display:inline-block; }
        /* 來源色：同語意同色、固定暖色盤（ai-rules/10），顏色不是唯一資訊（另有文字） */
        .src-qa  { background:#F7E0BD; color:#6B4423; }
        .src-car { background:#F0A24B; color:#3b2a18; }
        .src-ir  { background:#DD5138; color:#fff; }
        .src-qc  { background:#EFE3CF; color:#6B4423; }
        .src-manual { background:#D8C7AE; color:#4A3524; }
        .f-in { width:100%; border:1px solid var(--line); border-radius:3px; padding:1px 3px; font-size:11.5px; }
        .f-in.edited { background:#FFF9EC; }
        .ro-src { font-size:11.5px; color:#5b4a36; background:#F7F3EC; border:1px dashed var(--line);
                  border-radius:3px; padding:1px 4px; min-height:18px; }
        .closed-yes { color:#2c7a3f; font-weight:700; }
        .closed-no  { color:var(--coral); font-weight:700; }
        .aero-tag { font-size:10px; background:var(--coral); color:#fff; border-radius:8px; padding:0 5px; }
        .m-mask { position:fixed; inset:0; background:rgba(74,53,36,.45); z-index:10300; display:none; }
        .m-box { position:absolute; left:50%; top:5vh; transform:translateX(-50%); background:#fff;
                 border-radius:8px; box-shadow:0 10px 30px rgba(0,0,0,.3); display:flex; flex-direction:column; max-height:90vh; }
        .m-head { padding:10px 14px; background:var(--cream); border-bottom:1px solid var(--line);
                  font-weight:700; color:var(--ink); display:flex; align-items:center; gap:8px; }
        .m-body { padding:14px; overflow:auto; }
        .m-foot { padding:10px 14px; border-top:1px solid var(--line); text-align:right; background:#FDFBF7; }
        .help-doc h4 { color:var(--amber-d); font-size:15px; margin:14px 0 6px; }
        .help-doc li { margin-bottom:4px; line-height:1.7; }
        .fg { margin-bottom:8px; }
        .fg label { font-weight:600; font-size:12px; color:var(--ink); margin-bottom:2px; display:block; }
    </style>
</head>
<!-- 側欄載入時維持收合（全站慣例：nav-sm） -->
<body class="nav-sm">
<div class="container body"><div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html'; ?>
<div class="right_col" role="main">

  <div class="page-title">
    <h3><i class="fa fa-ban" style="color:var(--coral);"></i> 不合格品管制記錄表
      <span class="as-tag" id="asTag">2-QA-01-03</span>
      <span class="role-tag">目前身分：<?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></span>
      <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
    </h3>
  </div>

<?php if (!$perms['canView']): ?>
  <div class="warm-panel" style="color:var(--coral);">
    您沒有不合格品管制記錄表的檢閱權限。請洽管理員於「權限設定 → 不合格品管制記錄表」開通
    <code>ncr_view</code>（檢閱）或 <code>ncr_admin</code>（管理員）；品管既有的檢閱／管理檢驗設定角色也可以。
  </div>
<?php else: ?>

  <div class="warm-panel">
    <div class="note-box">
      這是一本<b>登錄簿</b>：系統把不合格品事件從四個來源自動彙整過來（程序書 2-QA-01 第 5 節的過程輸入），
      您只要補<b>原因／責任單位／處理方式／報廢單號／結案</b>這幾格。
      <b>來源欄位會即時反映來源單的現況</b>——來源單事後結案，這裡也會跟著變成已結案。
      要調整彙整哪些來源請按「設定」。
    </div>
    <div class="ncr-bar" style="margin-bottom:8px;">
      <label style="margin:0;">年度</label>
      <select id="fYear" class="form-control input-sm" style="width:96px;"></select>
      <span class="muted-help">或自訂</span>
      <input type="date" id="fFrom" class="form-control input-sm" style="width:140px;">
      <span class="muted-help">~</span>
      <input type="date" id="fTo" class="form-control input-sm" style="width:140px;">
      <select id="fClosed" class="form-control input-sm" style="width:110px;">
        <option value="all">結案：全部</option>
        <option value="open">未結案</option>
        <option value="closed">已結案</option>
      </select>
      <label style="margin:0;font-weight:normal;font-size:12px;">
        <input type="checkbox" id="fAero"> 只看航太類
      </label>
      <input type="text" id="fKw" class="form-control input-sm" style="width:190px;"
             data-eg-hint="客戶、料號、單號、原因都可以搜" placeholder="關鍵字（Enter 搜尋）">
      <button class="btn btn-sm btn-warm-o" id="btnReload"><i class="fa fa-refresh"></i> 查詢</button>
      <span style="margin-left:auto;display:flex;gap:6px;">
        <?php if ($perms['canAdmin']): ?>
        <button class="btn btn-sm btn-warm-o" id="btnManual"><i class="fa fa-plus"></i> 紙本補登</button>
        <?php endif; ?>
        <button class="btn btn-sm btn-warm" id="btnPrint"><i class="fa fa-print"></i> 列印</button>
        <button class="btn btn-sm btn-warm-o" id="btnCsv"><i class="fa fa-file-excel-o"></i> CSV</button>
        <?php if ($perms['canAdmin']): ?>
        <button class="btn btn-sm btn-warm-o" id="btnSetting"><i class="fa fa-cog"></i> 設定</button>
        <?php endif; ?>
      </span>
    </div>
    <div class="ncr-bar" id="srcFilter" style="margin-bottom:8px;"></div>
    <div class="sc-row" id="statRow"></div>
    <div class="ncr-scroll">
      <table class="ncr-tb" id="ncrTable">
        <colgroup>
          <col style="width:3%"><col style="width:7%"><col style="width:7%"><col style="width:8%">
          <col style="width:11%"><col style="width:9%"><col style="width:5%"><col style="width:10%">
          <col style="width:15%"><col style="width:8%"><col style="width:10%"><col style="width:7%">
        </colgroup>
        <thead><tr>
          <th>#</th><th>來源</th><th>檢驗日期</th><th>客戶</th><th>工件名稱</th><th>圖號/件號</th>
          <th>數量</th><th>異常單編號</th><th>原因</th><th>責任單位</th><th>處理方式</th><th>結案</th>
        </tr></thead>
        <tbody id="ncrBody"><tr><td colspan="12" style="padding:20px;color:#999;">載入中…</td></tr></tbody>
      </table>
    </div>
  </div>

<?php endif; ?>
</div></div></div>

<!-- ══ 使用說明（鐵律7）══ -->
<div class="m-mask" id="helpUseMask">
  <div class="m-box" style="width:min(94vw,880px);">
    <div class="m-head"><i class="fa fa-question-circle"></i> 不合格品管制記錄表－使用說明
      <span style="margin-left:auto;"><button class="btn btn-xs btn-default" data-close="helpUseMask">關閉</button></span></div>
    <div class="m-body help-doc">
      <h4>這一頁在做什麼</h4>
      <p>2-QA-01-03 不合格品管制記錄表是一本<b>登錄簿</b>，不是要人從頭打字的表單。
         程序書 2-QA-01 第 5 節把不合格品的來源列得很清楚（進料檢驗／製程·重工／委外加工檢驗／
         最終產品檢驗／客退），這些事件系統裡本來就有紀錄，所以這一頁<b>自動把它們彙整出來</b>。</p>
      <h4>四個來源（可在「設定」逐一開關）</h4>
      <ul>
        <li><b>品質異常處理單</b>（程序書 6.1.2 由品管課開立的那一張）</li>
        <li><b>異常矯正處理單</b>（走矯正與預防措施管理程序的那一張）</li>
        <li><b>客戶退貨</b>（程序書 5.5／6.6.2 客退不合格品）</li>
        <li><b>QC 檢驗判定不良／特採</b>（5.1~5.4 的檢驗當下）</li>
      </ul>
      <h4>哪些自動、哪些要人補</h4>
      <ul>
        <li><b>自動帶入</b>：檢驗日期、客戶、工件名稱、圖號、數量、異常單編號，以及來源單本身有填的原因／責任單位／處理方式／結案狀態。</li>
        <li><b>要人補</b>：來源單沒有的「原因／責任單位／處理方式」，以及<b>報廢單號</b>（程序書 6.5.1：報廢要把報廢單號填在本表內）、
            <b>航太類標記</b>（6.5.3：航太類不良品要記錄於本表並貼紅色吊卡）、結案。</li>
        <li>點任何一格就可以直接改，<b>離開欄位就自動存檔</b>。人改過的值會以淺黃底標示，
            並且<b>優先於系統帶入的值</b>；把它清空就回到系統帶的值。</li>
      </ul>
      <h4>兩個刻意的設計</h4>
      <ul>
        <li><b>補充內容不會寫回來源單</b>：來源單各有自己的流程與簽核（矯正單有四段簽核、品質異常有總經理裁決），
            在這本登錄簿改一個字就回頭動它們的欄位，等於從側門繞過那些流程。</li>
        <li><b>來源事件不可以從這裡刪掉</b>（只有「紙本補登」的列可以刪）。從登錄簿刪掉一筆不合格品
            會讓紀錄憑空消失、失去可追溯性；要處理請到來源模組。</li>
      </ul>
      <h4>操作步驟</h4>
      <ol>
        <li>選年度（或自訂日期區間），按「查詢」。</li>
        <li>用上方的來源徽章、結案狀態、航太類、關鍵字縮小範圍。</li>
        <li>逐列補上原因／責任單位／處理方式；處理方式選「報廢」時記得在補充欄填報廢單號。</li>
        <li>處理完的勾「結案」（沒填結案日期會自動補當天）。</li>
        <li>紙本上有、系統裡卻沒有來源單的，用「紙本補登」自己加一列。</li>
        <li>按「列印」產生正式表單（A4 橫式，含公司全名與 AS 文件編號、製表與主管審核簽章格）。</li>
      </ol>
      <h4>權限</h4>
      <ul>
        <li><code>ncr_admin</code> 管制記錄管理員：補填、紙本補登、改設定。<b>品管的「管理檢驗設定」角色視同管理員</b>——這本登錄簿本來就是品管在維護，不必再多指派一個角色。</li>
        <li><code>ncr_view</code> 檢閱：唯讀（含列印）。品管既有的檢閱／填寫檢驗／修改歷史角色也看得到。</li>
        <li>系統管理者固定全權。</li>
      </ul>
    </div>
    <div class="m-foot"><button class="btn btn-default" data-close="helpUseMask">關閉</button></div>
  </div>
</div>

<?php if ($perms['canAdmin']): ?>
<!-- ══ 紙本補登 ══ -->
<div class="m-mask" id="manMask">
  <div class="m-box" style="width:min(94vw,620px);">
    <div class="m-head"><i class="fa fa-plus"></i> 紙本補登一列
      <span style="margin-left:auto;"><button class="btn btn-xs btn-default" data-close="manMask">關閉</button></span></div>
    <div class="m-body">
      <div class="note-box">紙本上有、系統裡卻沒有對應來源單的不合格品事件，用這裡補登。
        <b>補登的列可以刪除</b>；由來源自動帶進來的列不行（那會讓紀錄憑空消失）。</div>
      <div class="fg"><label>檢驗日期 <span style="color:var(--coral);">*</span></label>
        <input type="date" id="mDate" class="form-control input-sm" style="width:170px;"></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <div class="fg" style="flex:1;min-width:150px;"><label>客戶</label><input type="text" id="mClient" class="form-control input-sm"></div>
        <div class="fg" style="flex:1;min-width:150px;"><label>工件名稱</label><input type="text" id="mPart" class="form-control input-sm"></div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <div class="fg" style="flex:1;min-width:120px;"><label>圖號/件號</label><input type="text" id="mDraw" class="form-control input-sm"></div>
        <div class="fg" style="width:100px;"><label>數量</label><input type="text" id="mQty" class="form-control input-sm"></div>
        <div class="fg" style="flex:1;min-width:130px;"><label>異常單編號</label><input type="text" id="mNo" class="form-control input-sm"></div>
      </div>
      <div class="fg"><label>原因</label><input type="text" id="mCause" class="form-control input-sm"></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <div class="fg" style="flex:1;min-width:140px;"><label>責任單位</label><input type="text" id="mResp" class="form-control input-sm"></div>
        <div class="fg" style="width:150px;"><label>處理方式</label><select id="mDisp" class="form-control input-sm"></select></div>
      </div>
      <div id="mErr" style="color:var(--coral);font-size:12px;"></div>
    </div>
    <div class="m-foot">
      <button class="btn btn-default" data-close="manMask">取消</button>
      <button class="btn btn-warm" id="btnManSave"><i class="fa fa-save"></i> 新增</button>
    </div>
  </div>
</div>

<!-- ══ 設定 ══ -->
<div class="m-mask" id="setMask">
  <div class="m-box" style="width:min(94vw,680px);">
    <div class="m-head"><i class="fa fa-cog"></i> 不合格品管制記錄表設定
      <span style="margin-left:auto;"><button class="btn btn-xs btn-default" data-close="setMask">關閉</button></span></div>
    <div class="m-body">
      <div class="fg" style="margin-bottom:16px;">
        <label>AS 文件編號綁定</label>
        <div class="muted-help" style="margin-bottom:4px;">綁定後：列印表頭＝該文件的表單名稱、頁尾右下＝文件編號（依期間最後一天回推當時版次）。</div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <span id="setDocLabel" style="flex:1;min-width:200px;padding:6px 10px;background:var(--cream);border:1px solid var(--line);border-radius:4px;">尚未綁定</span>
          <button class="btn btn-sm btn-default" onclick="ncrPickDoc()"><i class="fa fa-search"></i> 選擇</button>
          <button class="btn btn-sm btn-default" onclick="ncrClearDoc()"><i class="fa fa-times"></i> 取消</button>
        </div>
      </div>
      <div class="fg" style="margin-bottom:16px;">
        <label>要彙整哪些來源</label>
        <div class="muted-help" style="margin-bottom:4px;">至少要保留一個，否則這張表會永遠是空的。</div>
        <div id="setSources"></div>
      </div>
      <div class="fg">
        <label>製表／主管審核圖章模板</label>
        <div class="muted-help" style="margin-bottom:4px;">模板在「圖章管理 → 線上圖章設計」建立；未指定則用系統預設回墨印。列印一律用模板設計的實際尺寸，不會被縮小。</div>
        <select id="setStampTpl" class="form-control input-sm" style="max-width:400px;"><option value="0">（用系統預設印章）</option></select>
      </div>
    </div>
    <div class="m-foot">
      <button class="btn btn-default" data-close="setMask">取消</button>
      <button class="btn btn-warm" id="btnSetSave"><i class="fa fa-save"></i> 儲存設定</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<script src="../../resource/js/eg_stamp_tpl.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp_tpl.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script src="../../resource/js/eg_print_log.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_print_log.js') ?>"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
/* 側欄恢復顯示（與 CSS 的 visibility:hidden 成對，鐵律6） */
$(document).ready(function(){ $('#sidebar-menu').css('visibility','visible'); });

var API  = '../../src/store/QaNcr_API.php';
var CSRF = <?= json_encode($CSRF) ?>;
var CAN_ADMIN = <?= $perms['canAdmin'] ? 'true' : 'false' ?>;
var THIS_YEAR = <?= $thisYear ?>;
var ST = { rows:[], sources:{}, enabled:[], disp:[], set:null, from:'', to:'', srcSel:null };

function esc(s){ return String(s===null||s===undefined?'':s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function dispDate(v){ var d=String(v||'').substr(0,10);
    return /^\d{4}-\d{2}-\d{2}$/.test(d) ? (window.egFmtDate?egFmtDate(d):d) : ''; }
function openMask(id){ $('#'+id).show(); }
function closeMask(id){ $('#'+id).hide(); }
$(document).on('click','[data-close]',function(){ closeMask($(this).data('close')); });
$(document).on('click','.m-mask',function(e){ if(e.target===this) $(this).hide(); });
function ajxGet(p, cb){ $.get(API, p, cb, 'json').fail(function(x){ alert('讀取失敗：'+((x.responseJSON&&x.responseJSON.error)||x.status)); }); }
function ajxPost(p, cb){ p.csrf=CSRF; $.post(API, p, cb, 'json').fail(function(x){ alert('操作失敗：'+((x.responseJSON&&x.responseJSON.error)||x.status)); }); }

(function(){
    var h='';
    for (var y=THIS_YEAR; y>=THIS_YEAR-6; y--) h+='<option value="'+y+'"'+(y===THIS_YEAR?' selected':'')+'>'+y+'</option>';
    $('#fYear').html(h);
})();
$('#fYear').on('change', function(){ $('#fFrom,#fTo').val(''); load(); });
$('#fFrom,#fTo,#fClosed,#fAero').on('change', load);
$('#fKw').on('keydown', function(e){ if(e.which===13){ e.preventDefault(); load(); } });
$('#btnReload').on('click', load);
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });

function load(){
    $('#ncrBody').html('<tr><td colspan="12" style="padding:20px;color:#999;">查詢中…</td></tr>');
    var p = {action:'list', closed:$('#fClosed').val(), kw:$('#fKw').val(),
             aero:$('#fAero').is(':checked')?1:0};
    if($('#fFrom').val() && $('#fTo').val()){ p.from=$('#fFrom').val(); p.to=$('#fTo').val(); }
    else p.year=$('#fYear').val();
    if(ST.srcSel && ST.srcSel.length) p.sources = ST.srcSel.join(',');
    ajxGet(p, function(r){
        if(!r.ok) return;
        ST.rows=r.rows||[]; ST.sources=r.sources||{}; ST.enabled=r.enabled||[];
        ST.disp=r.dispositions||[]; ST.from=r.from; ST.to=r.to;
        renderSrcFilter(); renderStat(r.stat||{}); renderRows();
    });
}
function renderSrcFilter(){
    // 來源徽章當篩選鈕：預設全選。選項一律從後端拿，不在前端寫死來源清單（鐵律4）
    var h='<span class="muted-help">來源：</span>';
    var sel = ST.srcSel || ST.enabled;
    ST.enabled.forEach(function(k){
        var on = sel.indexOf(k)>=0;
        h+='<button class="btn btn-xs src-toggle" data-k="'+k+'" style="border:1px solid '+(on?'#C77C1A':'#ddd')+';'
          +'background:'+(on?'#F7E0BD':'#fff')+';color:#6B4423;">'+(on?'✓ ':'')+esc(ST.sources[k]||k)+'</button>';
    });
    h+='<button class="btn btn-xs btn-default" id="srcAll">全選</button>';
    $('#srcFilter').html(h);
}
$(document).on('click','.src-toggle',function(){
    var k=$(this).data('k');
    var sel = (ST.srcSel || ST.enabled).slice();
    var i=sel.indexOf(k);
    if(i>=0) sel.splice(i,1); else sel.push(k);
    if(!sel.length){ alert('至少要留一個來源'); return; }
    ST.srcSel=sel; load();
});
$(document).on('click','#srcAll',function(){ ST.srcSel=null; load(); });
function renderStat(s){
    $('#statRow').html(
      '<div class="sc"><div class="v">'+(s.total||0)+'</div><div class="l">不合格品筆數</div></div>'
     +'<div class="sc" style="border-left-color:#2c7a3f;"><div class="v">'+(s.closed||0)+'</div><div class="l">已結案</div></div>'
     +'<div class="sc" style="border-left-color:#DD5138;"><div class="v">'+((s.total||0)-(s.closed||0))+'</div><div class="l">未結案</div></div>'
     +'<div class="sc" style="border-left-color:#C77C1A;"><div class="v">'+(s.no_disp||0)+'</div><div class="l">尚未填處理方式</div></div>'
     +'<div class="sc" style="border-left-color:#DD5138;"><div class="v">'+(s.aero||0)+'</div><div class="l">航太類</div></div>');
}
function txtCell(i, k, val, srcVal, ro){
    var edited = (String(val||'') !== String(srcVal||'')) && String(val||'')!=='';
    /* 來源單自己就管好這幾欄時（品質異常處理單改版後：原因分類/責任單位/處置/結案都在那張單上），
       這裡一律唯讀——在登錄簿改一個字等於從側門繞過那張單的決策與簽核（使用者 2026-09-21 指定）。 */
    if (ro) return '<div class="ro-src" title="由來源單帶入，請到來源單修改">'+(esc(val)||'<span class="muted-help">－</span>')+'</div>';
    return '<input class="f-in'+(edited?' edited':'')+'" data-i="'+i+'" data-k="'+k+'" value="'+esc(val)+'" '
         + (CAN_ADMIN?'':'readonly')+' title="'+(srcVal?('系統帶入：'+esc(srcVal)):'系統沒有這個值，請補填')+'">';
}
function renderRows(){
    if(!ST.rows.length){ $('#ncrBody').html('<tr><td colspan="12" style="padding:20px;color:#999;">這個期間沒有不合格品紀錄</td></tr>'); return; }
    var dopt='<option value=""></option>';
    ST.disp.forEach(function(d){ dopt+='<option value="'+esc(d)+'">'+esc(d)+'</option>'; });
    var h='';
    ST.rows.forEach(function(r,i){
        h+='<tr>'
          +'<td>'+(i+1)+'</td>'
          +'<td><span class="src-badge src-'+esc(r.source)+'">'+esc(r.source_name)+'</span>'
          + (r.src_extra?'<div class="muted-help" style="font-size:10px;">'+esc(r.src_extra)+'</div>':'')
          + (r.is_aero?'<div><span class="aero-tag">航太</span></div>':'')+'</td>'
          +'<td>'+esc(dispDate(r.insp_date))+'</td>'
          +'<td class="tl">'+esc(r.client_name)+'</td>'
          +'<td class="tl">'+esc(r.part_name)+'</td>'
          +'<td class="tl">'+esc(r.drawing_no)+'</td>'
          +'<td>'+esc(r.qty)+'</td>'
          +'<td class="tl">'+esc(r.order_no)+'</td>'
          +'<td class="tl">'+txtCell(i,'cause',r.cause,r.src_cause,r.src_readonly)+'</td>'
          +'<td class="tl">'+txtCell(i,'resp_unit',r.resp_unit,r.src_resp,r.src_readonly)+'</td>'
          +'<td class="tl">'
          + (r.src_readonly
              ? '<div class="ro-src">'+(esc(r.disposition)||'<span class="muted-help">－</span>')+'</div>'
                +'<div class="muted-help" style="font-size:10px;">由來源單決定，請到來源單修改</div>'
              : '<select class="f-in" data-i="'+i+'" data-k="disposition" '+(CAN_ADMIN?'':'disabled')+'>'+dopt+'</select>'
                + txtCell(i,'disposition_note',r.disposition_note,'')
                + '<div class="muted-help" style="font-size:10px;">報廢請填報廢單號</div>')
          +'</td>'
          +'<td>'
          + (CAN_ADMIN && !r.src_readonly
              ? '<label style="font-weight:normal;font-size:11px;margin:0;"><input type="checkbox" class="f-ck" data-i="'+i+'" data-k="is_closed"'+(r.is_closed?' checked':'')+(r.src_closed?' disabled title="來源單已結案"':'')+'> 結案</label>'
                +'<label style="font-weight:normal;font-size:11px;margin:0;display:block;"><input type="checkbox" class="f-ck" data-i="'+i+'" data-k="is_aero"'+(r.is_aero?' checked':'')+'> 航太</label>'
                +(r.source==='manual'?'<button class="btn btn-xs btn-default" style="margin-top:2px;" onclick="delRow('+i+')" title="刪除這一列紙本補登">×</button>':'')
              : (r.is_closed?'<span class="closed-yes">已結案</span>':'<span class="closed-no">未結案</span>'))
          + (r.closed_date?'<div class="muted-help" style="font-size:10px;">'+esc(dispDate(r.closed_date))+'</div>':'')
          +'</td></tr>';
    });
    $('#ncrBody').html(h);
    ST.rows.forEach(function(r,i){ $('select.f-in[data-i="'+i+'"][data-k="disposition"]').val(r.disposition||''); });
}
/* 逐格存檔：離開欄位即存。只送「改動的那一格」——
   後端用 array_key_exists 判有沒有送，所以不會把其他欄位連帶清掉 */
function saveCell(i, k, val){
    var r=ST.rows[i];
    var p={action:'row_save', source:r.source, source_key:r.source_key};
    p[k]=val;
    ajxPost(p, function(res){ if(res.ok) load(); });
}
$(document).on('change','.f-in',function(){
    if(!CAN_ADMIN) return;
    var i=parseInt($(this).data('i')), k=$(this).data('k'), v=$(this).val();
    ST.rows[i][k]=v; saveCell(i,k,v);
});
$(document).on('change','.f-ck',function(){
    if(!CAN_ADMIN) return;
    var i=parseInt($(this).data('i')), k=$(this).data('k'), v=$(this).is(':checked')?1:0;
    ST.rows[i][k]=v; saveCell(i,k,v);
});
function delRow(i){
    var r=ST.rows[i];
    if(!confirm('確定刪除這一列紙本補登？')) return;
    ajxPost({action:'row_delete', source:r.source, source_key:r.source_key}, function(res){ if(res.ok) load(); });
}

<?php if ($perms['canAdmin']): ?>
/* ══ 紙本補登 ══ */
$('#btnManual').on('click', function(){
    var o='<option value=""></option>';
    ST.disp.forEach(function(d){ o+='<option value="'+esc(d)+'">'+esc(d)+'</option>'; });
    $('#mDisp').html(o);
    $('#mDate,#mClient,#mPart,#mDraw,#mQty,#mNo,#mCause,#mResp').val('');
    $('#mErr').text('');
    openMask('manMask');
});
$('#btnManSave').on('click', function(){
    // 前端即時驗證＋紅字說明原因（UI 規則第三總則）；後端同規則再擋一次
    var d=$('#mDate').val();
    if(!d){ $('#mErr').text('請填檢驗日期——這是這張表排序與歸屬期間的依據，不能留白。'); $('#mDate').focus(); return; }
    var q=$('#mQty').val().trim();
    if(q!=='' && isNaN(parseFloat(q))){ $('#mErr').text('數量請填數字。'); $('#mQty').focus(); return; }
    $('#mErr').text('');
    ajxPost({action:'manual_add', insp_date:d, client_name:$('#mClient').val(), part_name:$('#mPart').val(),
             drawing_no:$('#mDraw').val(), qty:q, order_no:$('#mNo').val(), cause:$('#mCause').val(),
             resp_unit:$('#mResp').val(), disposition:$('#mDisp').val()},
        function(r){ if(r.ok){ closeMask('manMask'); load(); } });
});

/* ══ 設定 ══ */
$('#btnSetting').on('click', function(){
    ajxGet({action:'setting_get'}, function(r){
        if(!r.ok) return;
        ST.set=r;
        renderSetDoc();
        var h='';
        Object.keys(r.sources||{}).forEach(function(k){
            h+='<label style="font-weight:normal;display:block;font-size:12.5px;">'
              +'<input type="checkbox" class="set-src" value="'+esc(k)+'"'+((r.enabled||[]).indexOf(k)>=0?' checked':'')+'> '
              +esc(r.sources[k])+'</label>';
        });
        $('#setSources').html(h);
        var s=$('#setStampTpl').html('<option value="0">（用系統預設印章）</option>');
        (r.stamp_tpls||[]).forEach(function(t){
            s.append('<option value="'+t.id+'">'+esc(t.tpl_name)+(t.type_name?'（'+esc(t.type_name)+'）':'')+'</option>');
        });
        s.val(String(parseInt(r.stamp_tpl_id||0)||0));
        openMask('setMask');
    });
});
function renderSetDoc(){
    var d=ST.set.doc;
    var txt=(window.EGAsDoc&&EGAsDoc.label)?EGAsDoc.label(d):(d?d.doc_no:'尚未綁定');
    $('#setDocLabel').text(txt);
}
function ncrPickDoc(){
    EGAsDoc.open({docs:ST.set.as_docs||[], current:parseInt(ST.set.doc_id||0)||0,
        title:'不合格品管制記錄表－AS 文件編號綁定',
        onSave:function(id,doc){ ST.set.doc_id=parseInt(id)||0; ST.set.doc=doc||null; renderSetDoc();
            ajxPost({action:'asdoc_save', doc_id:parseInt(id)||0}, function(){}); }});
}
function ncrClearDoc(){ ST.set.doc_id=0; ST.set.doc=null; renderSetDoc();
    ajxPost({action:'asdoc_save', doc_id:0}, function(){}); }
$('#btnSetSave').on('click', function(){
    var srcs=[];
    $('.set-src:checked').each(function(){ srcs.push($(this).val()); });
    if(!srcs.length){ alert('至少要保留一個來源，否則這張表會永遠是空的'); return; }
    ajxPost({action:'setting_save', sources:JSON.stringify(srcs),
             stamp_tpl_id:parseInt($('#setStampTpl').val()||0)||0},
        function(r){ if(r.ok){ closeMask('setMask'); ST.srcSel=null; load(); } });
});
<?php endif; ?>

/* ══ 列印（ai-rules/16）══ */
$('#btnPrint').on('click', function(){
    if(!ST.rows.length){ alert('目前條件下沒有資料可以列印'); return; }
    ajxGet({action:'print_meta', from:ST.from, to:ST.to}, function(m){
        if(!m.ok) return;
        window.__ownCompany = m.company||'';   // eg_stamp.js 畫預設回墨印要用（ai-rules/18 鐵則2）
        // 掃描實體章對照表是非同步載入的，沒等它會把有實體章的人印成預設 SVG 章
        if(window.EGStamp&&EGStamp.whenReady) EGStamp.whenReady(function(){ doPrint(m); });
        else doPrint(m);
    });
});
function doPrint(m){
    var title=(m.doc&&m.doc.doc_name)?m.doc.doc_name:'不合格品管制記錄表';
    var asTxt=String(m.doc_no_print||'').replace(/['\\]/g,'');
    var meta='<table class="p-meta"><colgroup><col style="width:9%"><col style="width:33%"><col style="width:9%"><col style="width:22%"><col style="width:9%"><col style="width:18%"></colgroup>'
        +'<tr><th>統計期間</th><td>'+esc(dispDate(ST.from))+' ~ '+esc(dispDate(ST.to))+'</td>'
        +'<th>筆數</th><td>'+ST.rows.length+' 筆</td>'
        +'<th>日期</th><td>'+esc(dispDate(m.biz_date))+'</td></tr></table>';
    var tb='';
    ST.rows.forEach(function(r,i){
        var disp=[r.disposition||'', r.disposition_note||''].filter(Boolean).join(' ');
        tb+='<tr>'
          +'<td>'+(i+1)+'</td>'
          +'<td class="tl">'+esc(r.client_name)+'</td>'
          +'<td class="tl">'+esc(r.part_name)+(r.is_aero?'<br>（航太）':'')+'</td>'
          +'<td class="tl">'+esc(r.drawing_no)+'</td>'
          +'<td>'+esc(r.qty)+'</td>'
          +'<td class="tl">'+esc(r.order_no)+'</td>'
          +'<td>'+esc(dispDate(r.insp_date))+'</td>'
          +'<td class="tl">'+esc(r.cause)+'</td>'
          +'<td class="tl">'+esc(r.resp_unit)+'</td>'
          +'<td class="tl">'+esc(disp)+'</td>'
          +'<td>'+(r.is_closed?('是'+(r.closed_date?'<br>'+esc(dispDate(r.closed_date)):'')):'')+'</td>'
          +'</tr>';
    });
    var tbl='<table class="p-tb"><colgroup><col style="width:4%"><col style="width:9%"><col style="width:12%"><col style="width:9%">'
        +'<col style="width:5%"><col style="width:11%"><col style="width:8%"><col style="width:17%"><col style="width:8%">'
        +'<col style="width:11%"><col style="width:6%"></colgroup>'
        +'<thead><tr><th>#</th><th>客戶</th><th>工件名稱</th><th>圖號/件號</th><th>數量</th><th>異常單編號</th>'
        +'<th>檢驗日期</th><th>原因</th><th>責任單位</th><th>處理方式</th><th>結案</th></tr></thead>'
        +'<tbody>'+tb+'</tbody></table>';
    var sign='<div class="p-sign"><div class="box"><div class="cap">主管審核</div></div>'
           +'<div class="box"><div class="cap">製表</div>'+makerStamp(m, dispDate(m.biz_date))+'</div></div>';
    var css='body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;margin:0;padding:0 4mm;color:#222;'
        +'-webkit-print-color-adjust:exact;print-color-adjust:exact;}*{box-sizing:border-box;}'
        +'.p-comp{font-size:22px;font-weight:bold;text-align:center;margin-bottom:2px;}'
        +'.p-title{font-size:16px;font-weight:bold;text-align:center;letter-spacing:5px;margin-bottom:8px;}'
        +'table{width:100%;max-width:100%;table-layout:fixed;border-collapse:collapse;}'
        +'table.p-meta{font-size:11px;margin-bottom:6px;}'
        +'table.p-meta th,table.p-meta td{border:1px solid #666;padding:3px 6px;text-align:left;overflow-wrap:break-word;word-break:break-word;}'
        +'table.p-meta th{background:#f3ead6;white-space:nowrap;}'
        +'table.p-tb{font-size:10px;}table.p-tb thead{display:table-header-group;}'
        +'table.p-tb th,table.p-tb td{border:1px solid #666;padding:2px 4px;text-align:center;overflow-wrap:break-word;word-break:break-word;}'
        +'table.p-tb thead th{background:#f3ead6;}table.p-tb td.tl{text-align:left;}'
        +'table.p-tb tr{break-inside:avoid;page-break-inside:avoid;}'
        +'.p-sign{margin-top:8px;display:flex;gap:10px;justify-content:flex-end;}'
        +'.p-sign .box{border:1px solid #666;min-width:150px;min-height:58px;padding:2px 6px;text-align:center;}'
        +'.p-sign .box .cap{font-size:10px;color:#555;border-bottom:1px solid #ccc;padding-bottom:1px;margin-bottom:2px;}'
        +'.stamp-wrap{display:inline-block;text-align:center;margin:2px 0;}'
        +'.stamp-wrap .stamp-title{display:block;font-size:11px;color:#999;}'
        +'.stamp-wrap svg{-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        +'.stamp-wrap svg.car-stamp{width:91px;height:91px;}'
        +'.stamp-wrap.stamp-fill{height:auto !important;display:inline-block;}'
        +'@page{size:A4 landscape;margin:12mm 8mm 16mm;'
        +(asTxt?" @bottom-right{ content:'"+asTxt+"'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; }":'')
        +'}';
    var body='<div class="p-comp">'+esc(m.company||'')+'</div><div class="p-title">'+esc(title)+'</div>'+meta+tbl+sign;
    try{ if(window.EGPrintLog) EGPrintLog.record({source:'qa_ncr_log', doc_name:title+' '+ST.from+'~'+ST.to, doc_kind:'form'}); }catch(e){}
    var w=window.open('','_blank');
    if(!w){ alert('請允許彈出視窗以列印'); return; }
    /* <!DOCTYPE html> 不可省：漏了會落入 Quirks Mode，scrollHeight 量不準、單頁也會誤印頁碼 */
    w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>'+esc(title)+'</title>'
        +'<style>'+css+'</style></head><body>'+body
        +'<scr'+'ipt>window.onload=function(){var onePage=(210-28)*96/25.4;'
        +'if(document.body.scrollHeight>onePage*0.92){var st=document.createElement(\'style\');'
        +'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; } }";'
        +'document.head.appendChild(st);}setTimeout(function(){window.print();},250);};</scr'+'ipt></body></html>');
    w.document.close(); w.focus();
}
function makerStamp(m, dateStr){
    var nm=(m&&m.maker_name)||'';
    if(!nm||!window.EGStamp) return esc(nm);
    var schema=(m&&m.stamp_tpl&&m.stamp_tpl.schema)?m.stamp_tpl.schema:null;
    var who=(m&&m.maker)||{};
    try{ return EGStamp.stamp(nm, dateStr, false, schema, who.dept||'', who.position||''); }catch(e){ return esc(nm); }
}

/* ══ CSV ══ */
$('#btnCsv').on('click', function(){
    if(!ST.rows.length){ alert('目前條件下沒有資料'); return; }
    var head=['#','來源','檢驗日期','客戶','工件名稱','圖號/件號','數量','異常單編號','原因','責任單位','處理方式','處理補充','航太','結案','結案日期','備註'];
    var rows=ST.rows.map(function(r,i){ return [i+1,r.source_name,r.insp_date,r.client_name,r.part_name,
        r.drawing_no,r.qty,r.order_no,r.cause,r.resp_unit,r.disposition,r.disposition_note,
        r.is_aero?'是':'',r.is_closed?'是':'',r.closed_date,r.remark]; });
    var q=function(v){ v=(v===null||v===undefined)?'':String(v); return '"'+v.replace(/"/g,'""')+'"'; };
    var csv=head.map(q).join(',')+'\r\n'+rows.map(function(r){ return r.map(q).join(','); }).join('\r\n');
    var blob=new Blob(['\uFEFF'+csv], {type:'text/csv;charset=utf-8;'});
    var a=document.createElement('a');
    a.href=URL.createObjectURL(blob); a.download='不合格品管制記錄表_'+ST.from+'_'+ST.to+'.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
});

<?php if ($perms['canView']): ?>
load();
<?php endif; ?>
</script>
</body>
</html>
