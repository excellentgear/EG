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
        .cs-unbound { font-size:11px; line-height:1.4; color:var(--coral); display:inline-block; }
        .cs-bindable { cursor:pointer; text-decoration:underline; }
        .warn-bar { font-size:12.5px; color:#8a3a26; background:#FDEEE9; border:1px solid #F0B9A8;
                    border-radius:6px; padding:8px 10px; margin-bottom:10px; line-height:1.8; }
        /* 一行式提示（取代 alert，做完自己淡出，不用按確定） */
        #csToast { display:none; position:fixed; left:50%; top:16px; transform:translateX(-50%);
                   z-index:10500; background:var(--ink); color:#fff; font-size:13px;
                   padding:8px 16px; border-radius:16px; box-shadow:0 4px 14px rgba(0,0,0,.25); max-width:80vw; }
        .m-err { display:none; color:var(--coral); font-size:12.5px; margin-top:8px; line-height:1.6; }
        /* 客戶欄下方的問卷狀態小籤（td span 會被版型撐成 28px，一定要自己指定 line-height） */
        .svy-tag { display:inline-block; font-size:10px; line-height:16px; padding:0 6px; border-radius:9px;
                   margin-right:4px; background:#EFE3CF; color:#6B4423; white-space:nowrap; }
        .svy-tag.ok   { background:#DDEFD9; color:#2c6b3f; }
        .svy-tag.file { background:#E5EDF7; color:#2f5c96; cursor:pointer; }
        .svy-tag.act  { background:var(--amber); color:var(--ink); cursor:pointer; font-weight:700; }
        .svy-row { display:flex; gap:8px; align-items:center; padding:5px 8px; border-bottom:1px solid var(--line);
                   font-size:12.5px; }
        .svy-row:last-child { border-bottom:0; }
        .svy-row:hover { background:#FFFDF8; }
        table.q-tb { width:100%; border-collapse:collapse; font-size:12.5px; }
        table.q-tb th, table.q-tb td { border:1px solid var(--line); padding:4px 6px; }
        table.q-tb thead th { background:var(--sand); color:#6B4423; text-align:center; }
        table.q-tb td.qc { text-align:center; }
        table.q-tb tr.q-cat td { background:var(--cream); font-weight:700; color:var(--ink); }
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
<!-- 側欄載入時維持收合（全站慣例：117 支頁面都是 nav-sm，只有這頁寫成 nav-md 才會一載入就展開） -->
<body class="nav-sm">
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
    <!-- ERP 上的出貨對象對不到客戶主檔時的提示（沒有就整條不顯示） -->
    <div class="warn-bar" id="unboundBar" style="display:none;"></div>
  </div>

  <!-- ═══ 統計資料表 ═══ -->
  <div id="tab-stat">
    <div class="warm-panel">
      <div class="cs-bar" style="margin-bottom:6px;">
        <strong style="color:var(--ink);">逐客戶評分（每項 10 分）</strong>
        <span class="muted-help" id="statCount"></span>
        <?php if ($perms['canAdmin']): ?>
        <span style="margin-left:auto;display:flex;gap:6px;">
          <button class="btn btn-xs btn-warm" id="btnSurvey"
                  title="挑本年度要調查哪幾家、產生問卷、上傳客戶寄回來的問卷"><i class="fa fa-list-alt"></i> 問卷作業</button>
          <button class="btn btn-xs btn-warm-o" id="btnClearSuggest"
                  title="把系統帶入的品質／交期分清掉（技術／服務／價格與備註不動）"><i class="fa fa-eraser"></i> 清除建議分</button>
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
      <h4>客戶ID與「未綁定客戶ID」</h4>
      <p>ERP 的出貨／訂單／退貨上寫的是<b>客戶簡稱</b>，而簡稱常常與客戶主檔不一樣
         （例 ERP 寫「高鋒工業」、主檔簡稱是「高鋒」）。系統會先查<b>客戶主檔＋別名對照</b>把它對回同一家客戶，
         所以同一家不會裂成兩列，出貨量、退貨、準交率都算在一起。</p>
      <p>對不到的會在客戶欄標<b style="color:var(--coral);">⚠ 未綁定客戶ID</b>，上方也會列出來。
         沒有客戶ID的影響：<b>「客戶開立異常處理單」件數一定是 0</b>（那是用客戶代號查的），而且同一家可能被拆成好幾列。
         管理員點一下就能指定它是哪一家，綁定後系統會自動：①把它併進該客戶
         ②<b>回填出貨單（<code>is_list</code>）上空白的客戶編號</b>
         ③把原本掛在這個名稱底下已填的評分／監控表改掛到該客戶（主檔那邊已經有資料的那一期不覆蓋）。
         這個對應<b>全站共用</b>（會計對帳、應收、發票資料同時生效），要解除請到「會計 → 對帳作業 → 對應到客戶主檔」。</p>
      <h4>滿意度評比五欄＝客戶填在問卷上的數值（很重要）</h4>
      <p><b>品質／交期／技術／服務／價格五項一律是客戶寫在 2-SM-02-02 問卷上的答案</b>，不是系統推估的。
         空白代表<b>還沒填</b>而不是 0 分（平均分只平均「有填的」項目，沒回收問卷的客戶不會被算成低分）。</p>
      <p><b>「帶入系統建議分」是沒有問卷時的暫代值</b>（拿退貨率換品質分、準交率換交期分），
         保留給臨時要看趨勢用；正式表單請以問卷為準，用「清除建議分」把它清掉即可
         （<b>已經有回收問卷的客戶不會被清</b>，兩者在資料上長得一樣，系統靠「有沒有回收問卷」分辨）。</p>

      <h4>問卷作業（每年 11 月）</h4>
      <p>滿意度調查<b>不是每家客戶都做</b>：公司定在每年 11 月，由業務挑要調查的客戶寄問卷。整個流程都在「問卷作業」裡：</p>
      <ol>
        <li><b>① 受調查名單</b>：左邊是本期間真的有出貨的客戶（附出貨次數與金額），勾選即可；
            也可以用<b>隨機篩選</b>——先用「出貨次數 ≥ N」或「出貨金額前 N 大」縮出母體，再從裡面隨機抽 N 家
            （抽出來的是<b>加進</b>名單，已經挑好的不會被洗掉）。按「儲存名單」之後，
            <b>逐客戶評分表就只列名單內的客戶</b>；名單空白時才列出全部有出貨的客戶。</li>
        <li><b>② 產生問卷</b>：依 2-SM-02-02 的格式自動產生，<b>一家一頁</b>、A4 直式。
            客戶名稱印<b>簡稱</b>，電話與傳真自動由客戶基本資料帶出（沒有 E-mail 欄），
            公司全名、服務電話、回傳傳真都取自本公司主檔（禁寫死）。直接列印或存成 PDF 寄給客戶。</li>
        <li><b>③ 回收問卷附件</b>：客戶寄回／傳真掃描的問卷可<b>一次上傳多份</b>，傳完再逐份指定是哪一家客戶；
            指定後該客戶那一列會顯示「附件 N」，點一下就回到這裡。</li>
        <li><b>填問卷結果</b>：在客戶那一列按「填問卷」。三種填法可切換——
            <b>逐題勾選</b>（照紙本十題五個等第，系統自動換算）、<b>直接填五項分數</b>、<b>只填一個總分</b>（滿分 100，平均分配）。</li>
      </ol>
      <p><b>分數怎麼算</b>：等第對應分數為 非常滿意 10／很滿意 9／滿意 8／普通 6／不滿意 4（可在「設定」調整）。
         <b>每個大項先算自己那幾題的平均</b>（品質有 4 題、服務有 3 題），再由五個大項去平均成「平均」欄。
         沒勾的題目不列入平均，客戶漏答不會被當成 0 分。</p>
      <h4>操作步驟</h4>
      <ol>
        <li>選年度與期間（整年度或某一季）。自動指標會立刻算出來。</li>
        <li><b>按「問卷作業」挑本年度要調查的客戶</b>（手動勾或隨機篩選）→ 產生問卷寄給客戶。</li>
        <li>客戶回覆後：把問卷掃描檔上傳並指定客戶，再按該客戶的<b>「填問卷」</b>把分數填進去
            （逐題勾選會自動換算五項分數）。也可以直接在表上那五欄打分數，每一格離開欄位就自動存檔。</li>
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
<!-- ══ 綁定客戶ID（ERP 出貨簡稱 → 客戶主檔）══ -->
<div class="m-mask" id="bindMask">
  <div class="m-box" style="width:min(94vw,560px);">
    <div class="m-head"><i class="fa fa-link"></i> 綁定客戶ID
      <span style="margin-left:auto;"><button class="btn btn-xs btn-default" data-close="bindMask">關閉</button></span></div>
    <div class="m-body">
      <div class="note-box" style="margin-bottom:10px;">
        ERP 出貨單上寫的是<b id="bindAlias"></b>，客戶主檔裡查不到這個寫法（例：ERP 寫「高鋒工業」、主檔簡稱是「高鋒」）。
        指定它是哪一家之後：<br>
        ① 這一頁會把它<b>併進該客戶</b>（出貨、退貨、準交率一起算，不再分成兩列）<br>
        ② 自動<b>回填出貨單（is_list）上空白的客戶編號</b><br>
        ③ 這個對應<b>全站共用</b>（對帳、應收、發票資料同時生效），只需要綁這一次
      </div>
      <label style="margin:0;">對應到客戶主檔</label>
      <select id="bindCust" class="form-control input-sm" data-eg-filter="輸入客戶名稱或代號篩選…"
              style="width:100%;margin-top:4px;"></select>
      <div class="muted-help" style="margin-top:6px;">
        綁錯了可以到「會計 → 對帳作業 → 對應到客戶主檔」解除（解除時會把當初回填的客戶編號一併還原）。
      </div>
      <div class="m-err" id="bindErr"></div>
    </div>
    <div class="m-foot">
      <button class="btn btn-default" data-close="bindMask">取消</button>
      <button class="btn btn-warm" id="btnBindSave"><i class="fa fa-link"></i> 綁定並回填</button>
    </div>
  </div>
</div>

<!-- ══ 問卷作業（2-SM-02-02）══ -->
<div class="m-mask" id="svyMask">
  <div class="m-box" style="width:min(96vw,1080px);">
    <div class="m-head"><i class="fa fa-list-alt"></i> 問卷作業（2-SM-02-02 客戶滿意度調查問卷）
      <span style="margin-left:auto;"><button class="btn btn-xs btn-default" data-close="svyMask">關閉</button></span></div>
    <div class="m-body" style="max-height:74vh;">
      <div class="cs-tabs" style="margin-bottom:10px;">
        <button class="cs-tab active svy-tab" data-svy="target">① 受調查名單</button>
        <button class="cs-tab svy-tab" data-svy="print">② 產生問卷</button>
        <button class="cs-tab svy-tab" data-svy="file">③ 回收問卷附件</button>
      </div>

      <!-- ① 受調查名單 -->
      <div class="svy-pane" data-pane="target">
        <div class="note-box" style="margin-bottom:8px;">
          滿意度調查不是每家都做（公司定在每年 11 月，由業務挑要調查的客戶）。
          <b>存了名單之後，逐客戶評分表就只會列名單內的客戶</b>；名單空白時才列出全部有往來的客戶。
        </div>
        <div class="cs-bar" style="gap:6px;">
          <b>隨機篩選：</b>
          <select id="svyRndMode" class="form-control input-sm" style="width:190px;">
            <option value="times">本年度出貨次數 ≥</option>
            <option value="top">出貨金額前 N 大</option>
          </select>
          <input type="number" id="svyRndArg" class="form-control input-sm" style="width:80px;" value="3" min="1">
          <span>抽</span>
          <input type="number" id="svyRndN" class="form-control input-sm" style="width:70px;" value="10" min="1">
          <span>家</span>
          <button class="btn btn-xs btn-warm-o" id="btnSvyRnd"><i class="fa fa-random"></i> 隨機挑選</button>
          <span class="muted-help" id="svyRndHint"></span>
        </div>
        <div style="display:flex;gap:10px;align-items:flex-start;">
          <div style="flex:1;min-width:0;">
            <div style="display:flex;gap:6px;align-items:center;margin-bottom:4px;">
              <b>候選客戶</b><span class="muted-help" id="svyCandCnt"></span>
              <input type="text" id="svyCandKw" class="form-control input-sm" style="width:150px;margin-left:auto;"
                     data-eg-hint="打客戶名稱或代號">
            </div>
            <div id="svyCandList" style="max-height:320px;overflow:auto;border:1px solid var(--line);border-radius:6px;"></div>
          </div>
          <div style="width:320px;">
            <div style="display:flex;gap:6px;align-items:center;margin-bottom:4px;">
              <b>本年度受調查名單</b><span class="muted-help" id="svyPickCnt"></span>
              <button class="btn btn-xs btn-default" id="btnSvyClear" style="margin-left:auto;">全部清空</button>
            </div>
            <div id="svyPickList" style="max-height:320px;overflow:auto;border:1px solid var(--line);border-radius:6px;"></div>
          </div>
        </div>
        <div style="text-align:right;margin-top:8px;">
          <button class="btn btn-sm btn-warm" id="btnSvyTargetSave"><i class="fa fa-save"></i> 儲存名單</button>
        </div>
      </div>

      <!-- ② 產生問卷 -->
      <div class="svy-pane" data-pane="print" style="display:none;">
        <div class="note-box" style="margin-bottom:8px;">
          依 2-SM-02-02 的格式產生問卷，<b>一家一頁</b>，客戶名稱印簡稱、電話與傳真自動由客戶基本資料帶出（沒有 E-mail 欄）。
          勾選要印的客戶後按「產生問卷」，可直接列印或存 PDF 寄給客戶。
        </div>
        <div class="cs-bar">
          <label style="margin:0;"><input type="checkbox" id="svyPrnAll" checked> 全選</label>
          <span class="muted-help" id="svyPrnCnt"></span>
          <span style="margin-left:auto;">
            <button class="btn btn-sm btn-warm" id="btnSvyPrint"><i class="fa fa-print"></i> 產生問卷</button>
          </span>
        </div>
        <div id="svyPrnList" style="max-height:360px;overflow:auto;border:1px solid var(--line);border-radius:6px;"></div>
      </div>

      <!-- ③ 回收問卷附件 -->
      <div class="svy-pane" data-pane="file" style="display:none;">
        <div class="note-box" style="margin-bottom:8px;">
          客戶填好寄回（或傳真掃描）的問卷傳到這裡。<b>可以一次選很多個檔案</b>，傳完再逐份指定是哪一家客戶。
          指定之後，該客戶那一列就會顯示附件數；分數仍要在「填問卷結果」填進去。
        </div>
        <div class="cs-bar">
          <input type="file" id="svyFiles" multiple
                 accept=".pdf,.jpg,.jpeg,.png,.gif,.bmp,.tif,.tiff,.doc,.docx,.xls,.xlsx">
          <button class="btn btn-sm btn-warm" id="btnSvyUpload"><i class="fa fa-upload"></i> 上傳</button>
          <span class="muted-help">單檔上限 30MB，一次最多 30 個檔案</span>
        </div>
        <div id="svyFileList" style="max-height:360px;overflow:auto;border:1px solid var(--line);border-radius:6px;"></div>
      </div>
    </div>
  </div>
</div>

<!-- ══ 填問卷結果 ══ -->
<div class="m-mask" id="qMask">
  <div class="m-box" style="width:min(96vw,900px);">
    <div class="m-head"><i class="fa fa-pencil-square-o"></i> 填問卷結果：<span id="qCustName"></span>
      <span style="margin-left:auto;"><button class="btn btn-xs btn-default" data-close="qMask">關閉</button></span></div>
    <div class="m-body" style="max-height:74vh;">
      <div class="cs-bar" style="gap:14px;">
        <b>填答方式</b>
        <label style="margin:0;font-weight:normal;"><input type="radio" name="qMode" value="item" checked> 逐題勾選（依問卷自動算分）</label>
        <label style="margin:0;font-weight:normal;"><input type="radio" name="qMode" value="direct"> 直接填五項分數</label>
        <label style="margin:0;font-weight:normal;"><input type="radio" name="qMode" value="total"> 只填一個總分</label>
      </div>
      <div id="qItemPane"></div>
      <div id="qDirectPane" style="display:none;"></div>
      <div id="qTotalPane" style="display:none;">
        <div class="cs-bar"><label style="margin:0;">客戶回覆的總分</label>
          <input type="number" id="qTotal" class="form-control input-sm" style="width:100px;" min="0" max="100" step="0.1">
          <span class="muted-help">滿分 100；系統會平均換算成五項各自的 10 分制分數</span></div>
      </div>
      <div class="note-box" id="qCalc" style="margin-top:8px;"></div>
      <div class="cs-bar" style="margin-top:8px;">
        <label style="margin:0;">問卷填寫者</label>
        <input type="text" id="qResp" class="form-control input-sm" style="width:130px;">
        <label style="margin:0;">職稱</label>
        <input type="text" id="qRespTitle" class="form-control input-sm" style="width:130px;">
        <label style="margin:0;">客戶填表日期</label>
        <input type="date" id="qDate" class="form-control input-sm" style="width:150px;">
      </div>
      <label style="margin:6px 0 2px;">客戶的建言／抱怨（問卷下半部那一格）</label>
      <textarea id="qComment" class="form-control" rows="3"></textarea>
      <div class="m-err" id="qErr"></div>
    </div>
    <div class="m-foot">
      <button class="btn btn-default" data-close="qMask">取消</button>
      <button class="btn btn-warm" id="btnQSave"><i class="fa fa-save"></i> 儲存並帶入分數</button>
    </div>
  </div>
</div>

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
        <label style="font-weight:700;">③ 調查問卷（2-SM-02-02）</label>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <span id="setSvyLabel" style="flex:1;min-width:200px;padding:6px 10px;background:var(--cream);border:1px solid var(--line);border-radius:4px;">尚未綁定</span>
          <button class="btn btn-sm btn-default" onclick="csPickDoc('survey')"><i class="fa fa-search"></i> 選擇</button>
          <button class="btn btn-sm btn-default" onclick="csClearDoc('survey')"><i class="fa fa-times"></i> 取消</button>
        </div>
      </div>
      <div style="margin-bottom:14px;">
        <label style="font-weight:700;">④ 製表圖章模板</label>
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
/* onErr：自己接手錯誤（跳窗內顯示紅字等）。沒傳才用預設的 alert。 */
function ajxPost(p, cb, onErr){ p.csrf=CSRF; $.post(API, p, cb, 'json').fail(function(x){
    var m=(x.responseJSON&&x.responseJSON.error)||x.status;
    if(typeof onErr==='function') onErr(m); else alert('操作失敗：'+m);
}); }

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
/* $done：整份資料重畫完之後要做的事（綁定完之後把捲動位置放回去用的，見 csReloadStat）。
   這一支本來就是 AJAX，畫面不會整頁重新載入。 */
function loadStat(done){
    $('#statBody').html('<tr><td colspan="13" style="padding:20px;color:#999;">計算中…</td></tr>');
    ajxGet({action:'stat_list', year:$('#fYear').val(), quarter:$('#fQuarter').val()}, function(r){
        if(!r.ok) return;
        ST.rows = r.rows||[]; ST.mode = r.undone_mode; ST.modeLabel = r.undone_label; ST.period = r.period;
        $('#modeNote').html(
            '期間：<b>'+esc(r.period)+'</b>（'+dispDate(r.range[0])+' ~ '+dispDate(r.range[1])+'）　'
          + '共 <b>'+ST.rows.length+'</b> 家客戶　'
          + (parseInt(r.target_count||0)
                ? '<span class="muted-help">（本年度受調查名單 '+r.target_count+' 家，只列名單內的客戶；要改名單請按「問卷作業」）</span>'
                : '<span class="muted-help">（<b>尚未建立本年度受調查名單</b>，目前列出全部有出貨的客戶；'
                  + '滿意度調查不是每家都做，請按「問卷作業」挑要調查的客戶）</span>')+'<br>'
          + '準交率判定方式：<b>'+esc(r.undone_label)+'</b>　'
          + '<span class="muted-help">（沿用 KPI「準時出貨率」該年度的設定，要改請到 KPI 設定頁，這裡刻意不另開開關）</span><br>'
          + '<span class="muted-help">退貨率＝<b>本期間出的貨被退回多少</b>（退貨依同客戶同料號往回沖銷到它原本的那批出貨，'
          + '所以退貨月份與出貨月份不同也算得對，上限 100%）。</span><br>'
          + '<span class="muted-help">左半邊（準交率／退貨率／退貨件／異常單）是系統自動算的；'
          + '<b>「滿意度評比」五欄一律填客戶寫在 2-SM-02-02 問卷上的數值</b>——'
          + '按客戶那一列的「填問卷」逐題勾選即自動換算，留白代表「尚未填」不是 0 分。'
          + '「帶入系統建議分」只是拿退貨率／準交率推估品質與交期分，<b>沒有問卷時的暫代值</b>，'
          + '要清掉按「清除建議分」（有回收問卷的不會被清）。</span>');
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
        if(typeof done==='function') done();
    });
}
/* 重新計算並把畫面位置留在原地（頁面捲軸與表格內捲軸都要），
   否則使用者會覺得「整頁重載」——資料一多就跳回最上面，本來看到哪一列全忘了。 */
function csReloadStat(msg){
    var sc=$('.cs-scroll').scrollTop(), wy=window.pageYOffset;
    loadStat(function(){
        $('.cs-scroll').scrollTop(sc); window.scrollTo(0, wy);
        if(msg) csToast(msg);
    });
}
/* 一行式提示（取代 alert）：做完就好，不要一直跳窗要人按確定 */
function csToast(msg){
    var $t=$('#csToast');
    if(!$t.length) $t=$('<div id="csToast"></div>').appendTo('body');
    $t.text(msg).stop(true,true).fadeIn(120);
    clearTimeout(csToast._t);
    csToast._t=setTimeout(function(){ $t.fadeOut(400); }, 4000);
}
function scoreCell(i, key, cls){
    var v = ST.rows[i][key];
    return '<input class="sc-in'+(v!==null&&v!==''?' filled':'')+'" data-i="'+i+'" data-k="'+key+'" '
         + 'value="'+(v===null||v===undefined?'':v)+'" '+(CAN_ADMIN?'':'readonly')+' '
         + 'title="0~10 分，留白＝尚未填">';
}
/* 客戶欄下方的問卷狀態：受調查／已回收／附件幾份，以及「填問卷」入口。
   分數是客戶填在問卷上的數值，所以入口放在客戶旁邊而不是另開一欄（表格已經 13 欄）。 */
function surveyCell(r, i){
    var h='';
    if(r.in_target)   h+='<span class="svy-tag">受調查</span>';
    if(r.survey_done) h+='<span class="svy-tag ok">已回收</span>';
    if(r.file_count)  h+='<span class="svy-tag file" data-i="'+i+'">附件 '+r.file_count+'</span>';
    if(CAN_ADMIN)     h+='<span class="svy-tag act" data-i="'+i+'">'+(r.survey_done?'改問卷':'填問卷')+'</span>';
    return h?('<div style="margin-top:3px;line-height:1.9;">'+h+'</div>'):'';
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
          +'<td class="tl">'+esc(r.customer_name)
          +(r.customer_id?'<br><span class="muted-help">'+esc(r.customer_id)+'</span>'
            /* 客戶主檔（含別名）查不到這個 ERP 寫法＝沒有客戶ID，一定要標出來並給綁定入口，
               不然這一家的異常處理單件數（那是用客戶代號查的）永遠會是 0 而且看不出原因 */
            :'<br><span class="cs-unbound'+(CAN_ADMIN?' cs-bindable':'')+'" data-alias="'+esc(r.customer_name)+'">'
             +'⚠ 未綁定客戶ID'+(CAN_ADMIN?'（點此綁定）':'')+'</span>')
          /* 本期沒有出貨、只因為先前評過分才留著的列——填過的分數不可以憑空消失，但要標示清楚 */
          +(r.no_activity?'<br><span class="muted-help" style="color:#C77C1A;">本期無出貨（先前已評分）</span>':'')
          + surveyCell(r, i)+'</td>'
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
    renderUnboundBar();
    var dead = ST.rows.filter(function(r){ return r.no_activity; }).length;
    $('#btnCleanDead').toggle(dead>0).html('<i class="fa fa-eraser"></i> 清除本期無出貨的 '+dead+' 列');
    syncStatHead();
}
/* 清除「本期無出貨（先前已評分）」那些列。先跟後端要一次實際會刪幾列再問使用者，
   不要用畫面上的數字當依據（有人填過技術／服務／價格或備註的後端會保留，數字不一樣）。 */
$(document).on('click', '#btnCleanDead', function(){
    ajxPost({action:'score_cleanup', year:$('#fYear').val(), quarter:$('#fQuarter').val(), dry:1}, function(r){
        if(!r||!r.ok){ csToast('查不到可清除的列：'+((r&&r.error)||'未知原因')); return; }
        if(!r.del){ csToast('沒有可以清除的列'+(r.kept?('（'+r.kept+' 列有人填過技術／服務／價格或備註，一律保留）'):'')); return; }
        var msg='將清除 '+r.del+' 列「本期間沒有出貨」的評分'
              + (r.kept?('\n另有 '+r.kept+' 列有人填過技術／服務／價格或備註，不會刪。'):'')
              + '\n\n例：'+(r.names||[]).slice(0,8).join('、')
              + '\n\n要繼續嗎？（刪掉之後按「帶入系統建議分」可以重新產生）';
        if(!confirm(msg)) return;
        ajxPost({action:'score_cleanup', year:$('#fYear').val(), quarter:$('#fQuarter').val()}, function(r2){
            if(!r2||!r2.ok){ csToast('清除失敗：'+((r2&&r2.error)||'未知原因')); return; }
            // 刪除前已經問過一次（那一關是必要的），做完就用一行提示帶過不再跳窗
            csReloadStat('已清除 '+r2.deleted+' 列'+(r2.kept?('，保留 '+r2.kept+' 列有人填過的'):''));
        }, function(m){ csToast('清除失敗：'+m); });
    });
});
/* ERP 出貨對象對不到客戶主檔的提示列。
   這種列沒有客戶代號，所以「客戶開立異常處理單件數」（用代號查 car_order）一定是 0，
   而且同一家客戶會因為 ERP 寫法不同裂成兩列，所以一定要提醒去綁。 */
function renderUnboundBar(){
    var un = ST.rows.filter(function(r){ return r.unbound; });
    if(!un.length){ $('#unboundBar').hide().empty(); return; }
    var h = '<b>有 '+un.length+' 個出貨對象沒有對應到客戶主檔（沒有客戶ID）</b>：'
          + un.map(function(r){
                return CAN_ADMIN
                    ? '<a href="javascript:;" class="cs-bindable" data-alias="'+esc(r.customer_name)+'">'+esc(r.customer_name)+'</a>'
                    : esc(r.customer_name);
            }).join('、')
          + '<br><span class="muted-help">ERP 的寫法與客戶主檔簡稱不一樣（例「高鋒工業」vs「高鋒」）就會這樣。'
          + (CAN_ADMIN ? '點名稱綁定，綁完會自動把同一家併成一列並回填出貨單上的客戶編號。'
                       : '請洽管理員綁定。') + '</span>';
    $('#unboundBar').html(h).show();
}
<?php if ($perms['canAdmin']): ?>
/* ── 綁定客戶ID ── */
var CUSTMASTER = null;
$(document).on('click', '.cs-bindable', function(){
    var alias = $(this).data('alias'); if(!alias) return;
    $('#bindAlias').text('「'+alias+'」');
    $('#btnBindSave').data('alias', alias);
    var fill = function(){
        var h='<option value="">— 請選擇客戶 —</option>';
        (CUSTMASTER||[]).forEach(function(c){
            h+='<option value="'+esc(c.id)+'">'+esc(c.name)+'（'+esc(c.id)+'）'+(c.full?'　'+esc(c.full):'')+'</option>';
        });
        $('#bindCust').html(h).val('').trigger('change');   // 讓共用篩選框重新抓一次選項快照
        bindClearFilter();
        $('#bindErr').hide().empty();
        openMask('bindMask');
    };
    if(CUSTMASTER) return fill();
    ajxGet({action:'cust_master'}, function(r){
        if(!r||!r.ok){ csToast('讀不到客戶主檔：'+((r&&r.error)||'未知原因')); return; }
        CUSTMASTER = r.rows||[]; fill();
    });
});
/* 每次開跳窗都要把上次打的關鍵字清掉（共用檔 eg_input_rules.js 規則7 長出來的那個篩選框），
   不然下一家客戶一開跳窗還停在上一次的「g1」，看起來像只有一兩家可選。
   清空後補送一次 input 事件，讓它把選項還原成全部。 */
function bindClearFilter(){
    var box=document.querySelector('#bindMask .eg-filter-box');
    if(!box || box.value==='') return;
    box.value='';
    box.dispatchEvent(new Event('input', {bubbles:true}));
}
$(document).on('click', '#bindMask [data-close]', bindClearFilter);
$('#btnBindSave').on('click', function(){
    var alias=$(this).data('alias'), cid=$('#bindCust').val();
    var $err=$('#bindErr');
    if(!cid){ $err.text('請先選擇要對應的客戶').show(); return; }
    $err.hide().empty();
    // 跳窗本身就是確認畫面，不再多一層 confirm；結果用一行提示帶過，不跳 alert
    ajxPost({action:'cust_bind', alias:alias, customer_id:cid}, function(r){
        if(!r||!r.ok){ $err.text('綁定失敗：'+((r&&r.error)||'未知原因')).show(); return; }
        /* 成功：關窗＋一行提示，不跳 alert */
        bindClearFilter();
        closeMask('bindMask');
        csReloadStat(r.message||'已綁定');   // AJAX 重算，畫面位置留在原地
    }, function(m){ $err.text('綁定失敗：'+m).show(); });
});
<?php endif; ?>

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
            if(isNaN(f)||f<0||f>10){ csToast('分數請填 0~10（留白＝尚未填）'); $(this).val(ST.rows[i][k]===null?'':ST.rows[i][k]); return; }
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
    csToast(n?('已帶入 '+n+' 家客戶的品質／交期建議分（原本已填過的沒有被覆蓋）'):'沒有可帶入的建議分（都已填過，或期間內算不出指標）');
});
$('#btnSaveSummary').on('click', function(){
    ajxPost({action:'summary_save', year:$('#fYear').val(), quarter:$('#fQuarter').val(),
             analysis_text:$('#statAnalysis').val(), stat_date:$('#statDate').val()},
        function(r){ if(r.ok) csToast('已儲存'); });
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
    if(!c){ csToast('請先選擇客戶'); return; }
    var bad = ST.mon.filter(function(r){ return String(r.item_name||'').trim()===''; }).length;
    if(bad && !confirm('有 '+bad+' 列沒有填「調查項目」，儲存時會被略過。要繼續嗎？')) return;
    ajxPost({action:'monitor_save', year:$('#fYear').val(), quarter:$('#fQuarter').val(),
             customer_id:c.customer_id, customer_name:c.customer_name,
             monitor_date:$('#statDate').val(), items:JSON.stringify(ST.mon)},
        function(r){ if(r.ok){ csToast('已儲存'); loadMonitor(); } });
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
    if(which==='monitor' && !curCustomer()){ csToast('請先選擇客戶'); return; }
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
        var c=curCustomer(); if(!c){ csToast('請先選擇客戶'); return; }
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
/* ══════════ 問卷作業（2-SM-02-02）══════════ */
var SVY = { q:[], lv:[], cats:{}, targets:[], files:[], cand:[], pick:{}, cust:null };

$('#btnSurvey').on('click', function(){ svyOpen(); });
function svyOpen(pane){
    ajxGet({action:'survey_meta', year:$('#fYear').val(), quarter:$('#fQuarter').val()}, function(r){
        if(!r||!r.ok){ csToast('讀不到問卷資料：'+((r&&r.error)||'未知原因')); return; }
        SVY.q=r.questions||[]; SVY.lv=r.levels||[]; SVY.cats=r.cats||{};
        SVY.targets=r.targets||[]; SVY.files=r.files||[]; SVY.cand=r.ship_stats||[];
        SVY.pick={};
        SVY.targets.forEach(function(t){ SVY.pick[t.customer_id]={customer_id:t.customer_id,customer_name:t.customer_name,pick_reason:t.pick_reason||'manual'}; });
        svyRenderCand(); svyRenderPick(); svyRenderPrint(); svyRenderFiles();
        svyTab(pane||'target');
        openMask('svyMask');
    });
}
function svyTab(name){
    $('.svy-tab').removeClass('active').filter('[data-svy="'+name+'"]').addClass('active');
    $('.svy-pane').hide().filter('[data-pane="'+name+'"]').show();
}
$(document).on('click','.svy-tab',function(){ svyTab($(this).data('svy')); });

/* 候選客戶＝本期間真的有出貨的（含出貨次數與金額，隨機篩選的條件就是這兩個） */
function svyKey(c){ return c.id||('#'+String(c.name||'').substr(0,18)); }
function svyRenderCand(){
    var kw=String($('#svyCandKw').val()||'').trim().toLowerCase();
    var list=SVY.cand.filter(function(c){
        if(!kw) return true;
        return (c.name+' '+(c.id||'')).toLowerCase().indexOf(kw)>=0;
    });
    var h=list.map(function(c){
        var k=svyKey(c);
        return '<div class="svy-row"><label style="margin:0;font-weight:normal;display:flex;gap:6px;align-items:center;width:100%;">'
             + '<input type="checkbox" class="svy-cand" data-k="'+esc(k)+'"'+(SVY.pick[k]?' checked':'')+'>'
             + '<b>'+esc(c.name)+'</b><span class="muted-help">'+esc(c.id||'（未綁定客戶ID）')+'</span>'
             + '<span style="margin-left:auto;" class="muted-help">出貨 '+c.times+' 次　'+Math.round(c.amount).toLocaleString()+' 元</span>'
             + '</label></div>';
    }).join('');
    $('#svyCandList').html(h||'<div class="svy-row muted-help">沒有符合的客戶</div>');
    $('#svyCandCnt').text('（'+list.length+' / '+SVY.cand.length+' 家）');
}
$(document).on('input','#svyCandKw',svyRenderCand);
$(document).on('change','.svy-cand',function(){
    var k=$(this).data('k'), c=SVY.cand.filter(function(x){ return svyKey(x)===k; })[0];
    if(!c) return;
    if(this.checked) SVY.pick[k]={customer_id:c.id||'',customer_name:c.name,pick_reason:'manual'};
    else delete SVY.pick[k];
    svyRenderPick(); svyRenderPrint();
});
function svyRenderPick(){
    var ks=Object.keys(SVY.pick);
    var h=ks.map(function(k){
        var p=SVY.pick[k];
        return '<div class="svy-row"><b>'+esc(p.customer_name)+'</b>'
             + '<span class="muted-help">'+esc(p.customer_id||'')+'</span>'
             + (p.pick_reason&&p.pick_reason.indexOf('random')===0?'<span class="svy-tag">隨機</span>':'')
             + '<button class="btn btn-xs btn-default svy-unpick" data-k="'+esc(k)+'" style="margin-left:auto;">×</button></div>';
    }).join('');
    $('#svyPickList').html(h||'<div class="svy-row muted-help">還沒有挑任何客戶</div>');
    $('#svyPickCnt').text('（'+ks.length+' 家）');
}
$(document).on('click','.svy-unpick',function(){
    delete SVY.pick[$(this).data('k')];
    svyRenderPick(); svyRenderPrint();
    $('.svy-cand[data-k="'+$(this).data('k').replace(/"/g,'\\"')+'"]').prop('checked',false);
});
$('#btnSvyClear').on('click', function(){ SVY.pick={}; svyRenderCand(); svyRenderPick(); svyRenderPrint(); });

/* 隨機篩選：先依條件縮出母體（出貨次數 ≥ N 或 金額前 N 大），再從裡面隨機抽 N 家。
   抽出來的是「加進名單」不是「取代名單」，已經挑好的不會被洗掉。 */
$('#btnSvyRnd').on('click', function(){
    var mode=$('#svyRndMode').val(), arg=parseInt($('#svyRndArg').val()||0)||0, n=parseInt($('#svyRndN').val()||0)||0;
    if(n<1){ csToast('請填要抽幾家'); return; }
    var pool = mode==='times' ? SVY.cand.filter(function(c){ return c.times>=arg; })
                              : SVY.cand.slice(0, Math.max(1,arg));   // cand 已依金額由大到小
    if(!pool.length){ csToast('依這個條件找不到任何客戶'); return; }
    var rest=pool.filter(function(c){ return !SVY.pick[svyKey(c)]; });
    for(var i=rest.length-1;i>0;i--){ var j=Math.floor(Math.random()*(i+1)); var t=rest[i]; rest[i]=rest[j]; rest[j]=t; }
    var take=rest.slice(0,n);
    take.forEach(function(c){ SVY.pick[svyKey(c)]={customer_id:c.id||'',customer_name:c.name,
        pick_reason: mode==='times'?'random_ship_times':'random_top_amount'}; });
    $('#svyRndHint').text('母體 '+pool.length+' 家，這次加入 '+take.length+' 家'
        + (take.length<n?'（母體裡沒挑過的只剩這些）':''));
    svyRenderCand(); svyRenderPick(); svyRenderPrint();
});
$('#btnSvyTargetSave').on('click', function(){
    var items=Object.keys(SVY.pick).map(function(k){ return SVY.pick[k]; });
    ajxPost({action:'target_save', year:$('#fYear').val(), quarter:$('#fQuarter').val(),
             items:JSON.stringify(items)}, function(r){
        if(!r||!r.ok){ csToast('儲存失敗：'+((r&&r.error)||'未知原因')); return; }
        csReloadStat('已儲存名單（'+r.saved+' 家）');
    });
});

/* ② 產生問卷 */
function svyRenderPrint(){
    var ks=Object.keys(SVY.pick);
    var h=ks.map(function(k){
        var p=SVY.pick[k];
        return '<div class="svy-row"><label style="margin:0;font-weight:normal;display:flex;gap:6px;align-items:center;width:100%;">'
             + '<input type="checkbox" class="svy-prn" data-k="'+esc(k)+'" checked>'
             + '<b>'+esc(p.customer_name)+'</b><span class="muted-help">'+esc(p.customer_id||'（未綁定客戶ID，電話傳真會留白）')+'</span>'
             + '</label></div>';
    }).join('');
    $('#svyPrnList').html(h||'<div class="svy-row muted-help">名單是空的，請先到「① 受調查名單」挑客戶</div>');
    $('#svyPrnCnt').text('（'+ks.length+' 家）');
}
$('#svyPrnAll').on('change', function(){ $('.svy-prn').prop('checked', this.checked); });
$('#btnSvyPrint').on('click', function(){
    var ks=$('.svy-prn:checked').map(function(){ return $(this).data('k'); }).get();
    if(!ks.length){ csToast('請先勾選要印的客戶'); return; }
    if(!CUSTMASTER){
        ajxGet({action:'cust_master'}, function(r){ if(r&&r.ok){ CUSTMASTER=r.rows||[]; doSurveyPrint(ks); } });
    } else doSurveyPrint(ks);
});

/* ③ 回收問卷附件 */
function svyRenderFiles(){
    var opts='<option value="">— 未指定 —</option>'
        + Object.keys(SVY.pick).map(function(k){
            var p=SVY.pick[k];
            return '<option value="'+esc(k)+'">'+esc(p.customer_name)+'</option>';
          }).join('');
    var h=SVY.files.map(function(f){
        var sel=opts.replace('value="'+esc(f.customer_id)+'"','value="'+esc(f.customer_id)+'" selected');
        return '<div class="svy-row">'
             + '<a href="javascript:;" class="svy-fview" data-id="'+f.id+'"><i class="fa fa-file-o"></i> '+esc(f.orig_name)+'</a>'
             + '<span class="muted-help">'+Math.round(f.size/1024)+' KB　'+esc(dispDate(f.at))+'　'+esc(f.by)+'</span>'
             + '<span style="margin-left:auto;display:flex;gap:6px;align-items:center;">'
             + '<select class="form-control input-sm svy-fassign" data-id="'+f.id+'" style="width:190px;">'+sel+'</select>'
             + '<button class="btn btn-xs btn-default svy-fdel" data-id="'+f.id+'">刪除</button></span></div>';
    }).join('');
    $('#svyFileList').html(h||'<div class="svy-row muted-help">還沒有上傳任何問卷</div>');
}
$('#btnSvyUpload').on('click', function(){
    var inp=document.getElementById('svyFiles');
    if(!inp.files||!inp.files.length){ csToast('請先選擇檔案'); return; }
    var fd=new FormData();
    fd.append('action','survey_upload'); fd.append('csrf',CSRF);
    fd.append('year',$('#fYear').val()); fd.append('quarter',$('#fQuarter').val());
    for(var i=0;i<inp.files.length;i++) fd.append('files[]', inp.files[i]);
    var $b=$(this).prop('disabled',true).text('上傳中…');
    $.ajax({url:API, type:'POST', data:fd, processData:false, contentType:false, dataType:'json'})
     .done(function(r){
        if(!r||!r.ok){ csToast('上傳失敗：'+((r&&r.error)||'未知原因')); return; }
        inp.value='';
        csToast('已上傳 '+(r.uploaded||[]).length+' 個檔案'+((r.skipped||[]).length?('，'+r.skipped.length+' 個略過：'+r.skipped.join('、')):''));
        svyRefreshFiles();
     })
     .fail(function(x){ csToast('上傳失敗：'+((x.responseJSON&&x.responseJSON.error)||x.status)); })
     .always(function(){ $b.prop('disabled',false).html('<i class="fa fa-upload"></i> 上傳'); });
});
function svyRefreshFiles(){
    ajxGet({action:'survey_meta', year:$('#fYear').val(), quarter:$('#fQuarter').val()}, function(r){
        if(!r||!r.ok) return;
        SVY.files=r.files||[]; svyRenderFiles(); csReloadStat();
    });
}
$(document).on('change','.svy-fassign',function(){
    var id=$(this).data('id'), k=$(this).val(), p=k?SVY.pick[k]:null;
    ajxPost({action:'survey_file_assign', year:$('#fYear').val(), quarter:$('#fQuarter').val(), id:id,
             customer_id:(p?p.customer_id:''), customer_name:(p?p.customer_name:'')}, function(r){
        if(!r||!r.ok){ csToast('指定失敗：'+((r&&r.error)||'未知原因')); return; }
        csToast(p?('已指定給 '+p.customer_name):'已取消指定');
        svyRefreshFiles();
    });
});
$(document).on('click','.svy-fdel',function(){
    if(!confirm('要刪除這個問卷附件嗎？（檔案會一起刪掉，無法復原）')) return;
    ajxPost({action:'survey_file_delete', id:$(this).data('id')}, function(r){
        if(!r||!r.ok){ csToast('刪除失敗：'+((r&&r.error)||'未知原因')); return; }
        csToast('已刪除'); svyRefreshFiles();
    });
});
$(document).on('click','.svy-fview',function(){
    window.open(API+'?action=survey_file_get&id='+$(this).data('id'), '_blank');
});
/* 表格上的「附件 N」＝直接開問卷作業的附件分頁 */
$(document).on('click','.svy-tag.file',function(){ svyOpen('file'); });

/* ══════════ 填問卷結果 ══════════ */
$(document).on('click','.svy-tag.act',function(){
    var r=ST.rows[$(this).data('i')]; if(!r) return;
    qOpen(r);
});
function qOpen(row){
    SVY.cust=row;
    $('#qCustName').text(row.customer_name);
    var need = !SVY.q.length;
    var go = function(){
        ajxGet({action:'survey_get', year:$('#fYear').val(), quarter:$('#fQuarter').val(),
                customer_id:row.customer_id||'', customer_name:row.customer_name}, function(r){
            if(!r||!r.ok){ csToast('讀不到問卷：'+((r&&r.error)||'未知原因')); return; }
            var s=r.survey||{}, ans=r.answers||{};
            $('input[name=qMode]').prop('checked',false).filter('[value="'+(s.mode||'item')+'"]').prop('checked',true);
            qRenderItems(ans); qRenderDirect(row);
            $('#qTotal').val(s.total_score===null||s.total_score===undefined?'':s.total_score);
            $('#qResp').val(s.respondent||''); $('#qRespTitle').val(s.respondent_title||'');
            $('#qDate').val((s.reply_date||'').substr(0,10)); $('#qComment').val(s.comment_text||'');
            $('#qErr').hide().empty();
            qModePane(); qCalc();
            openMask('qMask');
        });
    };
    if(!need) return go();
    ajxGet({action:'survey_meta', year:$('#fYear').val(), quarter:$('#fQuarter').val()}, function(r){
        if(!r||!r.ok){ csToast('讀不到問卷題目'); return; }
        SVY.q=r.questions||[]; SVY.lv=r.levels||[]; SVY.cats=r.cats||{};
        SVY.targets=r.targets||[]; SVY.files=r.files||[]; SVY.cand=r.ship_stats||[];
        go();
    });
}
function qRenderItems(ans){
    var CATNAME=SVY.cats, lastCat='', h='<table class="q-tb"><thead><tr><th style="width:52%;">評價主題與內容</th>'
        + SVY.lv.map(function(l){ return '<th style="width:9%;">'+esc(l.label)+'<div class="muted-help">'+l.score+' 分</div></th>'; }).join('')
        + '</tr></thead><tbody>';
    var order=['quality','delivery','service','price','tech'], num={};
    order.forEach(function(c){ num[c]=[]; });
    SVY.q.forEach(function(q){ if(num[q.cat]) num[q.cat].push(q); });
    order.forEach(function(c){
        if(!num[c].length) return;
        h+='<tr class="q-cat"><td colspan="'+(1+SVY.lv.length)+'">'+esc(CATNAME[c]||c)
         + '（本項分數＝底下 '+num[c].length+' 題的平均）</td></tr>';
        num[c].forEach(function(q){
            h+='<tr><td>'+q.no+'. '+esc(q.text)+'</td>'
             + SVY.lv.map(function(l,li){
                   var on=(ans && String(ans[q.no])===String(li));
                   return '<td class="qc"><input type="radio" name="q'+q.no+'" value="'+li+'"'+(on?' checked':'')+'></td>';
               }).join('') + '</tr>';
        });
    });
    h+='</tbody></table><div class="muted-help" style="margin-top:4px;">沒有勾的題目不列入平均（客戶漏答時不會被當成 0 分）。</div>';
    $('#qItemPane').html(h);
}
function qRenderDirect(row){
    var F=[['score_quality','品質'],['score_delivery','交期'],['score_tech','技術'],['score_service','服務'],['score_price','價格']];
    $('#qDirectPane').html('<div class="cs-bar">'+F.map(function(f){
        var v=row&&row[f[0]]!==null&&row[f[0]]!==undefined?row[f[0]]:'';
        return '<label style="margin:0;">'+f[1]+'</label>'
             + '<input type="number" class="form-control input-sm q-dir" data-k="'+f[0]+'" style="width:70px;" '
             + 'min="0" max="10" step="0.1" value="'+v+'">';
    }).join('')+'</div><div class="muted-help">留白＝尚未填（不是 0 分）。</div>');
}
function qModePane(){
    var m=$('input[name=qMode]:checked').val();
    $('#qItemPane').toggle(m==='item');
    $('#qDirectPane').toggle(m==='direct');
    $('#qTotalPane').toggle(m==='total');
}
$(document).on('change','input[name=qMode]',function(){ qModePane(); qCalc(); });
$(document).on('change','#qItemPane input[type=radio], .q-dir, #qTotal', qCalc);
/* 換算出來的分數即時顯示：填的人當下就看得到會寫進統計表的是多少（不必存了才知道） */
function qCalc(){
    var m=$('input[name=qMode]:checked').val(), sc={};
    var CAT={quality:'品質',delivery:'交期',tech:'技術',service:'服務',price:'價格'};
    if(m==='item'){
        var sum={},cnt={};
        SVY.q.forEach(function(q){
            var v=$('input[name=q'+q.no+']:checked').val();
            if(v===undefined) return;
            var s=(SVY.lv[parseInt(v)]||{}).score; if(s===undefined) return;
            sum[q.cat]=(sum[q.cat]||0)+parseFloat(s); cnt[q.cat]=(cnt[q.cat]||0)+1;
        });
        Object.keys(CAT).forEach(function(c){ if(cnt[c]) sc[c]=Math.round(sum[c]/cnt[c]*10)/10; });
    } else if(m==='total'){
        var t=parseFloat($('#qTotal').val());
        if(!isNaN(t)) Object.keys(CAT).forEach(function(c){ sc[c]=Math.round(Math.max(0,Math.min(100,t))/10*10)/10; });
    } else {
        $('.q-dir').each(function(){
            var v=$(this).val(); if(v==='') return;
            sc[$(this).data('k').replace('score_','')]=Math.round(Math.max(0,Math.min(10,parseFloat(v)))*10)/10;
        });
    }
    var ks=Object.keys(CAT).filter(function(c){ return sc[c]!==undefined; });
    var avg=ks.length?Math.round(ks.reduce(function(a,c){ return a+sc[c]; },0)/ks.length*10)/10:null;
    $('#qCalc').html('會寫進統計表的分數：'
        + Object.keys(CAT).map(function(c){ return CAT[c]+' <b>'+(sc[c]===undefined?'—':sc[c])+'</b>'; }).join('　')
        + '　→　平均 <b>'+(avg===null?'—':avg)+'</b>'
        + '<div class="muted-help">平均＝五個大項的平均（大項自己先算完該項題目的平均），只平均有填的項目。</div>');
}
$('#btnQSave').on('click', function(){
    var row=SVY.cust; if(!row) return;
    var m=$('input[name=qMode]:checked').val(), p={
        action:'survey_save', year:$('#fYear').val(), quarter:$('#fQuarter').val(),
        customer_id:row.customer_id||'', customer_name:row.customer_name, mode:m,
        respondent:$('#qResp').val(), respondent_title:$('#qRespTitle').val(),
        reply_date:$('#qDate').val(), comment_text:$('#qComment').val()
    };
    if(m==='item'){
        var ans={};
        SVY.q.forEach(function(q){
            var v=$('input[name=q'+q.no+']:checked').val();
            if(v!==undefined) ans[q.no]=parseInt(v);
        });
        if(!Object.keys(ans).length){ $('#qErr').text('至少要勾一題，或改用「直接填五項分數」').show(); return; }
        p.answers=JSON.stringify(ans);
    } else if(m==='total'){
        if($('#qTotal').val()===''){ $('#qErr').text('請填總分').show(); return; }
        p.total_score=$('#qTotal').val();
    } else {
        var any=false;
        $('.q-dir').each(function(){ p[$(this).data('k')]=$(this).val(); if($(this).val()!=='') any=true; });
        if(!any){ $('#qErr').text('至少要填一項分數').show(); return; }
    }
    $('#qErr').hide().empty();
    ajxPost(p, function(r){
        if(!r||!r.ok){ $('#qErr').text('儲存失敗：'+((r&&r.error)||'未知原因')).show(); return; }
        closeMask('qMask');
        csReloadStat('已存入 '+row.customer_name+' 的問卷結果');
    }, function(msg){ $('#qErr').text('儲存失敗：'+msg).show(); });
});

/* ── 產生問卷（版面照紙本 2-SM-02-02，A4 直式、一家一頁）──
   與統計表的列印分開寫一份：那邊是 A4 橫式報表，這裡是要寄給客戶填的表單。 */
function doSurveyPrint(keys){
    ajxGet({action:'print_meta', which:'survey', year:$('#fYear').val(), quarter:$('#fQuarter').val()}, function(m){
        if(!m||!m.ok){ csToast('讀不到列印設定'); return; }
        var own=m.own||{}, asTxt=String(m.doc_no_print||'').replace(/['\\]/g,'');
        var lvHead=SVY.lv.map(function(l){ return '<th>'+esc(l.label)+'</th>'; }).join('');
        var order=['quality','delivery','service','price','tech'], CN={quality:'一、 品質',delivery:'二、 交期',service:'三、 服務',price:'四、 價格',tech:'五、 技術'};
        var pages=keys.map(function(k,idx){
            var p=SVY.pick[k]||{customer_name:k,customer_id:''};
            var c=(CUSTMASTER||[]).filter(function(x){ return x.id===p.customer_id; })[0]||{};
            var rows='';
            order.forEach(function(cat){
                var qs=SVY.q.filter(function(q){ return q.cat===cat; });
                if(!qs.length) return;
                rows+='<tr class="cat"><td colspan="'+(1+SVY.lv.length)+'">'+CN[cat]+'</td></tr>';
                qs.forEach(function(q){
                    rows+='<tr><td class="tl">'+q.no+'.'+esc(q.text)+'</td>'
                        + SVY.lv.map(function(){ return '<td></td>'; }).join('')+'</tr>';
                });
            });
            return '<div class="q-page"'+(idx<keys.length-1?' style="page-break-after:always;"':'')+'>'
                 + '<div class="q-title">客戶滿意度調查問卷表</div>'
                 + '<div class="q-date">日期：　　年　　月　　日</div>'
                 + '<div class="q-to"><b>'+esc(p.customer_name)+'</b>　先生/小姐鈞鑑：</div>'
                 + '<div class="q-body">我們為提供給 貴公司更佳之服務與附加價值，懇切期待 貴公司惠賜寶貴意見與感想，'
                 + '讓我們能不斷的持續改善；帶給 貴公司更加的滿意與效益，我們精心設計了下列問卷請您務必耐心做答並'
                 + '傳真至 '+esc(own.customer_fax||'')+' 業務部收。我們將謹慎處理每一收回問卷並以滿足客戶需求努力，再次感謝您的熱忱與指教。</div>'
                 + '<div class="q-sign">敬祝　商　祺　　　　<b>'+esc(m.company||'')+'</b>　敬啟'
                 + '<div class="q-tel">歡迎使用服務電話：'+esc(own.customer_tel||'')+'</div></div>'
                 + '<div class="q-note">● 請以打勾「ˇ」或任何可識別之方式表達您對本公司的看法：（服務人員兩人以上請以姓名區分）</div>'
                 + '<table class="q-form"><thead><tr><th class="tl">評價主題與內容</th>'+lvHead+'</tr></thead><tbody>'+rows+'</tbody></table>'
                 + '<div class="q-note">● 若您對我們所提供的產品與服務有不滿意或特別建言、抱怨事項，請簡要敘述實情，以便我們進一步處理改善，'
                 + '我們將於收件一星期內主動與您聯繫說明處理結果，再次謝謝您：</div>'
                 + '<div class="q-free"></div>'
                 + '<div class="q-note">● 為方便聯絡與意見交換煩請確認：</div>'
                 + '<table class="q-foot"><tr><td>客戶名稱：<b>'+esc(p.customer_name)+'</b></td>'
                 + '<td>電話：'+esc(c.tel||'')+'</td><td>傳真：'+esc(c.fax||'')+'</td></tr>'
                 + '<tr><td>問卷填寫者：</td><td>職稱：</td><td>日期：　　年　　月　　日</td></tr></table>'
                 + '</div>';
        }).join('');
        surveyPrintWindow('客戶滿意度調查問卷', pages, asTxt, keys.length);
    });
}
function surveyPrintWindow(title, body, asTxt, n){
    try{ if(window.EGPrintLog) EGPrintLog.record({source:'cust_satis', doc_name:title+'（'+n+' 家）', doc_kind:'form'}); }catch(e){}
    var w=window.open('','_blank');
    if(!w){ alert('請允許彈出視窗以列印'); return; }
    var css='body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;margin:0;color:#222;font-size:12px;'
        +'-webkit-print-color-adjust:exact;print-color-adjust:exact;}*{box-sizing:border-box;}'
        +'.q-page{padding:0;}'
        +'.q-title{font-size:19px;font-weight:bold;text-align:center;letter-spacing:4px;margin-bottom:2px;}'
        +'.q-date{text-align:right;font-size:11px;margin-bottom:6px;}'
        +'.q-to{margin:4px 0;}.q-body{line-height:1.9;text-align:justify;}'
        +'.q-sign{margin:6px 0;text-align:right;}.q-tel{font-size:11px;color:#444;}'
        +'.q-note{margin:6px 0 3px;line-height:1.7;}'
        +'table{width:100%;border-collapse:collapse;table-layout:fixed;}'
        +'table.q-form th,table.q-form td{border:1px solid #333;padding:4px 5px;text-align:center;font-size:11.5px;}'
        +'table.q-form th{background:#f3ead6;}table.q-form th.tl,table.q-form td.tl{text-align:left;}'
        +'table.q-form thead th:first-child{width:46%;}'
        +'table.q-form tr.cat td{background:#faf4ea;text-align:left;font-weight:bold;}'
        +'table.q-form tr{break-inside:avoid;page-break-inside:avoid;}'
        +'.q-free{border:1px solid #333;height:26mm;}'
        +'table.q-foot td{border:1px solid #333;padding:5px 6px;font-size:11.5px;}'
        +'@page{size:A4 portrait;margin:14mm 14mm 16mm;'
        +(asTxt?" @bottom-right{ content:'"+asTxt+"'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; }":'')
        +'}';
    w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>'+esc(title)+'</title>'
        +'<style>'+css+'</style></head><body>'+body
        +'<scr'+'ipt>window.onload=function(){setTimeout(function(){window.print();},250);};</scr'+'ipt></body></html>');
    w.document.close(); w.focus();
}

/* 清除系統建議分（品質／交期）——那兩項應該由客戶問卷來，不是系統推估 */
$('#btnClearSuggest').on('click', function(){
    if(!confirm('要把本期間「系統帶入的」品質分／交期分清空嗎？\n\n'
              + '（那兩項應該填客戶問卷上的分數。已經有回收問卷的客戶不會被清；技術／服務／價格與備註也不會動到）')) return;
    ajxPost({action:'score_clear_suggest', year:$('#fYear').val(), quarter:$('#fQuarter').val()}, function(r){
        if(!r||!r.ok){ csToast('清除失敗：'+((r&&r.error)||'未知原因')); return; }
        csReloadStat('已清除 '+r.cleared+' 家的品質／交期分'+(r.kept?('，保留 '+r.kept+' 家有回收問卷的'):''));
    }, function(m){ csToast('清除失敗：'+m); });
});

/* ══════════ 設定 ══════════ */
/* 事件委派（不綁死在載入當下那顆按鈕上），且任何一條失敗路徑都要講出來——
   原本 `if(!r.ok) return;` 是靜默結束，使用者看到的就是「按了完全沒反應」，連原因都查不到。 */
$(document).on('click', '#btnSetting', function(){
    ajxGet({action:'setting_get'}, function(r){
        if(!r||!r.ok){ alert('讀不到設定：'+((r&&r.error)||'未知原因')+'\n請重新整理頁面後再試一次。'); return; }
        ST.set=r;
        renderDocLabel('stat'); renderDocLabel('monitor'); renderDocLabel('survey');
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
/* 三份文件共用同一套綁定 UI：stat 統計資料表／monitor 監控表／survey 調查問卷 */
var DOCSPEC = {stat:  {doc:'stat_doc',   id:'stat_doc_id',   el:'#setStatLabel', name:'客戶滿意度統計資料表'},
               monitor:{doc:'monitor_doc',id:'monitor_doc_id',el:'#setMonLabel',  name:'客戶滿意度監控表'},
               survey:{doc:'survey_doc', id:'survey_doc_id', el:'#setSvyLabel',  name:'客戶滿意度調查問卷'}};
function renderDocLabel(which){
    var sp=DOCSPEC[which]; if(!sp) return;
    var d = ST.set[sp.doc];
    var txt=(window.EGAsDoc&&EGAsDoc.label)?EGAsDoc.label(d):(d?d.doc_no:'尚未綁定');
    $(sp.el).text(txt);
}
function csPickDoc(which){
    var sp=DOCSPEC[which]; if(!sp) return;
    EGAsDoc.open({docs:ST.set.as_docs||[], current:parseInt(ST.set[sp.id]||0)||0,
        title:sp.name+'－AS 文件編號綁定',
        onSave:function(id,doc){
            ST.set[sp.id]=parseInt(id)||0; ST.set[sp.doc]=doc||null;
            renderDocLabel(which);
            ajxPost({action:'asdoc_save', which:which, doc_id:parseInt(id)||0}, function(){});
        }});
}
function csClearDoc(which){
    var sp=DOCSPEC[which]; if(!sp) return;
    ST.set[sp.id]=0; ST.set[sp.doc]=null;
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
        function(r){ if(r.ok){ closeMask('setMask'); csReloadStat('已儲存設定'); } });
});
<?php endif; ?>

<?php if ($perms['canView']): ?>
loadStat();
<?php endif; ?>
</script>
</body>
</html>
