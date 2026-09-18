<?php
/**
 * 客戶滿意度（2-SM-02-03 統計資料表／2-SM-02-04 監控表）— 2026-09-18 建立
 *
 * 這一頁的重點是「能算的一律自動算」：
 *   準交率     ← 訂單追蹤交期 vs 出貨（判定口徑直接沿用 KPI 準時出貨率的年度設定）
 *   退貨率／件數 ← 退貨追蹤 ir_track
 *   客戶開立異常處理單 ← car_order（counterparty_type='customer'）
 * 這三個數字以前是人工一家一家查出來填進紙本的，現在開頁面就有。
 *
 * **但技術／服務／價格三項一定要人填**——系統沒有任何資料可以推導它們，
 * 那三項只能來自 2-SM-02-02 客戶滿意度調查問卷。硬編一個分數出來就是假資料。
 * 畫面上這三欄用不同底色並標「問卷填入」，避免有人以為系統漏算。
 *
 * 資料一律走 src/store/CustSatis_API.php；共用邏輯 src/common/cust_satis_lib.php
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/Sales/customer_satisfaction.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/cust_satis_lib.php';

$db = (new DBConnection())->getPDO();
cs_ensure_schema($db);
$perms = cs_perms($db, cs_current_user($db));
if (empty($_SESSION['cs_csrf'])) $_SESSION['cs_csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['cs_csrf'];
$roleLabel = $perms['isAdmin'] ? '系統管理者' : ($perms['canAdmin'] ? '客戶滿意度管理員' : ($perms['canView'] ? '檢閱' : '無權限'));
$thisYear = (int)date('Y');
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>客戶滿意度</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        /* 側欄：CSS 藏起來、ready 時再顯示（鐵律6，CSS 與 JS 必須成對，只抄一半側欄會整片消失） */
        #sidebar-menu { visibility: hidden; }
        :root{ --ink:#4A3524; --cream:#FCF7F0; --sand:#F7E0BD; --amber:#F0A24B;
               --amber-d:#C77C1A; --coral:#DD5138; --line:#E4D3BC; }
        body { background:#F6F1EA; }
        /* .right_col 第一個子元素一律 clear:both（鐵律6：頂欄高度 0 且浮動溢出，會把 BFC 子元素壓成寬 0） */
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .page-title h3 { color:var(--ink); margin:0; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid var(--amber-d);
                         border-radius:15px; background:#fff; color:var(--amber-d); }
        .page-help-btn:hover { background:var(--amber-d); color:#fff; }
        @media print { .page-help-btn { display:none !important; } }
        .warm-panel { background:#fff; border:1px solid var(--line); border-radius:8px; padding:14px; margin-bottom:12px; }
        .btn-warm { background:var(--amber); border:1px solid var(--amber-d); color:var(--ink); font-weight:bold; }
        .btn-warm:hover,.btn-warm:focus { background:var(--amber-d); color:#fff; }
        .btn-warm-o { background:#fff; border:1px solid var(--amber-d); color:var(--amber-d); }
        .btn-warm-o:hover { background:var(--sand); }
        .as-tag { font-size:12px; background:var(--sand); color:#6B4423; border-radius:10px; padding:2px 10px; font-weight:normal; }
        .role-tag { font-size:12px; background:#EFE3CF; color:#6B4423; border-radius:10px; padding:2px 10px; font-weight:normal; }
        .cs-tabs { display:flex; gap:6px; margin-bottom:12px; flex-wrap:wrap; }
        .cs-tab { border:1px solid var(--line); background:#fff; color:var(--ink); border-radius:16px;
                  padding:5px 16px; font-weight:600; cursor:pointer; }
        .cs-tab.active { background:var(--amber); border-color:var(--amber-d); }
        .cs-bar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:10px; }
        .muted-help { font-size:12px; color:#8a7560; }
        .note-box { font-size:12px; color:#6B4423; background:var(--cream); border:1px solid var(--line);
                    border-radius:6px; padding:8px 10px; margin-bottom:10px; line-height:1.7; }
        table.cs-tb { width:100%; border-collapse:collapse; font-size:12.5px; background:#fff; }
        table.cs-tb th, table.cs-tb td { border:1px solid var(--line); padding:4px 6px; text-align:center; }
        table.cs-tb thead th { background:var(--sand); color:#6B4423; white-space:nowrap; position:sticky; top:0; z-index:2; }
        /* 兩列表頭（逐客戶評分）：第二列一定要停在第一列正下方。
           兩列都寫 top:0 的話會疊在同一條上——分組標題「系統自動計算／滿意度評比」整列被蓋掉，
           而黏住的高度只剩一列，捲上來的資料列就從空出來的那條露出來（使用者回報的症狀）。
           第一列高度由 syncStatHead() 量好寫進 --cs-th1，字級或換行變了也會自己跟著調。 */
        table.cs-tb thead tr:nth-child(2) th { top:var(--cs-th1, 26px); }
        table.cs-tb td.tl { text-align:left; }
        table.cs-tb tbody tr:hover { background:#FFFDF8; }
        /* 自動算出來的欄位：淺綠底＝系統算的；問卷欄位：淺藍底＝一定要人填。
           顏色不是唯一資訊，表頭另外寫了「系統自動」「問卷填入」（ai-rules/10）。 */
        .col-auto { background:#F3FAF4; }
        .col-manual { background:#F4F7FB; }
        .sc-in { width:46px; text-align:center; border:1px solid var(--line); border-radius:4px; padding:1px 2px; font-size:12.5px; }
        .sc-in.filled { background:#FFF9EC; font-weight:700; }
        .rm-in { width:100%; border:1px solid var(--line); border-radius:4px; padding:1px 4px; font-size:12px; }
        .warn-over { color:var(--coral); font-weight:700; }
        .badge-sug { font-size:10px; color:#2c7a3f; display:block; line-height:1.2; }
        .cs-scroll { max-height:60vh; overflow:auto; border:1px solid var(--line); border-radius:6px; }
        .m-mask { position:fixed; inset:0; background:rgba(74,53,36,.45); z-index:10300; display:none; }
        .m-box { position:absolute; left:50%; top:4vh; transform:translateX(-50%); background:#fff;
                 border-radius:8px; box-shadow:0 10px 30px rgba(0,0,0,.3); display:flex; flex-direction:column; max-height:92vh; }
        .m-head { padding:10px 14px; background:var(--cream); border-bottom:1px solid var(--line);
                  font-weight:700; color:var(--ink); display:flex; align-items:center; gap:8px; }
        .m-body { padding:14px; overflow:auto; }
        .m-foot { padding:10px 14px; border-top:1px solid var(--line); text-align:right; background:#FDFBF7; }
        .help-doc h4 { color:var(--amber-d); font-size:15px; margin:14px 0 6px; }
        .help-doc li { margin-bottom:4px; line-height:1.7; }
    </style>
</head>
<body class="nav-md">
<div class="container body"><div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html'; ?>
<div class="right_col" role="main">

  <div class="page-title">
    <h3><i class="fa fa-smile-o" style="color:var(--amber-d);"></i> 客戶滿意度
      <span class="as-tag" id="asTagStat">2-SM-02-03 統計資料表</span>
      <span class="as-tag" id="asTagMon">2-SM-02-04 監控表</span>
      <span class="role-tag">目前身分：<?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></span>
      <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
    </h3>
  </div>

<?php if (!$perms['canView']): ?>
  <div class="warm-panel" style="color:var(--coral);">
    您沒有客戶滿意度的檢閱權限。請洽管理員於「權限設定 → 客戶滿意度」開通 <code>cs_view</code>（檢閱）或 <code>cs_admin</code>（管理員）。
  </div>
<?php else: ?>

  <div class="cs-tabs">
    <button class="cs-tab active" data-tab="stat">統計資料表（2-SM-02-03）</button>
    <button class="cs-tab" data-tab="monitor">監控表（2-SM-02-04）</button>
  </div>

  <div class="warm-panel">
    <div class="cs-bar">
      <label style="margin:0;">年度</label>
      <select id="fYear" class="form-control input-sm" style="width:100px;"></select>
      <label style="margin:0;">期間</label>
      <select id="fQuarter" class="form-control input-sm" style="width:120px;">
        <option value="0">整年度</option>
        <option value="1">第 1 季</option><option value="2">第 2 季</option>
        <option value="3">第 3 季</option><option value="4">第 4 季</option>
      </select>
      <span id="tabMonOnly" style="display:none;gap:8px;align-items:center;">
        <label style="margin:0;">客戶</label>
        <select id="fCustomer" class="form-control input-sm" style="width:220px;" data-eg-filter="輸入客戶名稱或代號篩選…"></select>
      </span>
      <button class="btn btn-sm btn-warm-o" id="btnReload"><i class="fa fa-refresh"></i> 重新計算</button>
      <span style="margin-left:auto;display:flex;gap:6px;">
        <button class="btn btn-sm btn-warm" id="btnPrint"><i class="fa fa-print"></i> 列印</button>
        <button class="btn btn-sm btn-warm-o" id="btnCsv"><i class="fa fa-file-excel-o"></i> CSV</button>
        <?php if ($perms['canAdmin']): ?>
        <button class="btn btn-sm btn-warm-o" id="btnSetting"><i class="fa fa-cog"></i> 設定</button>
        <?php endif; ?>
      </span>
    </div>
    <div class="note-box" id="modeNote">載入中…</div>
  </div>

  <!-- ═══ 統計資料表 ═══ -->
  <div id="tab-stat">
    <div class="warm-panel">
      <div class="cs-bar" style="margin-bottom:6px;">
        <strong style="color:var(--ink);">逐客戶評分（每項 10 分）</strong>
        <span class="muted-help" id="statCount"></span>
        <?php if ($perms['canAdmin']): ?>
        <span style="margin-left:auto;display:flex;gap:6px;">
          <button class="btn btn-xs btn-warm-o" id="btnCleanDead" style="display:none;"
                  title="本期間沒有出貨、只因為之前按過「帶入系統建議分」才留著的評分列；有人真的填過技術／服務／價格或備註的不會被刪"></button>
          <button class="btn btn-xs btn-warm-o" id="btnFillSuggest"
                  title="把系統算出來的品質分與交期分一次帶入所有客戶（已經填過的不覆蓋）">
            <i class="fa fa-magic"></i> 帶入系統建議分
          </button>
        </span>
        <?php endif; ?>
      </div>
      <div class="cs-scroll">
        <table class="cs-tb" id="statTable">
          <thead>
            <tr>
              <th rowspan="2" style="width:36px;">編號</th>
              <th rowspan="2" style="width:110px;">客戶</th>
              <th colspan="4" class="col-auto">系統自動計算（不必人填）</th>
              <th colspan="5">滿意度評比（每項 10 分）</th>
              <th rowspan="2" style="width:56px;">平均</th>
              <th rowspan="2" style="width:120px;">備註</th>
            </tr>
            <tr>
              <th class="col-auto" style="width:82px;">準交率</th>
              <th class="col-auto" style="width:62px;">退貨率</th>
              <th class="col-auto" style="width:54px;">退貨件</th>
              <th class="col-auto" style="width:54px;">異常單</th>
              <th class="col-auto" style="width:60px;">品質<div class="badge-sug">系統建議</div></th>
              <th class="col-auto" style="width:60px;">交期<div class="badge-sug">系統建議</div></th>
              <th class="col-manual" style="width:60px;">技術<div class="badge-sug" style="color:#2f5c96;">問卷填入</div></th>
              <th class="col-manual" style="width:60px;">服務<div class="badge-sug" style="color:#2f5c96;">問卷填入</div></th>
              <th class="col-manual" style="width:60px;">價格<div class="badge-sug" style="color:#2f5c96;">問卷填入</div></th>
            </tr>
          </thead>
          <tbody id="statBody"><tr><td colspan="13" style="padding:20px;color:#999;">載入中…</td></tr></tbody>
        </table>
      </div>
    </div>
    <div class="warm-panel">
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:6px;">
        <strong style="color:var(--ink);">綜合分析</strong>
        <label style="margin:0;font-weight:normal;">表單日期</label>
        <input type="date" id="statDate" class="form-control input-sm" style="width:150px;" <?= $perms['canAdmin']?'':'disabled' ?>>
        <span class="muted-help">這是表單上印的日期，也是 AS 版次回推的依據；留白＝用期間最後一天。</span>
      </div>
      <textarea id="statAnalysis" class="form-control" rows="4" <?= $perms['canAdmin']?'':'readonly' ?>
                placeholder="例：本期整體滿意度較上期提升，登裕退貨率偏高已開立矯正單追蹤…"></textarea>
      <?php if ($perms['canAdmin']): ?>
      <div style="text-align:right;margin-top:8px;">
        <button class="btn btn-sm btn-warm" id="btnSaveSummary"><i class="fa fa-save"></i> 儲存綜合分析與日期</button>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ═══ 監控表 ═══ -->
  <div id="tab-monitor" style="display:none;">
    <div class="warm-panel">
      <div class="note-box" id="monMetrics">請先在上方選擇客戶。</div>
      <table class="cs-tb" id="monTable">
        <thead><tr>
          <th style="width:36px;">No</th>
          <th style="width:150px;">調查項目</th>
          <th style="width:100px;">績效指標</th>
          <th style="width:110px;">調查結果</th>
          <th>客戶建議事項</th>
          <th>處理對策</th>
          <th>效果追蹤</th>
          <th style="width:110px;">異常處理單</th>
          <?php if ($perms['canAdmin']): ?><th style="width:40px;"></th><?php endif; ?>
        </tr></thead>
        <tbody id="monBody"></tbody>
      </table>
      <?php if ($perms['canAdmin']): ?>
      <div style="display:flex;gap:8px;margin-top:8px;">
        <button class="btn btn-xs btn-warm-o" id="btnMonAdd"><i class="fa fa-plus"></i> 新增調查項目</button>
        <button class="btn btn-sm btn-warm" id="btnMonSave" style="margin-left:auto;"><i class="fa fa-save"></i> 儲存監控表</button>
      </div>
      <?php endif; ?>
    </div>
  </div>

<?php endif; ?>
</div></div></div>

<!-- ══ 使用說明（鐵律7）══ -->
<div class="m-mask" id="helpUseMask">
  <div class="m-box" style="width:min(94vw,860px);">
    <div class="m-head"><i class="fa fa-question-circle"></i> 客戶滿意度－使用說明
      <span style="margin-left:auto;"><button class="btn btn-xs btn-default" data-close="helpUseMask">關閉</button></span></div>
    <div class="m-body help-doc">
      <h4>這一頁在做什麼</h4>
      <p>把 AS9100 的兩張紙本做成網頁，並且<b>把能算的數字全部自動算出來</b>：</p>
      <ul>
        <li><b>2-SM-02-03 客戶滿意度統計資料表</b>：逐客戶的五項評比（品質／交期／技術／服務／價格，每項 10 分）＋綜合分析。</li>
        <li><b>2-SM-02-04 客戶滿意度監控表</b>：逐客戶列出調查項目、績效指標、調查結果，未達標時開立異常處理單追蹤。</li>
      </ul>
      <h4>哪些是系統自動算的</h4>
      <ul>
        <li><b>準交率</b>＝訂單追蹤的交期 vs 出貨。<b>判定口徑直接沿用 KPI「準時出貨率」的年度設定</b>，
            所以同一家客戶在 KPI 頁與這一頁看到的數字是同一套規則算出來的。要改口徑請到
            <b>KPI 設定頁 → 準時出貨率 → 未交判定方式</b>，這裡刻意不另開一個開關（兩個開關必定打架）。</li>
        <li><b>退貨率／退貨件數</b>＝退貨追蹤（<code>ir_track</code>）。口徑是「<b>本期間交出去的貨，有多少被退回來</b>」：
            分母＝本期間出貨量、分子＝本期間出貨中被退回的量，所以<b>不可能超過 100%</b>。
            <b>退貨的月份跟出貨的月份常常不是同一個</b>，因此每一張退貨單會依「同客戶＋同料號、出貨日不晚於退貨日」
            <b>往回沖銷到它原本那批出貨</b>（由最近一次出貨開始沖），沖到哪一期就算在哪一期。
            滑鼠移到退貨欄可看到明細（ERP 這段期間開了幾張、其中幾筆是更早期間出的貨、有沒有查不到對應出貨的）。</li>
        <li><b>客戶開立異常處理單件數</b>＝異常矯正處理單中「對方是客戶」那一種，依填表日期歸期間。</li>
        <li><b>品質分／交期分的「系統建議分」</b>＝把退貨率與準交率照設定的級距換算成 10 分制。
            建議分只是帶入，<b>填進去之後仍可手動改</b>；級距可在「設定」調整。</li>
      </ul>
      <h4>表上會列出哪些客戶</h4>
      <p><b>只列本期間真的有出貨的客戶</b>（另加「有退貨算在本期間」的）。<b>只有訂單、沒有出貨的不列</b>——
         客戶滿意度評的是交出去的貨，而訂單那一側常有代號沒建主檔的假客戶（例 <code>NA</code>），
         列出來只會多一列沒人填得下去的空白。當然更不會把整份客戶主檔（900 多家）全部列出來。</p>
      <h4>哪些一定要人填（很重要）</h4>
      <p><b>技術、服務、價格三項系統算不出來</b>——站上沒有任何資料可以推導客戶對技術支援、服務態度、
         價格的感受，那三項只能來自 <b>2-SM-02-02 客戶滿意度調查問卷</b>回收的結果。
         所以這三欄用淺藍底標示「問卷填入」，空白代表<b>還沒填</b>而不是 0 分
         （平均分只平均「有填的」項目，避免沒回收問卷的客戶被算成低分）。</p>
      <h4>操作步驟</h4>
      <ol>
        <li>選年度與期間（整年度或某一季）。自動指標會立刻算出來。</li>
        <li>按「帶入系統建議分」把品質／交期分一次帶入（<b>已經填過的不會被覆蓋</b>）。</li>
        <li>照問卷填技術／服務／價格三欄。每一格離開欄位就自動存檔。</li>
        <li>填「綜合分析」與「表單日期」後儲存。</li>
        <li>切到監控表分頁、選一家客戶：調查項目與調查結果會自動帶入，
            補上客戶建議事項／處理對策／效果追蹤後儲存。<b>未達績效指標的那一列會標紅字</b>，
            照程序書由業務開立異常處理單，把單號填在最後一欄。</li>
        <li>按「列印」產生正式表單（A4 橫式，含公司全名與 AS 文件編號）。</li>
      </ol>
      <h4>要注意的兩件事</h4>
      <ul>
        <li><b>調查結果永遠是「現在算出來的」</b>，不是存檔當下的舊值。資料會持續進來（補綁出貨單、
            補開退貨單），所以同一張監控表過幾天再打開，數字可能會變——這是刻意的。</li>
        <li><b>若某客戶標了「ERP 未交筆數多於訂單筆數」</b>，代表訂單追蹤與 ERP 未交清單這兩份資料
            對不起來（例：某客戶在訂單追蹤裡 0 張訂單，ERP 卻有 42 筆未交），那一家的準交率僅供參考。
            這是既有的資料結構問題，不是這一頁算錯。</li>
      </ul>
      <h4>權限</h4>
      <ul>
        <li><code>cs_admin</code> 客戶滿意度管理員：填分數、綜合分析、維護監控表、改設定與 AS 文件綁定。</li>
        <li><code>cs_view</code> 檢閱：唯讀看全部（含列印）。</li>
        <li>系統管理者固定全權。設定入口：權限設定 → 客戶滿意度。</li>
      </ul>
    </div>
    <div class="m-foot"><button class="btn btn-default" data-close="helpUseMask">關閉</button></div>
  </div>
</div>

<?php if ($perms['canAdmin']): ?>
<!-- ══ 設定 ══ -->
<div class="m-mask" id="setMask">
  <div class="m-box" style="width:min(94vw,820px);">
    <div class="m-head"><i class="fa fa-cog"></i> 客戶滿意度設定
      <span style="margin-left:auto;"><button class="btn btn-xs btn-default" data-close="setMask">關閉</button></span></div>
    <div class="m-body">
      <div class="note-box">
        AS 文件綁定後：列印大標題＝本公司全名、表頭＝該文件的表單名稱、頁尾右下＝文件編號
        （四階文件會依表單日期回推當時版次）。
      </div>
      <div style="margin-bottom:14px;">
        <label style="font-weight:700;">① 統計資料表（2-SM-02-03）</label>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <span id="setStatLabel" style="flex:1;min-width:200px;padding:6px 10px;background:var(--cream);border:1px solid var(--line);border-radius:4px;">尚未綁定</span>
          <button class="btn btn-sm btn-default" onclick="csPickDoc('stat')"><i class="fa fa-search"></i> 選擇</button>
          <button class="btn btn-sm btn-default" onclick="csClearDoc('stat')"><i class="fa fa-times"></i> 取消</button>
        </div>
      </div>
      <div style="margin-bottom:14px;">
        <label style="font-weight:700;">② 監控表（2-SM-02-04）</label>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <span id="setMonLabel" style="flex:1;min-width:200px;padding:6px 10px;background:var(--cream);border:1px solid var(--line);border-radius:4px;">尚未綁定</span>
          <button class="btn btn-sm btn-default" onclick="csPickDoc('monitor')"><i class="fa fa-search"></i> 選擇</button>
          <button class="btn btn-sm btn-default" onclick="csClearDoc('monitor')"><i class="fa fa-times"></i> 取消</button>
        </div>
      </div>
      <div style="margin-bottom:14px;">
        <label style="font-weight:700;">③ 製表圖章模板</label>
        <div class="muted-help" style="margin-bottom:4px;">列印時製表欄會蓋上按下列印那個人的圖章；模板在「圖章管理 → 線上圖章設計」建立。</div>
        <select id="setStampTpl" class="form-control input-sm" style="max-width:400px;"><option value="0">（用系統預設印章）</option></select>
      </div>
      <div style="margin-bottom:14px;">
        <label style="font-weight:700;">④ 交期分換算級距（準交率 % → 分數）</label>
        <div class="muted-help" style="margin-bottom:4px;">由上往下比對，第一個「準交率 ≥ 門檻」的就是分數。</div>
        <table class="cs-tb" id="gdTable" style="max-width:360px;">
          <thead><tr><th>準交率 ≥</th><th>分數</th><th style="width:36px;"></th></tr></thead><tbody></tbody>
        </table>
        <button class="btn btn-xs btn-warm-o" onclick="csGradeAdd('gd')"><i class="fa fa-plus"></i> 加一列</button>
      </div>
      <div style="margin-bottom:14px;">
        <label style="font-weight:700;">⑤ 品質分換算級距（退貨率 % → 分數）</label>
        <div class="muted-help" style="margin-bottom:4px;">由上往下比對，第一個「退貨率 ≤ 門檻」的就是分數（退貨率越低分數越高）。</div>
        <table class="cs-tb" id="gqTable" style="max-width:360px;">
          <thead><tr><th>退貨率 ≤</th><th>分數</th><th style="width:36px;"></th></tr></thead><tbody></tbody>
        </table>
        <button class="btn btn-xs btn-warm-o" onclick="csGradeAdd('gq')"><i class="fa fa-plus"></i> 加一列</button>
      </div>
      <div>
        <label style="font-weight:700;">⑥ 監控表預設調查項目</label>
        <div class="muted-help" style="margin-bottom:4px;">新開一張監控表時預先帶入的項目。「自動指標」決定「調查結果」要不要由系統帶入。</div>
        <table class="cs-tb" id="miTable" style="max-width:640px;">
          <thead><tr><th>調查項目</th><th style="width:100px;">績效指標</th><th style="width:190px;">自動指標</th><th style="width:36px;"></th></tr></thead><tbody></tbody>
        </table>
        <button class="btn btn-xs btn-warm-o" onclick="csMiAdd()"><i class="fa fa-plus"></i> 加一列</button>
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
/* 側欄恢復顯示（與上方 CSS 的 visibility:hidden 成對，鐵律6） */
$(document).ready(function(){ $('#sidebar-menu').css('visibility','visible'); });

var API  = '../../src/store/CustSatis_API.php';
var CSRF = <?= json_encode($CSRF) ?>;
var CAN_ADMIN = <?= $perms['canAdmin'] ? 'true' : 'false' ?>;
var CAN_VIEW  = <?= $perms['canView']  ? 'true' : 'false' ?>;
var THIS_YEAR = <?= $thisYear ?>;
var ST = { tab:'stat', rows:[], mode:'', modeLabel:'', period:'', mon:[], monKeys:{}, set:null };

function esc(s){ return String(s===null||s===undefined?'':s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function dispDate(v){ var d=String(v||'').substr(0,10);
    return /^\d{4}-\d{2}-\d{2}$/.test(d) ? (window.egFmtDate?egFmtDate(d):d) : ''; }
function openMask(id){ $('#'+id).show(); }
function closeMask(id){ $('#'+id).hide(); }
$(document).on('click','[data-close]',function(){ closeMask($(this).data('close')); });
$(document).on('click','.m-mask',function(e){ if(e.target===this) $(this).hide(); });

function ajxGet(p, cb){ $.get(API, p, cb, 'json').fail(function(x){ alert('讀取失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); }); }
function ajxPost(p, cb){ p.csrf=CSRF; $.post(API, p, cb, 'json').fail(function(x){ alert('操作失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); }); }

/* ── 年度下拉：從今年往前 6 年、往後 1 年（補未來期間用） ── */
(function(){
    var h='';
    for (var y=THIS_YEAR+1; y>=THIS_YEAR-6; y--) h+='<option value="'+y+'"'+(y===THIS_YEAR?' selected':'')+'>'+y+'</option>';
    $('#fYear').html(h);
})();

$('.cs-tab').on('click', function(){
    ST.tab = $(this).data('tab');
    $('.cs-tab').removeClass('active'); $(this).addClass('active');
    $('#tab-stat').toggle(ST.tab==='stat');
    $('#tab-monitor').toggle(ST.tab==='monitor');
    $('#tabMonOnly').css('display', ST.tab==='monitor' ? 'flex' : 'none');
    if (ST.tab==='monitor') loadMonitor();
});
$('#fYear,#fQuarter').on('change', function(){ loadStat(); });
$('#btnReload').on('click', function(){ loadStat(); if(ST.tab==='monitor') loadMonitor(); });
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });

/* ══════════ 統計資料表 ══════════ */
function loadStat(){
    $('#statBody').html('<tr><td colspan="13" style="padding:20px;color:#999;">計算中…</td></tr>');
    ajxGet({action:'stat_list', year:$('#fYear').val(), quarter:$('#fQuarter').val()}, function(r){
        if(!r.ok) return;
        ST.rows = r.rows||[]; ST.mode = r.undone_mode; ST.modeLabel = r.undone_label; ST.period = r.period;
        $('#modeNote').html(
            '期間：<b>'+esc(r.period)+'</b>（'+dispDate(r.range[0])+' ~ '+dispDate(r.range[1])+'）　'
          + '共 <b>'+ST.rows.length+'</b> 家客戶　'
          + '<span class="muted-help">（本期間真的有出貨的才列；只有訂單、沒有出貨的不列）</span><br>'
          + '準交率判定方式：<b>'+esc(r.undone_label)+'</b>　'
          + '<span class="muted-help">（沿用 KPI「準時出貨率」該年度的設定，要改請到 KPI 設定頁，這裡刻意不另開開關）</span><br>'
          + '<span class="muted-help">退貨率＝<b>本期間出的貨被退回多少</b>（退貨依同客戶同料號往回沖銷到它原本的那批出貨，'
          + '所以退貨月份與出貨月份不同也算得對，上限 100%）。</span><br>'
          + '<span class="muted-help">淺綠欄＝系統自動算；淺藍欄（技術／服務／價格）系統算不出來，'
          + '請照 2-SM-02-02 客戶滿意度調查問卷回收結果填寫，留白代表「尚未填」不是 0 分。</span>');
        // 監控表的客戶下拉用同一份資料，不另外查一次
        var ch='<option value="">— 請選擇客戶 —</option>';
        ST.rows.forEach(function(x,i){
            ch+='<option value="'+i+'">'+esc(x.customer_name)+(x.customer_id?'（'+esc(x.customer_id)+'）':'')+'</option>';
        });
        var keep=$('#fCustomer').val();
        $('#fCustomer').html(ch).val(keep);
        renderStat();
        $('#statAnalysis').val((r.summary&&r.summary.analysis_text)||'');
        $('#statDate').val((r.summary&&r.summary.stat_date)||'');
    });
}
function scoreCell(i, key, cls){
    var v = ST.rows[i][key];
    return '<input class="sc-in'+(v!==null&&v!==''?' filled':'')+'" data-i="'+i+'" data-k="'+key+'" '
         + 'value="'+(v===null||v===undefined?'':v)+'" '+(CAN_ADMIN?'':'readonly')+' '
         + 'title="0~10 分，留白＝尚未填">';
}
/* 退貨欄的滑鼠提示：講清楚這個數字是怎麼來的，尤其是「退貨月份 ≠ 出貨月份」那幾筆跑去哪裡了 */
function retTip(r){
    if(r.return_rate===null && !(r.return_cnt||0)) return '本期間出的貨沒有被退回，或本期間沒有出貨（沒有出貨就不算率）';
    var t = '本期間出貨 '+(r.ship_qty||0)+'，其中被退回 '+(r.return_qty||0)+' → '+(r.return_rate===null?'—':r.return_rate+'%');
    if (r.return_erp_cnt||r.return_erp_qty)
        t += '\nERP 這段期間開出的退貨單：'+(r.return_erp_cnt||0)+' 筆 / '+(r.return_erp_qty||0);
    if (r.return_cross_qty)
        t += '\n其中 '+r.return_cross_qty+' 是更早期間出的貨（已算到那一期，不算在本期）';
    if (r.return_unmatched_qty)
        t += '\n另有 '+r.return_unmatched_qty+'（'+(r.return_unmatched_cnt||0)+' 筆）查不到對應的出貨（同客戶同料號），仍計入本期分子';
    return t;
}
function renderStat(){
    if(!ST.rows.length){ $('#statBody').html('<tr><td colspan="13" style="padding:20px;color:#999;">此期間沒有任何出貨，也沒有算在這段期間的退貨</td></tr>'); $('#statCount').text(''); syncStatHead(); return; }
    var h='';
    ST.rows.forEach(function(r,i){
        var over = parseInt(r.ontime_over||0)>0;
        h+='<tr>'
          +'<td>'+(i+1)+'</td>'
          +'<td class="tl">'+esc(r.customer_name)+(r.customer_id?'<br><span class="muted-help">'+esc(r.customer_id)+'</span>':'')
          /* 本期沒有出貨、只因為先前評過分才留著的列——填過的分數不可以憑空消失，但要標示清楚 */
          +(r.no_activity?'<br><span class="muted-help" style="color:#C77C1A;">本期無出貨（先前已評分）</span>':'')+'</td>'
          +'<td class="col-auto'+(over?' warn-over':'')+'" title="'+(over?'ERP 未交筆數比訂單筆數還多 '+r.ontime_over+' 筆，這兩份資料對不起來，本欄僅供參考':'準時 '+r.ontime_num+' / 訂單 '+r.ontime_den)+'">'
          + (r.ontime_rate===null?'—':r.ontime_rate+'%')
          + '<div class="muted-help">'+r.ontime_num+'/'+r.ontime_den+(over?' ⚠':'')+'</div></td>'
          +'<td class="col-auto" title="'+esc(retTip(r))+'">'+(r.return_rate===null?'—':r.return_rate+'%')
          + (r.return_qty?'<div class="muted-help">'+r.return_qty+'/'+(r.ship_qty||0)+'</div>':'')+'</td>'
          +'<td class="col-auto" title="'+esc(retTip(r))+'">'+(r.return_cnt||0)
          + ((r.return_cross_cnt||0)?'<div class="badge-sug" style="color:#8a7560;">ERP '+(r.return_erp_cnt||0)+'，'+r.return_cross_cnt+' 筆歸其他期</div>':'')
          + ((r.return_unmatched_cnt||0)?'<div class="badge-sug" style="color:#C77C1A;">查無出貨 '+r.return_unmatched_cnt+'</div>':'')+'</td>'
          +'<td class="col-auto">'+(r.car_count||0)+'</td>'
          +'<td class="col-auto">'+scoreCell(i,'score_quality')
          + '<div class="badge-sug">'+(r.suggest_quality===null?'—':'建議 '+r.suggest_quality)+'</div></td>'
          +'<td class="col-auto">'+scoreCell(i,'score_delivery')
          + '<div class="badge-sug">'+(r.suggest_delivery===null?'—':'建議 '+r.suggest_delivery)+'</div></td>'
          +'<td class="col-manual">'+scoreCell(i,'score_tech')+'</td>'
          +'<td class="col-manual">'+scoreCell(i,'score_service')+'</td>'
          +'<td class="col-manual">'+scoreCell(i,'score_price')+'</td>'
          +'<td><b data-avg="'+i+'">'+(r.avg_score===null||r.avg_score===undefined?'—':r.avg_score)+'</b></td>'
          +'<td><input class="rm-in" data-i="'+i+'" data-k="remark" value="'+esc(r.remark)+'" '+(CAN_ADMIN?'':'readonly')+'></td>'
          +'</tr>';
    });
    $('#statBody').html(h);
    var filled = ST.rows.filter(function(r){ return r.avg_score!==null&&r.avg_score!==undefined; }).length;
    $('#statCount').text('（已評分 '+filled+' / '+ST.rows.length+' 家）');
    var dead = ST.rows.filter(function(r){ return r.no_activity; }).length;
    $('#btnCleanDead').toggle(dead>0).html('<i class="fa fa-eraser"></i> 清除本期無出貨的 '+dead+' 列');
    syncStatHead();
}
/* 清除「本期無出貨（先前已評分）」那些列。先跟後端要一次實際會刪幾列再問使用者，
   不要用畫面上的數字當依據（有人填過技術／服務／價格或備註的後端會保留，數字不一樣）。 */
$(document).on('click', '#btnCleanDead', function(){
    ajxPost({action:'score_cleanup', year:$('#fYear').val(), quarter:$('#fQuarter').val(), dry:1}, function(r){
        if(!r||!r.ok){ alert('查不到可清除的列：'+((r&&r.error)||'未知原因')); return; }
        if(!r.del){ alert('沒有可以清除的列'+(r.kept?('（'+r.kept+' 列有人填過技術／服務／價格或備註，一律保留）'):'')); return; }
        var msg='將清除 '+r.del+' 列「本期間沒有出貨」的評分'
              + (r.kept?('\n另有 '+r.kept+' 列有人填過技術／服務／價格或備註，不會刪。'):'')
              + '\n\n例：'+(r.names||[]).slice(0,8).join('、')
              + '\n\n要繼續嗎？（刪掉之後按「帶入系統建議分」可以重新產生）';
        if(!confirm(msg)) return;
        ajxPost({action:'score_cleanup', year:$('#fYear').val(), quarter:$('#fQuarter').val()}, function(r2){
            if(!r2||!r2.ok){ alert('清除失敗：'+((r2&&r2.error)||'未知原因')); return; }
            alert('已清除 '+r2.deleted+' 列'+(r2.kept?('，保留 '+r2.kept+' 列有人填過的'):''));
            loadStat();
        });
    });
});
/* 兩列表頭的第二列要停在第一列正下方（CSS 的 --cs-th1）。
   第一列高度會隨字級、欄寬換行而變，寫死數字遲早對不上，所以量出來再寫回去。 */
function syncStatHead(){
    var t=document.getElementById('statTable');
    if(!t||!t.tHead||!t.tHead.rows[0]) return;
    var h=t.tHead.rows[0].getBoundingClientRect().height;
    if(h>0) t.style.setProperty('--cs-th1', h+'px');
}
$(window).on('resize', syncStatHead);
$(document).ready(syncStatHead);
function recalcAvg(i){
    var r=ST.rows[i], v=[];
    ['score_quality','score_delivery','score_tech','score_service','score_price'].forEach(function(k){
        if(r[k]!==null&&r[k]!==''&&r[k]!==undefined) v.push(parseFloat(r[k]));
    });
    r.avg_score = v.length ? Math.round(v.reduce(function(a,b){return a+b;},0)/v.length*10)/10 : null;
    $('[data-avg="'+i+'"]').text(r.avg_score===null?'—':r.avg_score);
}
/* 分數／備註：離開欄位即存（逐格存檔，不做「整頁儲存」——一次改一格是這張表的實際用法） */
$(document).on('change', '.sc-in,.rm-in', function(){
    if(!CAN_ADMIN) return;
    var i=parseInt($(this).data('i')), k=$(this).data('k'), v=$(this).val().trim();
    if(k!=='remark'){
        if(v!==''){
            var f=parseFloat(v);
            if(isNaN(f)||f<0||f>10){ alert('分數請填 0~10（留白＝尚未填）'); $(this).val(ST.rows[i][k]===null?'':ST.rows[i][k]); return; }
            v=Math.round(f*10)/10;
        } else v=null;
        $(this).toggleClass('filled', v!==null);
    }
    ST.rows[i][k] = (v===''?null:v);
    recalcAvg(i);
    var r=ST.rows[i];
    ajxPost({action:'score_save', year:$('#fYear').val(), quarter:$('#fQuarter').val(),
             customer_id:r.customer_id, customer_name:r.customer_name,
             score_quality:r.score_quality===null?'':r.score_quality,
             score_delivery:r.score_delivery===null?'':r.score_delivery,
             score_tech:r.score_tech===null?'':r.score_tech,
             score_service:r.score_service===null?'':r.score_service,
             score_price:r.score_price===null?'':r.score_price,
             remark:r.remark||''}, function(){});
});
$('#btnFillSuggest').on('click', function(){
    var n=0;
    ST.rows.forEach(function(r,i){
        var ch=false;
        // 已經填過的一律不覆蓋——人工調整過的分數被系統蓋掉是最不能接受的
        if((r.score_quality===null||r.score_quality==='') && r.suggest_quality!==null){ r.score_quality=r.suggest_quality; ch=true; }
        if((r.score_delivery===null||r.score_delivery==='') && r.suggest_delivery!==null){ r.score_delivery=r.suggest_delivery; ch=true; }
        if(ch){ n++;
            ajxPost({action:'score_save', year:$('#fYear').val(), quarter:$('#fQuarter').val(),
                     customer_id:r.customer_id, customer_name:r.customer_name,
                     score_quality:r.score_quality, score_delivery:r.score_delivery,
                     score_tech:r.score_tech===null?'':r.score_tech,
                     score_service:r.score_service===null?'':r.score_service,
                     score_price:r.score_price===null?'':r.score_price,
                     remark:r.remark||''}, function(){});
        }
    });
    ST.rows.forEach(function(r,i){ recalcAvg(i); });
    renderStat();
    alert(n?('已帶入 '+n+' 家客戶的品質／交期建議分（原本已填過的沒有被覆蓋）'):'沒有可帶入的建議分（都已填過，或期間內算不出指標）');
});
$('#btnSaveSummary').on('click', function(){
    ajxPost({action:'summary_save', year:$('#fYear').val(), quarter:$('#fQuarter').val(),
             analysis_text:$('#statAnalysis').val(), stat_date:$('#statDate').val()},
        function(r){ if(r.ok) alert('已儲存'); });
});

/* ══════════ 監控表 ══════════ */
$('#fCustomer').on('change', loadMonitor);
function curCustomer(){
    var i=$('#fCustomer').val();
    return (i==='' || i===null) ? null : ST.rows[parseInt(i)];
}
function loadMonitor(){
    var c=curCustomer();
    if(!c){ $('#monBody').html(''); $('#monMetrics').text('請先在上方選擇客戶。'); return; }
    ajxGet({action:'monitor_get', year:$('#fYear').val(), quarter:$('#fQuarter').val(),
            customer_id:c.customer_id, customer_name:c.customer_name}, function(r){
        if(!r.ok) return;
        ST.mon = r.rows||[]; ST.monKeys = r.auto_keys||{};
        var m=r.metrics||{};
        $('#monMetrics').html('客戶：<b>'+esc(c.customer_name)+'</b>　期間：<b>'+esc(r.period)+'</b><br>'
          +'本期自動指標　準交率 <b>'+(m.ontime_rate===null||m.ontime_rate===undefined?'—':m.ontime_rate+'%')+'</b>'
          +'（'+(m.ontime_num||0)+'/'+(m.ontime_den||0)+'）　'
          +'退貨率 <b>'+(m.return_rate===null||m.return_rate===undefined?'—':m.return_rate+'%')+'</b>'
          +'（'+(m.return_cnt||0)+' 件）　客戶開立異常處理單 <b>'+(m.car_count||0)+'</b> 件'
          +(parseInt(m.ontime_over||0)>0?'<br><span class="warn-over">⚠ ERP 未交筆數比訂單筆數多 '+m.ontime_over+' 筆，準交率僅供參考</span>':''));
        renderMonitor();
    });
}
function renderMonitor(){
    if(!ST.mon.length){ $('#monBody').html('<tr><td colspan="'+(CAN_ADMIN?9:8)+'" style="padding:16px;color:#999;">尚無調查項目，請按「新增調查項目」</td></tr>'); return; }
    var opts='';
    Object.keys(ST.monKeys).forEach(function(k){ opts+='<option value="'+esc(k)+'">'+esc(ST.monKeys[k])+'</option>'; });
    var h='';
    ST.mon.forEach(function(r,i){
        var meet = r.meet;
        var resCls = meet===false ? 'warn-over' : '';
        h+='<tr>'
          +'<td>'+(i+1)+'</td>'
          +'<td><input class="rm-in mon-f" data-i="'+i+'" data-k="item_name" value="'+esc(r.item_name)+'" '+(CAN_ADMIN?'':'readonly')+'></td>'
          +'<td><input class="rm-in mon-f" data-i="'+i+'" data-k="target_text" value="'+esc(r.target_text)+'" '+(CAN_ADMIN?'':'readonly')+' title="例：80分、2件/季、95%"></td>'
          +'<td class="col-auto '+resCls+'">'+(r.result_text?esc(r.result_text):'<span class="muted-help">—</span>')
          + (meet===false?'<div class="badge-sug warn-over">未達標</div>':(meet===true?'<div class="badge-sug">達標</div>':''))+'</td>'
          +'<td><input class="rm-in mon-f" data-i="'+i+'" data-k="customer_suggestion" value="'+esc(r.customer_suggestion)+'" '+(CAN_ADMIN?'':'readonly')+'></td>'
          +'<td><input class="rm-in mon-f" data-i="'+i+'" data-k="action_plan" value="'+esc(r.action_plan)+'" '+(CAN_ADMIN?'':'readonly')+'></td>'
          +'<td><input class="rm-in mon-f" data-i="'+i+'" data-k="effect_followup" value="'+esc(r.effect_followup)+'" '+(CAN_ADMIN?'':'readonly')+'></td>'
          +'<td><input class="rm-in mon-f" data-i="'+i+'" data-k="car_no" value="'+esc(r.car_no)+'" '+(CAN_ADMIN?'':'readonly')+' title="未達標時依程序書開立異常處理單，把單號填在這裡"></td>'
          +(CAN_ADMIN?'<td><button class="btn btn-xs btn-default" onclick="monDel('+i+')" title="移除這一列">×</button></td>':'')
          +'</tr>'
          +(CAN_ADMIN?'<tr><td></td><td colspan="'+(CAN_ADMIN?8:7)+'" class="tl" style="border-top:none;padding-top:0;">'
            +'<span class="muted-help">自動指標：</span><select class="mon-f" data-i="'+i+'" data-k="auto_key" style="font-size:11px;">'+opts+'</select>'
            +'<span class="muted-help">　←「調查結果」由這個指標即時算出來；選「（人工填寫）」就不自動帶。</span></td></tr>':'');
    });
    $('#monBody').html(h);
    ST.mon.forEach(function(r,i){ $('select.mon-f[data-i="'+i+'"][data-k="auto_key"]').val(r.auto_key||''); });
}
$(document).on('change', '.mon-f', function(){
    var i=parseInt($(this).data('i')), k=$(this).data('k');
    ST.mon[i][k] = $(this).val();
});
function monDel(i){ ST.mon.splice(i,1); renderMonitor(); }
$('#btnMonAdd').on('click', function(){
    ST.mon.push({item_name:'', target_text:'', auto_key:'', result_text:'', customer_suggestion:'',
                 action_plan:'', effect_followup:'', car_no:'', meet:null});
    renderMonitor();
});
$('#btnMonSave').on('click', function(){
    var c=curCustomer();
    if(!c){ alert('請先選擇客戶'); return; }
    var bad = ST.mon.filter(function(r){ return String(r.item_name||'').trim()===''; }).length;
    if(bad && !confirm('有 '+bad+' 列沒有填「調查項目」，儲存時會被略過。要繼續嗎？')) return;
    ajxPost({action:'monitor_save', year:$('#fYear').val(), quarter:$('#fQuarter').val(),
             customer_id:c.customer_id, customer_name:c.customer_name,
             monitor_date:$('#statDate').val(), items:JSON.stringify(ST.mon)},
        function(r){ if(r.ok){ alert('已儲存'); loadMonitor(); } });
});

/* ══════════ 列印（ai-rules/16）══════════ */
function printCss(asTxt){
    return 'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;margin:0;padding:0 4mm;color:#222;'
        +'-webkit-print-color-adjust:exact;print-color-adjust:exact;}*{box-sizing:border-box;}'
        +'.p-comp{font-size:22px;font-weight:bold;text-align:center;margin-bottom:2px;}'
        +'.p-title{font-size:16px;font-weight:bold;text-align:center;letter-spacing:5px;margin-bottom:8px;}'
        +'table{width:100%;max-width:100%;table-layout:fixed;border-collapse:collapse;}'
        +'table.p-meta{font-size:11px;margin-bottom:6px;}'
        +'table.p-meta th,table.p-meta td{border:1px solid #666;padding:3px 6px;text-align:left;overflow-wrap:break-word;word-break:break-word;}'
        +'table.p-meta th{background:#f3ead6;white-space:nowrap;}'
        +'table.p-tb{font-size:10.5px;}table.p-tb thead{display:table-header-group;}'
        +'table.p-tb th,table.p-tb td{border:1px solid #666;padding:2px 4px;text-align:center;overflow-wrap:break-word;word-break:break-word;}'
        +'table.p-tb thead th{background:#f3ead6;}table.p-tb td.tl{text-align:left;}'
        +'table.p-tb tr{break-inside:avoid;page-break-inside:avoid;}'
        +'.p-ana{border:1px solid #666;margin-top:6px;font-size:11px;}'
        +'.p-ana .cap{background:#f3ead6;padding:3px 6px;font-weight:700;border-bottom:1px solid #666;}'
        +'.p-ana .txt{padding:6px;min-height:22mm;white-space:pre-wrap;}'
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
}
function printWindow(title, body, asTxt){
    try{ if(window.EGPrintLog) EGPrintLog.record({source:'cust_satis', doc_name:title, doc_kind:'form'}); }catch(e){}
    var w=window.open('','_blank');
    if(!w){ alert('請允許彈出視窗以列印'); return; }
    /* <!DOCTYPE html> 不可省：漏了會落入 Quirks Mode，scrollHeight 量不準、單頁也會誤印頁碼 */
    w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>'+esc(title)+'</title>'
        +'<style>'+printCss(asTxt)+'</style></head><body>'+body
        +'<scr'+'ipt>window.onload=function(){var onePage=(210-28)*96/25.4;'
        +'if(document.body.scrollHeight>onePage*0.92){var st=document.createElement(\'style\');'
        +'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; } }";'
        +'document.head.appendChild(st);}setTimeout(function(){window.print();},250);};</scr'+'ipt></body></html>');
    w.document.close(); w.focus();
}
function makerStamp(meta, dateStr){
    var nm=(meta&&meta.maker_name)||'';
    if(!nm||!window.EGStamp) return esc(nm);
    var schema=(meta&&meta.stamp_tpl&&meta.stamp_tpl.schema)?meta.stamp_tpl.schema:null;
    var who=(meta&&meta.maker)||{};
    try{ return EGStamp.stamp(nm, dateStr, false, schema, who.dept||'', who.position||''); }catch(e){ return esc(nm); }
}
$('#btnPrint').on('click', function(){
    var which = ST.tab==='monitor' ? 'monitor' : 'stat';
    if(which==='monitor' && !curCustomer()){ alert('請先選擇客戶'); return; }
    ajxGet({action:'print_meta', which:which, year:$('#fYear').val(), quarter:$('#fQuarter').val()}, function(m){
        if(!m.ok) return;
        window.__ownCompany = m.company||'';   // eg_stamp.js 畫預設回墨印時要用（ai-rules/18 鐵則2）
        // 掃描實體章對照表是非同步載入的，沒等它會把有實體章的人印成預設 SVG 章
        if(window.EGStamp&&EGStamp.whenReady) EGStamp.whenReady(function(){ doPrint(which,m); });
        else doPrint(which,m);
    });
});
function printHead(m, fallback){
    var t=(m.doc&&m.doc.doc_name)?m.doc.doc_name:fallback;
    return {title:t, asTxt:String(m.doc_no_print||'').replace(/['\\]/g,''),
            html:'<div class="p-comp">'+esc(m.company||'')+'</div><div class="p-title">'+esc(t)+'</div>'};
}
function doPrint(which, m){
    var h = printHead(m, which==='monitor'?'客戶滿意度監控表':'客戶滿意度統計資料表');
    var biz = m.biz_date||'';
    if(which==='stat'){
        var meta='<table class="p-meta"><colgroup><col style="width:9%"><col style="width:26%"><col style="width:9%"><col style="width:26%"><col style="width:9%"><col style="width:21%"></colgroup>'
            +'<tr><th>統計期間</th><td>'+esc(ST.period)+'</td>'
            +'<th>日期</th><td>'+esc(dispDate(biz))+'</td>'
            +'<th>客戶家數</th><td>'+ST.rows.length+' 家</td></tr>'
            +'<tr><th>準交率判定</th><td colspan="5">'+esc(ST.modeLabel)+'</td></tr></table>';
        var tb='', n=0;
        ST.rows.forEach(function(r){
            // 列印版只印「有評分」的客戶——一整排空白列印出去給稽核看沒有意義
            if(r.avg_score===null||r.avg_score===undefined) return;
            n++;
            tb+='<tr><td>'+n+'</td><td class="tl">'+esc(r.customer_name)+'</td>'
              +'<td>'+(r.avg_score===null?'':r.avg_score)+'</td>'
              +['score_quality','score_delivery','score_tech','score_service','score_price'].map(function(k){
                  return '<td>'+(r[k]===null||r[k]===undefined?'':r[k])+'</td>'; }).join('')
              +'<td class="tl">'+esc(r.remark)+'</td></tr>';
        });
        if(!tb) tb='<tr><td colspan="9" style="padding:14px;">本期間尚無已評分的客戶</td></tr>';
        var tbl='<table class="p-tb"><colgroup><col style="width:5%"><col style="width:20%"><col style="width:9%">'
            +'<col style="width:9%"><col style="width:9%"><col style="width:9%"><col style="width:9%"><col style="width:9%"><col style="width:21%"></colgroup>'
            +'<thead><tr><th rowspan="2">編號</th><th rowspan="2">客戶</th><th rowspan="2">滿意度平均</th>'
            +'<th colspan="5">每項評比分數均為 10 分</th><th rowspan="2">備註</th></tr>'
            +'<tr><th>品質</th><th>交期</th><th>技術</th><th>服務</th><th>價格</th></tr></thead><tbody>'+tb+'</tbody></table>';
        var ana='<div class="p-ana"><div class="cap">綜合分析</div><div class="txt">'+esc($('#statAnalysis').val())+'</div></div>';
        var sign='<div class="p-sign"><div class="box"><div class="cap">核准</div></div>'
               +'<div class="box"><div class="cap">製表</div>'+makerStamp(m, dispDate(biz))+'</div></div>';
        printWindow(h.title+' '+ST.period, h.html+meta+tbl+ana+sign, h.asTxt);
    } else {
        var c=curCustomer();
        var meta2='<table class="p-meta"><colgroup><col style="width:10%"><col style="width:40%"><col style="width:10%"><col style="width:40%"></colgroup>'
            +'<tr><th>客戶名稱</th><td>'+esc(c.customer_name)+(c.customer_id?'（'+esc(c.customer_id)+'）':'')+'</td>'
            +'<th>日期</th><td>'+esc(dispDate(biz))+'　'+esc(ST.period)+'</td></tr></table>';
        var tb2='';
        ST.mon.forEach(function(r,i){
            if(String(r.item_name||'').trim()==='') return;
            tb2+='<tr><td>'+(i+1)+'</td><td class="tl">'+esc(r.item_name)+'</td>'
               +'<td>'+esc(r.target_text)+'</td><td>'+esc(r.result_text)+'</td>'
               +'<td class="tl">'+esc(r.customer_suggestion)+'</td><td class="tl">'+esc(r.action_plan)+'</td>'
               +'<td class="tl">'+esc(r.effect_followup)+'</td><td>'+esc(r.car_no)+'</td></tr>';
        });
        if(!tb2) tb2='<tr><td colspan="8" style="padding:14px;">尚無調查項目</td></tr>';
        var tbl2='<table class="p-tb"><colgroup><col style="width:5%"><col style="width:16%"><col style="width:10%"><col style="width:10%">'
            +'<col style="width:18%"><col style="width:18%"><col style="width:13%"><col style="width:10%"></colgroup>'
            +'<thead><tr><th>No</th><th>調查項目</th><th>績效指標</th><th>調查結果</th>'
            +'<th>客戶建議事項</th><th>處理對策</th><th>效果追蹤</th><th>異常處理單</th></tr></thead><tbody>'+tb2+'</tbody></table>';
        var note='<div class="p-ana"><div class="txt" style="min-height:0;">註：未達績效指標由業務開立異常處理單。</div></div>';
        var sign2='<div class="p-sign"><div class="box"><div class="cap">核准</div></div>'
                +'<div class="box"><div class="cap">製表</div>'+makerStamp(m, dispDate(biz))+'</div></div>';
        printWindow(h.title+' '+c.customer_name, h.html+meta2+tbl2+note+sign2, h.asTxt);
    }
}

/* ══════════ CSV ══════════ */
$('#btnCsv').on('click', function(){
    var head, rows;
    if(ST.tab==='monitor'){
        var c=curCustomer(); if(!c){ alert('請先選擇客戶'); return; }
        head=['No','調查項目','績效指標','調查結果','客戶建議事項','處理對策','效果追蹤','異常處理單'];
        rows=ST.mon.map(function(r,i){ return [i+1,r.item_name,r.target_text,r.result_text,
            r.customer_suggestion,r.action_plan,r.effect_followup,r.car_no]; });
        csvDump('客戶滿意度監控表_'+c.customer_name+'_'+ST.period, head, rows);
    } else {
        head=['編號','客戶','客戶代號','準交率%','準時/訂單','退貨率%','退貨件數','異常單件數',
              '品質','交期','技術','服務','價格','平均','備註'];
        rows=ST.rows.map(function(r,i){ return [i+1,r.customer_name,r.customer_id,
            r.ontime_rate,r.ontime_num+'/'+r.ontime_den,r.return_rate,r.return_cnt,r.car_count,
            r.score_quality,r.score_delivery,r.score_tech,r.score_service,r.score_price,r.avg_score,r.remark]; });
        csvDump('客戶滿意度統計資料表_'+ST.period, head, rows);
    }
});
function csvDump(name, head, rows){
    var q=function(v){ v=(v===null||v===undefined)?'':String(v); return '"'+v.replace(/"/g,'""')+'"'; };
    var csv=head.map(q).join(',')+'\r\n'+rows.map(function(r){ return r.map(q).join(','); }).join('\r\n');
    var blob=new Blob(['\uFEFF'+csv], {type:'text/csv;charset=utf-8;'});
    var a=document.createElement('a');
    a.href=URL.createObjectURL(blob); a.download=name+'.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
}

<?php if ($perms['canAdmin']): ?>
/* ══════════ 設定 ══════════ */
/* 事件委派（不綁死在載入當下那顆按鈕上），且任何一條失敗路徑都要講出來——
   原本 `if(!r.ok) return;` 是靜默結束，使用者看到的就是「按了完全沒反應」，連原因都查不到。 */
$(document).on('click', '#btnSetting', function(){
    ajxGet({action:'setting_get'}, function(r){
        if(!r||!r.ok){ alert('讀不到設定：'+((r&&r.error)||'未知原因')+'\n請重新整理頁面後再試一次。'); return; }
        ST.set=r;
        renderDocLabel('stat'); renderDocLabel('monitor');
        var s=$('#setStampTpl').html('<option value="0">（用系統預設印章）</option>');
        (r.stamp_tpls||[]).forEach(function(t){
            s.append('<option value="'+t.id+'">'+esc(t.tpl_name)+(t.type_name?'（'+esc(t.type_name)+'）':'')+'</option>');
        });
        s.val(String(parseInt(r.stamp_tpl_id||0)||0));
        renderGrade('gd', r.grade_delivery||[]);
        renderGrade('gq', r.grade_quality||[]);
        renderMi(r.monitor_items||[]);
        openMask('setMask');
    });
});
function renderDocLabel(which){
    var d = which==='stat' ? ST.set.stat_doc : ST.set.monitor_doc;
    var txt=(window.EGAsDoc&&EGAsDoc.label)?EGAsDoc.label(d):(d?d.doc_no:'尚未綁定');
    $(which==='stat'?'#setStatLabel':'#setMonLabel').text(txt);
}
function csPickDoc(which){
    var cur = which==='stat' ? ST.set.stat_doc_id : ST.set.monitor_doc_id;
    EGAsDoc.open({docs:ST.set.as_docs||[], current:parseInt(cur||0)||0,
        title:(which==='stat'?'客戶滿意度統計資料表':'客戶滿意度監控表')+'－AS 文件編號綁定',
        onSave:function(id,doc){
            if(which==='stat'){ ST.set.stat_doc_id=parseInt(id)||0; ST.set.stat_doc=doc||null; }
            else { ST.set.monitor_doc_id=parseInt(id)||0; ST.set.monitor_doc=doc||null; }
            renderDocLabel(which);
            ajxPost({action:'asdoc_save', which:which, doc_id:parseInt(id)||0}, function(){});
        }});
}
function csClearDoc(which){
    if(which==='stat'){ ST.set.stat_doc_id=0; ST.set.stat_doc=null; } else { ST.set.monitor_doc_id=0; ST.set.monitor_doc=null; }
    renderDocLabel(which);
    ajxPost({action:'asdoc_save', which:which, doc_id:0}, function(){});
}
function renderGrade(kind, arr){
    var key = kind==='gd' ? 'min' : 'max';
    var h='';
    arr.forEach(function(g,i){
        h+='<tr><td><input class="sc-in gr-f" style="width:70px;" data-kind="'+kind+'" data-i="'+i+'" data-k="'+key+'" value="'+esc(g[key])+'"></td>'
          +'<td><input class="sc-in gr-f" data-kind="'+kind+'" data-i="'+i+'" data-k="score" value="'+esc(g.score)+'"></td>'
          +'<td><button class="btn btn-xs btn-default" onclick="csGradeDel(\''+kind+'\','+i+')">×</button></td></tr>';
    });
    $('#'+(kind==='gd'?'gdTable':'gqTable')+' tbody').html(h);
}
$(document).on('change','.gr-f',function(){
    var kind=$(this).data('kind'), i=parseInt($(this).data('i')), k=$(this).data('k');
    var arr = kind==='gd' ? ST.set.grade_delivery : ST.set.grade_quality;
    arr[i][k]=parseFloat($(this).val())||0;
});
function csGradeAdd(kind){
    var arr = kind==='gd' ? (ST.set.grade_delivery=ST.set.grade_delivery||[]) : (ST.set.grade_quality=ST.set.grade_quality||[]);
    arr.push(kind==='gd'?{min:0,score:5}:{max:999,score:5});
    renderGrade(kind, arr);
}
function csGradeDel(kind,i){
    var arr = kind==='gd' ? ST.set.grade_delivery : ST.set.grade_quality;
    arr.splice(i,1); renderGrade(kind, arr);
}
function renderMi(arr){
    var keys={'':'（人工填寫）','satis_score':'客戶滿意度平均分(換算百分)','car_count':'客戶開立異常處理單件數',
              'ontime_rate':'準交率 %','return_rate':'退貨率 %'};
    var h='';
    arr.forEach(function(d,i){
        var opts='';
        Object.keys(keys).forEach(function(k){ opts+='<option value="'+esc(k)+'"'+((d.auto_key||'')===k?' selected':'')+'>'+esc(keys[k])+'</option>'; });
        h+='<tr><td><input class="rm-in mi-f" data-i="'+i+'" data-k="item_name" value="'+esc(d.item_name)+'"></td>'
          +'<td><input class="rm-in mi-f" data-i="'+i+'" data-k="target_text" value="'+esc(d.target_text)+'"></td>'
          +'<td><select class="mi-f" data-i="'+i+'" data-k="auto_key" style="width:100%;font-size:11px;">'+opts+'</select></td>'
          +'<td><button class="btn btn-xs btn-default" onclick="csMiDel('+i+')">×</button></td></tr>';
    });
    $('#miTable tbody').html(h);
}
$(document).on('change','.mi-f',function(){
    var i=parseInt($(this).data('i')), k=$(this).data('k');
    ST.set.monitor_items[i][k]=$(this).val();
});
function csMiAdd(){ ST.set.monitor_items=ST.set.monitor_items||[]; ST.set.monitor_items.push({item_name:'',target_text:'',auto_key:''}); renderMi(ST.set.monitor_items); }
function csMiDel(i){ ST.set.monitor_items.splice(i,1); renderMi(ST.set.monitor_items); }
$('#btnSetSave').on('click', function(){
    ajxPost({action:'setting_save',
             grade_delivery:JSON.stringify(ST.set.grade_delivery||[]),
             grade_quality:JSON.stringify(ST.set.grade_quality||[]),
             monitor_items:JSON.stringify((ST.set.monitor_items||[]).filter(function(x){ return String(x.item_name||'').trim()!==''; })),
             stamp_tpl_id:parseInt($('#setStampTpl').val()||0)||0},
        function(r){ if(r.ok){ alert('已儲存設定'); closeMask('setMask'); loadStat(); } });
});
<?php endif; ?>

<?php if ($perms['canView']): ?>
loadStat();
<?php endif; ?>
</script>
</body>
</html>
