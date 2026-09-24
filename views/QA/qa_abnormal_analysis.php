<?php
/**
 * qa_abnormal_analysis.php — 品質異常分析
 * 建立：2026-09-24
 *
 * 來源＝品質異常處理單（qa_abnormal_order，見 qa_abnormal_list.php／qa_abnormal_form.php），
 * 本頁**只讀不寫**：不會新增/修改任何一張異常單，改分析口徑或設定不影響單張處理頁。
 * 期間切法（月／季／半年／整年＋去年同期／上一期）沿用 order_analysis_lib.php 的共用函式。
 * 權限：qab_analysis_view（檢視）／qab_analysis_admin（設定），module='qa_abnormal'，
 * 自動出現在既有「品質異常處理單」角色設定區塊，異常單管理員與系統管理員自動涵蓋。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/QA/qa_abnormal_analysis.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/qa_abnormal_analysis_lib.php';

$db = (new DBConnection())->getPDO();
qaa_ensure($db);
$uid   = (int)($_SESSION['id'] ?? 0);
$perms = qaa_perms($db, $uid);
if (empty($_SESSION['qaa_csrf'])) $_SESSION['qaa_csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['qaa_csrf'];
$roleLabel = $perms['isAdmin'] ? '系統管理者' : ($perms['canAAdmin'] ? '分析設定管理員'
            : ($perms['canAView'] ? '分析檢視' : '無權限'));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>品質異常分析</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
<style>
#sidebar-menu { visibility: hidden; }
:root{ --ink:#4A3524; --cream:#FCF7F0; --sand:#F7E0BD; --amber:#F0A24B; --amber-d:#C77C1A;
       --coral:#DD5138; --line:#E4D3BC; --muted:#a08a6f; --brown:#8a5a2b; }
body { background:#F6F1EA; }
.right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
.page-title h3 { color:var(--ink); margin:0; display:flex; align-items:center; gap:10px; flex-wrap:wrap; font-size:22px; }
.page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid var(--amber-d);
                 border-radius:15px; background:#fff; color:var(--amber-d); }
.page-help-btn:hover { background:var(--amber-d); color:#fff; }
@media print { .page-help-btn, .qaa-bar { display:none !important; } }
.role-tag { font-size:12px; background:#EFE3CF; color:#6B4423; border-radius:10px; padding:2px 10px; font-weight:normal; }
.warm-panel { background:#fff; border:1px solid var(--line); border-radius:8px; padding:12px 14px; margin-bottom:12px; }
.btn-warm { background:var(--amber); border:1px solid var(--amber-d); color:var(--ink); font-weight:bold; }
.btn-warm:hover,.btn-warm:focus { background:var(--amber-d); color:#fff; }
.btn-warm-o { background:#fff; border:1px solid var(--amber-d); color:var(--amber-d); }
.btn-warm-o:hover { background:var(--sand); color:var(--ink); }
.qaa-bar { display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; }
.qaa-bar label { display:block; font-size:12px; color:#8a7560; margin-bottom:2px; font-weight:600; }
.qaa-bar select, .qaa-bar input { border:1px solid var(--line); border-radius:4px; padding:3px 6px; font-size:13px; height:30px; }
.oa-note { background:#faf6f0; border:1px solid var(--line); border-left:4px solid var(--amber);
           border-radius:6px; padding:8px 12px; font-size:12px; color:#6B4423; line-height:1.8; margin-bottom:12px; }
.oa-note b { color:var(--coral); }
.oa-warn { background:#FDF2EE; border-left-color:var(--coral); }
.kpi-row { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px; }
.kpi-card { flex:1 1 150px; min-width:150px; background:#fff; border:1px solid var(--line);
            border-top:3px solid var(--amber); border-radius:8px; padding:10px 12px; }
.kpi-card .k-lab { font-size:12px; color:var(--muted); }
.kpi-card .k-val { font-size:24px; font-weight:700; color:var(--ink); line-height:1.2; word-break:break-all; }
.kpi-card .k-sub { font-size:11px; color:var(--muted); margin-top:2px; }
.kpi-card.k-warn { border-top-color:var(--coral); }
.up   { color:#2E7D32; font-weight:700; }
.down { color:var(--coral); font-weight:700; }
.flat { color:var(--muted); }
.sec { background:#fff; border:1px solid var(--line); border-radius:8px; padding:12px 14px; margin-bottom:14px; }
.sec h4 { margin:0 0 4px; font-size:16px; color:var(--ink); font-weight:700;
          display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.sec h4 .hint { font-size:11px; color:var(--muted); font-weight:normal; }
.sec-tools { margin-left:auto; display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
.chart-box { width:100%; height:300px; }
.chart-box.tall { height:360px; }
.two-col { display:flex; gap:14px; flex-wrap:wrap; }
.two-col > div { flex:1 1 420px; min-width:320px; }
table.oa-t { width:100%; border-collapse:collapse; font-size:12px; table-layout:fixed; }
table.oa-t th, table.oa-t td { border:1px solid var(--line); padding:4px 6px; vertical-align:middle;
                               word-break:break-all; line-height:1.5; }
table.oa-t th { background:#faf6f0; color:#6B4423; font-weight:700; text-align:center; white-space:nowrap; }
table.oa-t td.n { text-align:right; font-variant-numeric:tabular-nums; }
table.oa-t tbody tr:nth-child(even) { background:#fdfbf8; }
.tbl-wrap { max-height:360px; overflow:auto; border:1px solid var(--line); border-radius:6px; }
.tbl-wrap table.oa-t th { position:sticky; top:0; z-index:2; }
.row-over { background:#FDF2EE !important; }
.badge-over { background:var(--coral); color:#fff; border-radius:9px; padding:1px 8px; font-size:10.5px; line-height:17px; display:inline-block; }
.badge-stage { background:var(--sand); color:#6B4423; border-radius:9px; padding:1px 8px; font-size:10.5px; line-height:17px; display:inline-block; }
.ord-link { color:#8a5a2b; border-bottom:1px dotted #8a5a2b; cursor:pointer; text-decoration:none; }
.ord-link:hover { color:var(--coral); border-bottom-color:var(--coral); }
.ins-list { display:flex; flex-direction:column; gap:6px; }
.ins { display:flex; gap:10px; align-items:flex-start; border:1px solid var(--line); border-left-width:4px;
       border-radius:6px; padding:7px 10px; background:#fffdfa; }
.ins .ic { font-size:15px; line-height:20px; width:18px; text-align:center; flex:0 0 18px; }
.ins .bd { flex:1 1 auto; min-width:0; }
.ins .tt { font-weight:700; color:var(--ink); font-size:13px; }
.ins .dt { font-size:12px; color:#6B4423; line-height:1.7; }
.ins-bad  { border-left-color:var(--coral); }  .ins-bad  .ic { color:var(--coral); }
.ins-warn { border-left-color:var(--amber); }  .ins-warn .ic { color:var(--amber-d); }
.ins-good { border-left-color:#4F8A4F; }       .ins-good .ic { color:#2E7D32; }
.ins-info { border-left-color:#B9A78C; }       .ins-info .ic { color:var(--muted); }
.m-mask { position:fixed; inset:0; background:rgba(74,53,36,.45); z-index:10300; display:none; }
.m-win  { background:#fff; border-radius:8px; width:640px; max-width:95vw; margin:5vh auto;
          box-shadow:0 8px 30px rgba(0,0,0,.3); display:flex; flex-direction:column; max-height:90vh; }
.m-win.wide { width:820px; }
.m-head { padding:10px 14px; border-bottom:1px solid var(--line); font-weight:700; color:var(--ink);
          display:flex; align-items:center; }
.m-head .x { margin-left:auto; cursor:pointer; color:var(--muted); }
.m-body { padding:14px; overflow:auto; }
.m-foot { padding:10px 14px; border-top:1px solid var(--line); text-align:right; }
.help-doc h4 { color:var(--amber-d); font-size:15px; margin:14px 0 6px; }
.help-doc li { margin-bottom:4px; line-height:1.7; }
.st-fgrid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:10px 14px; margin-bottom:10px; }
.st-fgrid label { display:block; font-size:12px; color:#8a7560; margin-bottom:3px; }
.st-fgrid input { width:100%; border:1px solid var(--line); border-radius:4px; padding:4px 6px; font-size:13px; }
.err-txt { color:var(--coral); font-size:12px; margin-top:6px; white-space:pre-line; }
</style>
</head>
<body class="nav-md">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">

        <div class="page-title">
            <h3><i class="fa fa-line-chart" style="color:var(--coral);"></i> 品質異常分析
                <span class="role-tag">目前身分：<?= htmlspecialchars($roleLabel) ?></span>
                <a href="qa_abnormal_list.php" class="btn btn-default btn-sm" style="margin-left:auto;"
                   title="回品質異常處理單"><i class="fa fa-exchange"></i> 品質異常處理單</a>
                <button id="btnPageHelp" class="page-help-btn"><i class="fa fa-question-circle"></i> 使用說明</button>
            </h3>
        </div>

        <?php if (!$perms['canAView']): ?>
        <div class="warm-panel">您沒有品質異常分析的檢視權限，請洽系統管理員於「品質異常處理單」角色設定開通 <code>qab_analysis_view</code>。</div>
        <?php else: ?>

        <div class="warm-panel">
            <div class="qaa-bar">
                <div><label>年度</label><select id="fYear"></select></div>
                <div><label>期間粒度</label>
                    <select id="fGran">
                        <option value="month">月</option>
                        <option value="quarter">季</option>
                        <option value="half">半年</option>
                        <option value="year">整年</option>
                    </select></div>
                <div><label>期別</label><select id="fIdx"></select></div>
                <div><label>比較基準</label>
                    <select id="fCmp"><option value="yoy">去年同期</option><option value="prev">上一期</option><option value="none">不比較</option></select></div>
                <div><label>開單來源</label>
                    <select id="fSource"><option value="">全部</option>
                        <option value="IR">客退(IR)</option><option value="QC">QC檢驗單</option><option value="BOM">製程中(製令)</option></select></div>
                <div style="flex:1;min-width:160px;"><label>關鍵字（料號／客戶／單號）</label><input type="text" id="fKw" style="width:100%;"></div>
                <button class="btn btn-warm btn-sm" id="btnReload"><i class="fa fa-search"></i> 查詢</button>
                <button class="btn btn-warm-o btn-sm" id="btnPrint"><i class="fa fa-print"></i> 列印報告</button>
                <?php if ($perms['canAAdmin']): ?>
                <button class="btn btn-warm-o btn-sm" id="btnCfg"><i class="fa fa-cog"></i> 設定</button>
                <?php endif; ?>
            </div>
        </div>

        <div id="noteBar"></div>
        <div id="alertBar"></div>

        <div class="kpi-row" id="kpiRow"></div>

        <div class="sec">
            <h4><i class="fa fa-magic"></i> 自動分析 <span class="hint">每一條都附具體數字</span></h4>
            <div class="ins-list" id="insightList"></div>
        </div>

        <div class="sec">
            <h4><i class="fa fa-area-chart"></i> 異常單趨勢 <span class="hint">筆數／不良數量（左軸）與 COPQ 金額（右軸）</span></h4>
            <div id="chTrend" class="chart-box tall"></div>
        </div>

        <div class="two-col">
            <div class="sec">
                <h4><i class="fa fa-bar-chart"></i> 柏拉圖：依料號 <span class="hint">Top 15，長條＋累積%</span></h4>
                <div id="chParetoPart" class="chart-box"></div>
            </div>
            <div class="sec">
                <h4><i class="fa fa-sitemap"></i> 柏拉圖：依異常原因分類 <span class="hint">一張單可能同時屬於多個分類</span></h4>
                <div id="chParetoCause" class="chart-box"></div>
                <div class="tbl-wrap" style="max-height:150px;margin-top:8px;">
                    <table class="oa-t"><thead><tr><th>原因分類（完整路徑）</th><th style="width:70px;">筆數</th></tr></thead>
                        <tbody id="tblCauseLeaf"></tbody></table>
                </div>
            </div>
        </div>

        <div class="two-col">
            <div class="sec">
                <h4><i class="fa fa-industry"></i> 柏拉圖：依發生源頭 <span class="hint">廠內製程站別／委外供應商</span></h4>
                <div id="chParetoSource" class="chart-box"></div>
            </div>
            <div class="sec">
                <h4><i class="fa fa-pie-chart"></i> 最終處置分布 <span class="hint">特採／報廢／重工／需矯正／待決策</span></h4>
                <div id="chDisposition" class="chart-box"></div>
            </div>
        </div>

        <div class="two-col">
            <div class="sec">
                <h4><i class="fa fa-inbox"></i> 開單來源分布</h4>
                <div id="chSourceType" class="chart-box"></div>
            </div>
            <div class="sec">
                <h4><i class="fa fa-money"></i> COPQ（不良品質成本）依最終處置</h4>
                <div id="chCopqDisp" class="chart-box"></div>
            </div>
        </div>

        <div class="sec">
            <h4><i class="fa fa-clock-o"></i> 時效監控 <span class="hint" id="agingHint">目前仍未結案的單，依卡關天數由高到低排列（不受上方期間篩選影響，永遠是現況）</span></h4>
            <div class="tbl-wrap">
                <table class="oa-t">
                    <thead><tr><th style="width:110px;">異常單號</th><th style="width:90px;">業務日期</th><th>客戶</th><th>料號</th>
                        <th style="width:110px;">目前狀態</th><th style="width:70px;">卡了幾天</th><th style="width:70px;">門檻</th><th style="width:60px;">操作</th></tr></thead>
                    <tbody id="tblAging"></tbody>
                </table>
            </div>
        </div>

        <div class="sec">
            <h4><i class="fa fa-repeat"></i> 重複發生警示 <span class="hint" id="recurHint">同料號＋同原因分類，在設定的月數窗口內累計達到門檻次數（只提示，不會擋下結案）</span></h4>
            <div class="tbl-wrap">
                <table class="oa-t">
                    <thead><tr><th>料號</th><th>客戶</th><th>原因分類</th><th style="width:70px;">發生次數</th><th>相關異常單（新到舊）</th></tr></thead>
                    <tbody id="tblRecur"></tbody>
                </table>
            </div>
        </div>

        <?php endif; ?>
    </div>
    <?php include '../partPage/footer.html' ?>
</div>
</div>

<!-- 使用說明 -->
<div class="m-mask" id="helpUseMask">
    <div class="m-win wide">
        <div class="m-head"><i class="fa fa-question-circle"></i> 使用說明－品質異常分析<span class="x" data-close="helpUseMask">&times;</span></div>
        <div class="m-body help-doc">
            <h4>這頁在做什麼</h4>
            <ul>
                <li>把「品質異常處理單」累積下來的資料自動彙整成柏拉圖、趨勢、處置分布、COPQ（不良品質成本）、時效監控與重複發生偵測，供品質會議直接使用。</li>
                <li><b>本頁只讀不寫</b>：不會新增或修改任何一張異常單；篩選、設定都不影響單張處理頁的內容。</li>
            </ul>
            <h4>篩選與期間</h4>
            <ul>
                <li>期間粒度可選<b>月／季／半年／整年</b>，「期別」隨粒度變化；比較基準可選<b>去年同期</b>或<b>上一期</b>，也可以不比較。</li>
                <li>KPI 卡片、自動分析、各柏拉圖與分布圖都<b>依這裡的期間篩選</b>計算；<b>時效監控與重複發生偵測是現況（不受期間篩選影響）</b>，因為它們問的是「現在有哪些單卡住了／重複了」。</li>
            </ul>
            <h4>各項分析的口徑</h4>
            <ul>
                <li><b>柏拉圖依料號／依原因分類／依發生源頭</b>：依選定期間內的異常單計數，長條由高到低排列；依原因分類可能加總大於單數，因為一張單可同時勾選多個原因。</li>
                <li><b>COPQ（不良品質成本）</b>：加總「扣款確認明細」裡已勾選採用的金額，是系統裡唯一已經人工確認過的不良成本數字。</li>
                <li><b>時效監控</b>：目前未結案的單依「建立時間到現在」的天數排序，超過設定門檻的標紅並顯示「逾期」徽章；門檻依目前卡在哪一關分別設定（待決策／等待單位回覆／待總經理裁示／扣款確認中／待品管確認說明），也有一個不分階段的整體門檻。</li>
                <li><b>重複發生警示</b>：同一支料號＋同一個異常原因分類，在設定的月數窗口內累計出現達到門檻次數就列出來，點單號可開啟該張異常單。<b>這裡只做提示，不會強制擋下結案或要求開矯正單</b>——是否連動到單張處理頁的結案流程，留給後續視需要再決定。</li>
                <li>統計只計「邏輯上的葉列」：一張異常單如果之後被拆分成好幾張子單，只算子單、不重複算原始母單。</li>
            </ul>
            <h4>設定</h4>
            <ul>
                <li>只有「分析設定管理員」看得到「設定」按鈕，可調整重複發生偵測的月數與門檻、各階段時效門檻、COPQ 期間金額提醒門檻。</li>
            </ul>
            <h4>列印</h4>
            <ul>
                <li>「列印報告」會依<b>目前畫面上選定的期間</b>（月／季／半年／整年任一種）產生 A4 橫式報告，含 KPI、自動分析、全部圖表與時效／重複發生清單，可直接留存或送交稽核。</li>
            </ul>
            <h4>權限</h4>
            <ul>
                <li><code>qab_analysis_view</code> 品質異常分析（檢視）：可看整份分析與列印報告。</li>
                <li><code>qab_analysis_admin</code> 品質異常分析（設定）：另外可調整重複發生／時效／COPQ 門檻設定。</li>
                <li>異常單管理員（<code>qab_admin</code>）與系統管理員自動擁有以上全部權限；角色指派請至「使用者權限設定」的「品質異常處理單」區塊。</li>
            </ul>
        </div>
        <div class="m-foot"><button class="btn btn-default btn-sm" data-close="helpUseMask">關閉</button></div>
    </div>
</div>

<?php if ($perms['canAAdmin']): ?>
<!-- 設定 -->
<div class="m-mask" id="cfgMask">
    <div class="m-win">
        <div class="m-head"><i class="fa fa-cog"></i> 品質異常分析 設定<span class="x" data-close="cfgMask">&times;</span></div>
        <div class="m-body">
            <div style="font-weight:700;color:var(--ink);margin-bottom:4px;">重複發生偵測</div>
            <div class="st-fgrid">
                <div><label>往前抓幾個月</label><input type="number" id="s_recur_m" min="1" max="36"></div>
                <div><label>累計達到幾次算重複</label><input type="number" id="s_recur_n" min="2" max="20"></div>
            </div>
            <div style="font-weight:700;color:var(--ink);margin:10px 0 4px;">時效監控門檻（天）</div>
            <div class="st-fgrid">
                <div><label>待決策</label><input type="number" id="s_age_decide" min="1" max="180"></div>
                <div><label>等待單位回覆</label><input type="number" id="s_age_reply" min="1" max="180"></div>
                <div><label>待總經理裁示</label><input type="number" id="s_age_gm" min="1" max="180"></div>
                <div><label>扣款確認中</label><input type="number" id="s_age_deduct" min="1" max="180"></div>
                <div><label>待品管確認說明</label><input type="number" id="s_age_qcreview" min="1" max="180"></div>
                <div><label>整體未結案（不分階段）</label><input type="number" id="s_age_overall" min="1" max="365"></div>
            </div>
            <div style="font-weight:700;color:var(--ink);margin:10px 0 4px;">COPQ 提醒</div>
            <div class="st-fgrid">
                <div><label>單期金額超過提醒（元，留空＝不提醒）</label><input type="number" id="s_copq_alert" min="0"></div>
            </div>
            <div class="err-txt" id="cfgErr"></div>
        </div>
        <div class="m-foot">
            <button class="btn btn-default btn-sm" data-close="cfgMask">取消</button>
            <button class="btn btn-warm btn-sm" id="btnCfgSave"><i class="fa fa-save"></i> 儲存</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../code/highcharts.js"></script>
<script src="../../code/modules/exporting.js"></script>
<script src="../../resource/js/eg_date_fmt.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_print_log.js"></script>
<script>
var QAA_API = '../../src/store/QaAbnormalAnalysis_API.php';
var CSRF = <?= json_encode($CSRF) ?>;
var CAN_ADMIN = <?= $perms['canAAdmin'] ? 'true' : 'false' ?>;
var DATA = null, SETTINGS = null;

function esc(s){ return $('<div>').text(s==null?'':s).html(); }
function nf(n){ n=Number(n)||0; return n.toLocaleString('en-US'); }
function money(n){ return nf(Math.round(Number(n)||0)); }
function dispDate(s){ return (typeof egFmtDate==='function') ? egFmtDate(s) : (s||''); }
function pct(a,b){ b=Number(b)||0; if(!b) return '—'; return (Math.round((Number(a)||0)/b*1000)/10)+'%'; }
function openMask(id){ $('#'+id).show(); }
function closeMask(id){ $('#'+id).hide(); }
$(document).on('click','[data-close]', function(){ closeMask($(this).data('close')); });
$(document).on('click','.m-mask', function(e){ if(e.target===this) $(this).hide(); });
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });

/* ai-rules/10 暖色調色盤（同語意同色、跨頁一致；禁止隨機或 HSL 上色） */
var PAL = ['#DD5138','#F0A24B','#B06F27','#E8C07A','#8A5A2B','#D98A5F','#C9A227','#A34E2A','#EBD3A8','#7A4A34'];
var C_AMBER='#F0A24B', C_CORAL='#DD5138', C_BROWN='#8A5A2B', C_GREEN='#4F8A4F';

function deltaHtml(cur, prev, fmt){
  fmt = fmt || nf;
  if (prev === null || prev === undefined) return '';
  var p = Number(prev)||0, d = (Number(cur)||0) - p;
  var cls = d>0?'up':(d<0?'down':'flat');
  var sign = d>0?'+':'';
  var rate;
  if(!p) rate = '（基期為 0）';
  else if(Math.abs(d/p) > 10) rate = '（基期過小）';
  else rate = '（'+sign+Math.round(d/Math.abs(p)*1000)/10+'%）';
  return '<span class="'+cls+'">'+sign+fmt(d)+'</span> <span style="font-size:10px;color:#a08a6f;">'+rate+'</span>';
}

/* ── 年度／期別下拉 ─────────────────────────────────── */
var GRAN_IDX = {
  year:    [['1','整年']],
  half:    [['1','上半年'],['2','下半年']],
  quarter: [['1','第 1 季'],['2','第 2 季'],['3','第 3 季'],['4','第 4 季']],
  month:   null
};
function fillYears(){
  var now = new Date().getFullYear(), h='';
  for (var y = now; y >= now-6; y--) h += '<option value="'+y+'"'+(y===now?' selected':'')+'>'+y+'</option>';
  $('#fYear').html(h);
}
function fillIdx(keepIdx){
  var g = $('#fGran').val(), list = GRAN_IDX[g];
  if(!list){ list=[]; for(var m=1;m<=12;m++) list.push([String(m), m+' 月']); }
  var h=''; list.forEach(function(x){ h += '<option value="'+x[0]+'">'+x[1]+'</option>'; });
  $('#fIdx').html(h).prop('disabled', g==='year');
  var want = keepIdx || defaultIdx(g);
  if($('#fIdx option[value="'+want+'"]').length) $('#fIdx').val(want);
}
function defaultIdx(g){
  var y = parseInt($('#fYear').val(),10), now = new Date();
  if(y !== now.getFullYear()){ return g==='month'?'12':(g==='quarter'?'4':(g==='half'?'2':'1')); }
  var m = now.getMonth()+1;
  if(g==='month')   return String(m);
  if(g==='quarter') return String(Math.ceil(m/3));
  if(g==='half')    return m<=6?'1':'2';
  return '1';
}
fillYears();
$('#fGran, #fYear').on('change', function(){ fillIdx(); });
fillIdx();

/* ── 圖表共用 ───────────────────────────────────────── */
var CHARTS = {};
var BASE_CHART = {
  chart:{ backgroundColor:'#fff', style:{fontFamily:'"Microsoft JhengHei",sans-serif'}, spacing:[8,8,6,8] },
  title:{ text:null }, credits:{ enabled:false },
  xAxis:{ lineColor:'#E4D3BC', tickColor:'#E4D3BC', labels:{ style:{fontSize:'11px', color:'#6B4423'} } },
  legend:{ itemStyle:{fontSize:'11px', fontWeight:'400', color:'#6B4423'}, symbolRadius:3 }
};
function chart(id, o){
  try { if(CHARTS[id]) { CHARTS[id].destroy(); CHARTS[id]=null; } } catch(e){}
  CHARTS[id] = Highcharts.chart(id, $.extend(true, {}, BASE_CHART, o));
}
function sizeBox(id, px){ var e=document.getElementById(id); if(e) e.style.height = Math.round(px)+'px'; }

/* ── 主流程 ─────────────────────────────────────────── */
function load(){
  var req = {
    action:'analyze', year:$('#fYear').val(), gran:$('#fGran').val(), idx:$('#fIdx').val(),
    cmp:$('#fCmp').val(), source:$('#fSource').val(), kw:$('#fKw').val()
  };
  $('#noteBar').html('<div class="oa-note"><i class="fa fa-spinner fa-spin"></i> 計算中…</div>');
  $.get(QAA_API, req, function(r){
    if(!r || !r.ok){ $('#noteBar').html('<div class="oa-note oa-warn">'+esc((r&&r.error)||'載入失敗')+'</div>'); return; }
    DATA = r; SETTINGS = r.meta.settings;
    renderNote(); renderAlert(); renderKpi(); renderInsights();
    renderTrend(); renderParetoPart(); renderParetoCause();
    renderParetoSource(); renderDisposition(); renderSourceType(); renderCopqDisp();
    renderAging(); renderRecur();
  }, 'json').fail(function(x){
    $('#noteBar').html('<div class="oa-note oa-warn">載入失敗（HTTP '+x.status+'）'
      + (x.status===403?'：權限不足或連線憑證失效，請重新整理頁面':'') + '</div>');
  });
}
$('#btnReload').on('click', load);
$('#fSource, #fCmp').on('change', load);
$('#fKw').on('keydown', function(e){ if(e.which===13) load(); });

function renderNote(){
  var m = DATA.meta;
  var h = '<b>本期</b>：'+esc(m.period.label)+'（'+dispDate(m.period.start)+'～'+dispDate(m.period.end)+'）'
        + (m.cmp_period ? '　<b>比較基期</b>：'+esc(m.cmp_period.label)+'（'+dispDate(m.cmp_period.start)+'～'+dispDate(m.cmp_period.end)+'）' : '（未設定比較基期）')
        + '<br>統計只計「邏輯上的葉列」：異常單若之後被拆分成子單，只算子單、原始母單不重複計入。'
        + '　COPQ（不良品質成本）＝扣款確認明細裡已勾選採用的金額加總。';
  $('#noteBar').html('<div class="oa-note">'+h+'</div>');
}

function renderAlert(){
  var s = SETTINGS, k = DATA.kpi.cur;
  if (s.copq_alert_amount !== null && k.copq > s.copq_alert_amount) {
    $('#alertBar').html('<div class="oa-note oa-warn"><i class="fa fa-exclamation-triangle"></i> '
      + '本期 COPQ 金額 <b>'+money(k.copq)+'</b> 元，已超過設定的提醒門檻 '+money(s.copq_alert_amount)+' 元。</div>');
  } else { $('#alertBar').html(''); }
}

function kc(lab, val, sub, warn){
  return '<div class="kpi-card'+(warn?' k-warn':'')+'"><div class="k-lab">'+lab+'</div>'
       + '<div class="k-val">'+val+'</div><div class="k-sub">'+(sub||'')+'</div></div>';
}
function renderKpi(){
  var k = DATA.kpi.cur, c = DATA.kpi.cmp;
  var h = '';
  h += kc('異常單總數', nf(k.total), c ? '較基期 '+deltaHtml(k.total, c.total) : '');
  h += kc('已結案／未結案', nf(k.closed)+' / '+nf(k.open), '結案率 '+pct(k.closed, k.total));
  h += kc('不良總數量', nf(k.ng_qty), c ? '較基期 '+deltaHtml(k.ng_qty, c.ng_qty) : '');
  h += kc('報廢件數', nf(k.scrap_count), '佔比 '+pct(k.scrap_count, k.total), k.total>0 && (k.scrap_count/k.total)>=0.2);
  h += kc('COPQ 金額', money(k.copq)+' 元', c ? '較基期 '+deltaHtml(k.copq, c.copq, money) : '', SETTINGS.copq_alert_amount!==null && k.copq>SETTINGS.copq_alert_amount);
  h += kc('平均結案天數', k.avg_cycle_days===null?'—':k.avg_cycle_days, '樣本 '+nf(k.cycle_sample)+' 張（排除補登紙本）');
  h += kc('待總經理裁示', nf(k.pending_gm), 'MRB 判定尚未完成', k.pending_gm>0);
  h += kc('逾期未結案', nf(DATA.aging_over_count), '現況，不受期間篩選影響', DATA.aging_over_count>0);
  $('#kpiRow').html(h);
}

function renderInsights(){
  var list = DATA.insights || [], icons = {bad:'fa-times-circle', warn:'fa-exclamation-circle', good:'fa-check-circle', info:'fa-info-circle'};
  if(!list.length){ $('#insightList').html('<div style="font-size:12px;color:var(--muted);">本期沒有需要特別指出的變化。</div>'); return; }
  var order = {bad:0, warn:1, good:2, info:3};
  list = list.slice().sort(function(a,b){ return (order[a.level]||9) - (order[b.level]||9); });
  var h = '';
  list.forEach(function(x){
    h += '<div class="ins ins-'+esc(x.level)+'"><div class="ic"><i class="fa '+(icons[x.level]||'fa-info-circle')+'"></i></div>'
       + '<div class="bd"><div class="tt">'+esc(x.title)+'</div><div class="dt">'+esc(x.detail)+'</div></div></div>';
  });
  $('#insightList').html(h);
}

function renderTrend(){
  var t = DATA.trend || [];
  chart('chTrend', {
    xAxis: { categories: t.map(function(x){return x.label;}) },
    yAxis: [
      { title:{text:'筆數／不良數量',style:{color:'#6B4423',fontSize:'11px'}}, gridLineColor:'#F1E8DA' },
      { title:{text:'COPQ 金額（元）',style:{color:'#6B4423',fontSize:'11px'}}, opposite:true, gridLineColor:'transparent' }
    ],
    tooltip:{ shared:true },
    plotOptions:{ column:{ borderWidth:0 } },
    series: [
      { type:'column', name:'異常單數', data:t.map(function(x){return x.count;}), color:C_AMBER, yAxis:0 },
      { type:'line', name:'不良數量', data:t.map(function(x){return x.ng_qty;}), color:C_BROWN, yAxis:0, marker:{enabled:true,radius:3} },
      { type:'line', name:'COPQ 金額', data:t.map(function(x){return x.copq;}), color:C_CORAL, yAxis:1, marker:{enabled:true,radius:3}, dashStyle:'ShortDot' }
    ]
  });
}

function renderParetoPart(){
  var p = DATA.pareto_part || [];
  if(!p.length){ chart('chParetoPart', {series:[]}); return; }
  chart('chParetoPart', {
    xAxis: { categories: p.map(function(x){return x.label;}), labels:{rotation:-30} },
    yAxis: [
      { title:{text:'筆數',style:{color:'#6B4423',fontSize:'11px'}}, allowDecimals:false },
      { title:{text:'累積百分比',style:{color:'#6B4423',fontSize:'11px'}}, opposite:true, max:100, labels:{format:'{value}%'} }
    ],
    tooltip:{ shared:true },
    series: [
      { type:'column', name:'異常單數', data:p.map(function(x){return x.count;}), color:C_AMBER, yAxis:0 },
      { type:'line', name:'累積百分比', data:p.map(function(x){return x.cum_pct;}), color:C_CORAL, yAxis:1, marker:{enabled:true,radius:3} }
    ]
  });
}

function renderParetoCause(){
  var c = (DATA.pareto_cause||{}).top || [];
  chart('chParetoCause', {
    xAxis: { categories: c.map(function(x){return x.label;}) },
    yAxis: { title:{text:'筆數（可能同時屬於多個分類）',style:{color:'#6B4423',fontSize:'11px'}}, allowDecimals:false },
    series: [{ type:'column', name:'筆數', data:c.map(function(x,i){return {y:x.count,color:PAL[i%PAL.length]};}), showInLegend:false }]
  });
  var leaf = (DATA.pareto_cause||{}).leaf || [], h='';
  leaf.forEach(function(x){ h += '<tr><td>'+esc(x.label)+'</td><td class="n">'+nf(x.count)+'</td></tr>'; });
  $('#tblCauseLeaf').html(h || '<tr><td colspan="2" style="text-align:center;color:var(--muted);">本期無資料</td></tr>');
}

function renderParetoSource(){
  var s = DATA.pareto_source || [];
  chart('chParetoSource', {
    xAxis: { categories: s.map(function(x){return x.label;}), labels:{rotation:-30} },
    yAxis: { title:{text:'筆數',style:{color:'#6B4423',fontSize:'11px'}}, allowDecimals:false },
    tooltip:{ pointFormatter:function(){ var p=this; return '<b>'+p.y+'</b> 筆（'+esc(p.srcType||'')+'）'; } },
    series: [{ type:'column', name:'筆數', data:s.map(function(x,i){return {y:x.count,color:PAL[i%PAL.length],srcType:x.type};}), showInLegend:false }]
  });
}

function renderDisposition(){
  var d = DATA.disposition || [];
  chart('chDisposition', {
    tooltip:{ pointFormat:'<b>{point.y}</b> 筆（{point.percentage:.1f}%）' },
    series: [{ type:'pie', name:'筆數', data:d.map(function(x,i){return {name:x.label,y:x.count,color:PAL[i%PAL.length]};}),
               dataLabels:{style:{fontSize:'11px',color:'#4A3524',textOutline:'none'}} }]
  });
}

function renderSourceType(){
  var d = (DATA.source_type||{}).dist || [];
  chart('chSourceType', {
    tooltip:{ pointFormat:'<b>{point.y}</b> 筆（{point.percentage:.1f}%）' },
    series: [{ type:'pie', name:'筆數', data:d.map(function(x,i){return {name:x.label,y:x.count,color:PAL[i%PAL.length]};}),
               dataLabels:{style:{fontSize:'11px',color:'#4A3524',textOutline:'none'}} }]
  });
}

function renderCopqDisp(){
  var d = DATA.copq_by_disposition || [];
  chart('chCopqDisp', {
    xAxis: { categories: d.map(function(x){return x.label;}), labels:{rotation:-30} },
    yAxis: { title:{text:'金額（元）',style:{color:'#6B4423',fontSize:'11px'}} },
    tooltip:{ pointFormatter:function(){ return '<b>'+Number(this.y).toLocaleString('en-US')+'</b> 元'; } },
    series: [{ type:'column', name:'COPQ 金額', data:d.map(function(x,i){return {y:x.amount,color:PAL[i%PAL.length]};}), showInLegend:false }]
  });
}

function openOrder(id){ window.open('qa_abnormal_form.php?id='+encodeURIComponent(id), '_blank'); }

function renderAging(){
  var a = DATA.aging || [], h='';
  a.forEach(function(r){
    h += '<tr class="'+(r.over?'row-over':'')+'">'
       + '<td><a class="ord-link" onclick="openOrder('+r.id+')">'+esc(r.no)+'</a></td>'
       + '<td>'+dispDate(r.busdate)+'</td><td>'+esc(r.client||'')+'</td><td>'+esc(r.part_no||'')+'</td>'
       + '<td><span class="badge-stage">'+esc(r.stage_label)+'</span></td>'
       + '<td class="n">'+nf(r.days)+'</td><td class="n">'+nf(r.threshold)+'</td>'
       + '<td>'+(r.over?'<span class="badge-over">逾期</span>':'')+'</td></tr>';
  });
  $('#tblAging').html(h || '<tr><td colspan="8" style="text-align:center;color:var(--muted);">目前沒有未結案的異常單</td></tr>');
  $('#agingHint').text('目前仍未結案的單，依卡關天數由高到低排列（不受上方期間篩選影響，永遠是現況；共 '+a.length+' 張，逾期 '+DATA.aging_over_count+' 張）');
}

function renderRecur(){
  var r = DATA.recurrence || [], h='';
  r.forEach(function(g){
    var items = g.items.map(function(it){ return '<a class="ord-link" onclick="openOrder('+it.id+')">'+esc(it.no)+'</a>（'+dispDate(it.date)+'）'; }).join('、');
    h += '<tr><td>'+esc(g.part_no)+'</td><td>'+esc(g.client||'')+'</td><td>'+esc(g.cause)+'</td>'
       + '<td class="n">'+nf(g.count)+'</td><td>'+items+'</td></tr>';
  });
  $('#tblRecur').html(h || '<tr><td colspan="5" style="text-align:center;color:var(--muted);">目前沒有達到重複發生門檻的組合</td></tr>');
  $('#recurHint').text('同料號＋同原因分類，在最近 '+SETTINGS.recurrence_months+' 個月內累計達到 '+SETTINGS.recurrence_threshold+' 次以上（只提示，不會擋下結案），共 '+r.length+' 組');
}

load();

<?php if ($perms['canAAdmin']): ?>
/* ── 設定 ───────────────────────────────────────────── */
function openCfg(){
  $.get(QAA_API, {action:'settings_get'}, function(r){
    if(!r || !r.ok){ alert((r&&r.error)||'載入失敗'); return; }
    var s = r.settings;
    $('#s_recur_m').val(s.recurrence_months); $('#s_recur_n').val(s.recurrence_threshold);
    $('#s_age_decide').val(s.aging_days.decide); $('#s_age_reply').val(s.aging_days.reply);
    $('#s_age_gm').val(s.aging_days.gm); $('#s_age_deduct').val(s.aging_days.deduct);
    $('#s_age_qcreview').val(s.aging_days.qcreview); $('#s_age_overall').val(s.aging_overall_days);
    $('#s_copq_alert').val(s.copq_alert_amount===null?'':s.copq_alert_amount);
    $('#cfgErr').text('');
    openMask('cfgMask');
  }, 'json');
}
$('#btnCfg').on('click', openCfg);
$('#btnCfgSave').on('click', function(){
  var v = $('#s_copq_alert').val();
  var s = {
    recurrence_months: parseInt($('#s_recur_m').val(),10)||6,
    recurrence_threshold: parseInt($('#s_recur_n').val(),10)||2,
    aging_days: {
      decide: parseInt($('#s_age_decide').val(),10)||3, reply: parseInt($('#s_age_reply').val(),10)||5,
      gm: parseInt($('#s_age_gm').val(),10)||5, deduct: parseInt($('#s_age_deduct').val(),10)||7,
      qcreview: parseInt($('#s_age_qcreview').val(),10)||3
    },
    aging_overall_days: parseInt($('#s_age_overall').val(),10)||20,
    copq_alert_amount: (v===''||v===null) ? null : parseFloat(v)
  };
  $.post(QAA_API, {action:'settings_save', csrf:CSRF, settings:JSON.stringify(s)}, function(r){
    if(!r || !r.ok){ $('#cfgErr').text((r&&r.error)||'儲存失敗'); return; }
    closeMask('cfgMask'); load();
  }, 'json').fail(function(x){ $('#cfgErr').text('儲存失敗（HTTP '+x.status+'）'); });
});
<?php endif; ?>

/* ══════════════════════════════════════════════════════════════════
 * 列印報告（A4 橫式；本報表不是正式 AS9100 文件，沒有綁定表單編號，
 * 只印公司全名不印 AS 編號。頁碼多頁才印左下角，量寬度用列印實際寬度，
 * 圖表一律用畫面上既有 Highcharts 實例的 getSVG() 轉成向量圖，
 * 不重新畫一次——畫面看到的圖跟列印看到的圖才是同一份數據。見 ai-rules/16。
 * ══════════════════════════════════════════════════════════════════ */
var PR_MG = 12, PR_PAD = 5;
var PR_W_MM = 297, PR_H_MM = 210;   // A4 橫式
function prChartSvg(id, w, h){
  try { var c = CHARTS[id]; if(!c) return ''; return c.getSVG({ chart:{ width:w, height:h } }); } catch(e){ return ''; }
}
function prBadgeTxt(lv){ return lv==='bad'?'要處理':(lv==='warn'?'要注意':(lv==='good'?'正面':'說明')); }
function qaaPrintHtml(){
  var m = DATA.meta, k = DATA.kpi.cur, c = DATA.kpi.cmp;
  var printTime = new Date().toLocaleString('zh-TW');
  var css =
    '*{box-sizing:border-box;margin:0;padding:0;}'+
    'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#222;font-size:9.5pt;padding:'+PR_PAD+'mm;}'+
    '@page{size:'+PR_W_MM+'mm '+PR_H_MM+'mm;margin:'+PR_MG+'mm;}'+
    '@media print{*{-webkit-print-color-adjust:exact;print-color-adjust:exact;}thead{display:table-header-group;}tr{page-break-inside:avoid;}.pr-sec{page-break-inside:avoid;}}'+
    '.pr-head{background:#FBF3E7;color:#4A3524;padding:5mm 7mm;border-radius:2mm;margin-bottom:3mm;border:1px solid #E4D3BC;border-left:3mm solid #DD5138;}'+
    '.pr-co{font-size:15pt;font-weight:700;letter-spacing:1.5px;}'+
    '.pr-tt{font-size:12pt;margin-top:1mm;color:#6B4423;}'+
    '.pr-sub{font-size:8.5pt;margin-top:1.5mm;color:#8a6a4a;}'+
    '.pr-kpi{display:grid;grid-template-columns:repeat(8,minmax(0,1fr));gap:2mm;margin-bottom:3mm;}'+
    '.pr-kc{background:#faf6f0;border:1px solid #E4D3BC;border-top:1mm solid #F0A24B;border-radius:1.5mm;padding:2mm 1.8mm;}'+
    '.pr-kc.warn{border-top-color:#DD5138;}'+
    '.pr-kc .lb{font-size:7.5pt;color:#a08a6f;} .pr-kc .vl{font-size:11pt;font-weight:700;color:#4A3524;} .pr-kc .sb{font-size:7pt;color:#a08a6f;}'+
    '.pr-sec{margin-bottom:3mm;}'+
    '.pr-sec-title{font-size:11pt;font-weight:700;color:#4A3524;border-left:1.2mm solid #F0A24B;padding-left:2mm;margin-bottom:1.5mm;}'+
    '.pr-two{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:4mm;}'+
    '.pr-chart{text-align:center;} .pr-chart svg{max-width:100%;height:auto;}'+
    '.pr-ins{display:flex;gap:2mm;align-items:flex-start;border:1px solid #E4D3BC;border-left:1.2mm solid #B9A78C;border-radius:1.2mm;padding:1.5mm 2.5mm;margin-bottom:1.2mm;font-size:8.3pt;line-height:1.5;}'+
    '.pr-ins.bad{border-left-color:#DD5138;} .pr-ins.warn{border-left-color:#F0A24B;} .pr-ins.good{border-left-color:#4F8A4F;}'+
    '.pr-ins .tag{flex:0 0 auto;font-size:7pt;font-weight:700;color:#fff;background:#B9A78C;border-radius:3mm;padding:0.3mm 1.8mm;}'+
    '.pr-ins.bad .tag{background:#DD5138;} .pr-ins.warn .tag{background:#F0A24B;color:#4E2C0B;} .pr-ins.good .tag{background:#4F8A4F;}'+
    'table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:8pt;}'+
    'th{background:#8a5a2b;color:#fff;padding:1.1mm 1.8mm;font-weight:700;white-space:nowrap;}'+
    'td{padding:1mm 1.8mm;border-bottom:0.2mm solid #E4D3BC;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'+
    'tr:nth-child(even) td{background:#FDFBF8;} .tr{text-align:right;}'+
    '.pr-footer{margin-top:2mm;padding-top:1.5mm;border-top:0.2mm solid #E4D3BC;font-size:7pt;color:#a08a6f;text-align:center;}';

  var h = '<div class="pr-head"><div class="pr-co">'+esc(m.company||'')+'</div><div class="pr-tt">品質異常分析報告</div>'
    + '<div class="pr-sub">期間：'+esc(m.period.label)+'（'+dispDate(m.period.start)+'～'+dispDate(m.period.end)+'）'
    + (m.cmp_period ? '　比較基期：'+esc(m.cmp_period.label) : '')
    + '　列印時間：'+esc(printTime)+'</div></div>';

  function kc(lab,val,sub,warn){ return '<div class="pr-kc'+(warn?' warn':'')+'"><div class="lb">'+lab+'</div><div class="vl">'+val+'</div><div class="sb">'+sub+'</div></div>'; }
  h += '<div class="pr-kpi">'+
    kc('異常單總數', nf(k.total), c?('較基期 '+(k.total-c.total>=0?'+':'')+nf(k.total-c.total)):'') +
    kc('已結案/未結案', nf(k.closed)+'/'+nf(k.open), '結案率 '+pct(k.closed,k.total)) +
    kc('不良總數量', nf(k.ng_qty), c?('較基期 '+(k.ng_qty-c.ng_qty>=0?'+':'')+nf(k.ng_qty-c.ng_qty)):'') +
    kc('報廢件數', nf(k.scrap_count), '佔比 '+pct(k.scrap_count,k.total)) +
    kc('COPQ金額', money(k.copq)+'元', c?('較基期 '+(k.copq-c.copq>=0?'+':'')+money(k.copq-c.copq)+'元'):'', SETTINGS.copq_alert_amount!==null && k.copq>SETTINGS.copq_alert_amount) +
    kc('平均結案天數', k.avg_cycle_days===null?'—':k.avg_cycle_days, '樣本 '+nf(k.cycle_sample)+' 張') +
    kc('待總經理裁示', nf(k.pending_gm), 'MRB尚未完成', k.pending_gm>0) +
    kc('逾期未結案', nf(DATA.aging_over_count), '現況', DATA.aging_over_count>0) +
    '</div>';

  h += '<div class="pr-sec"><div class="pr-sec-title">自動分析</div>';
  var order = {bad:0,warn:1,good:2,info:3};
  var ins = (DATA.insights||[]).slice().sort(function(x,y){ return (order[x.level]||9)-(order[y.level]||9); });
  if(!ins.length) h += '<div style="font-size:8pt;color:#a08a6f;">本期沒有需要特別指出的變化。</div>';
  ins.forEach(function(x){
    h += '<div class="pr-ins '+esc(x.level)+'"><span class="tag">'+esc(prBadgeTxt(x.level))+'</span>'
       + '<div><b>'+esc(x.title)+'</b>　'+esc(x.detail)+'</div></div>';
  });
  h += '</div>';

  var trendSvg = prChartSvg('chTrend', 780, 220);
  if(trendSvg) h += '<div class="pr-sec"><div class="pr-sec-title">異常單趨勢</div><div class="pr-chart">'+trendSvg+'</div></div>';

  var ppSvg = prChartSvg('chParetoPart', 380, 200), pcSvg = prChartSvg('chParetoCause', 380, 200);
  h += '<div class="pr-sec"><div class="pr-two">'+
    '<div><div class="pr-sec-title">柏拉圖：依料號</div>'+(ppSvg?'<div class="pr-chart">'+ppSvg+'</div>':'')+'</div>'+
    '<div><div class="pr-sec-title">柏拉圖：依異常原因分類</div>'+(pcSvg?'<div class="pr-chart">'+pcSvg+'</div>':'')+'</div>'+
    '</div></div>';

  var psSvg = prChartSvg('chParetoSource', 380, 200), dSvg = prChartSvg('chDisposition', 380, 200);
  h += '<div class="pr-sec"><div class="pr-two">'+
    '<div><div class="pr-sec-title">柏拉圖：依發生源頭</div>'+(psSvg?'<div class="pr-chart">'+psSvg+'</div>':'')+'</div>'+
    '<div><div class="pr-sec-title">最終處置分布</div>'+(dSvg?'<div class="pr-chart">'+dSvg+'</div>':'')+'</div>'+
    '</div></div>';

  var stSvg = prChartSvg('chSourceType', 380, 200), cdSvg = prChartSvg('chCopqDisp', 380, 200);
  h += '<div class="pr-sec"><div class="pr-two">'+
    '<div><div class="pr-sec-title">開單來源分布</div>'+(stSvg?'<div class="pr-chart">'+stSvg+'</div>':'')+'</div>'+
    '<div><div class="pr-sec-title">COPQ 依最終處置</div>'+(cdSvg?'<div class="pr-chart">'+cdSvg+'</div>':'')+'</div>'+
    '</div></div>';

  var agingRows = ''; (DATA.aging||[]).slice(0,25).forEach(function(r){
    agingRows += '<tr><td>'+esc(r.no)+'</td><td>'+dispDate(r.busdate)+'</td><td>'+esc(r.client||'')+'</td><td>'+esc(r.part_no||'')+'</td>'
      + '<td>'+esc(r.stage_label)+'</td><td class="tr">'+nf(r.days)+'</td><td class="tr">'+nf(r.threshold)+'</td><td>'+(r.over?'逾期':'')+'</td></tr>';
  });
  h += '<div class="pr-sec"><div class="pr-sec-title">時效監控（現況，前 25 筆，依卡關天數排序）</div>'+
    '<table><colgroup><col style="width:12%"><col style="width:11%"><col style="width:16%"><col style="width:18%"><col style="width:15%"><col style="width:9%"><col style="width:9%"><col style="width:10%"></colgroup>'+
    '<thead><tr><th>異常單號</th><th>業務日期</th><th>客戶</th><th>料號</th><th>目前狀態</th><th class="tr">卡了幾天</th><th class="tr">門檻</th><th>狀態</th></tr></thead>'+
    '<tbody>'+(agingRows||'<tr><td colspan="8" style="text-align:center;">目前沒有未結案的異常單</td></tr>')+'</tbody></table></div>';

  var recurRows = ''; (DATA.recurrence||[]).slice(0,20).forEach(function(g){
    var items = g.items.map(function(it){ return it.no+'('+dispDate(it.date)+')'; }).join('、');
    recurRows += '<tr><td>'+esc(g.part_no)+'</td><td>'+esc(g.client||'')+'</td><td>'+esc(g.cause)+'</td><td class="tr">'+nf(g.count)+'</td><td>'+esc(items)+'</td></tr>';
  });
  h += '<div class="pr-sec"><div class="pr-sec-title">重複發生警示（最近 '+SETTINGS.recurrence_months+' 個月，達 '+SETTINGS.recurrence_threshold+' 次以上）</div>'+
    '<table><colgroup><col style="width:15%"><col style="width:14%"><col style="width:24%"><col style="width:9%"><col style="width:38%"></colgroup>'+
    '<thead><tr><th>料號</th><th>客戶</th><th>原因分類</th><th class="tr">次數</th><th>相關異常單</th></tr></thead>'+
    '<tbody>'+(recurRows||'<tr><td colspan="5" style="text-align:center;">目前沒有達到重複發生門檻的組合</td></tr>')+'</tbody></table></div>';

  h += '<div class="pr-footer">本報告由 EGsystem 品質異常分析自動產生｜列印時間：'+esc(printTime)+'</div>';

  return '<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="utf-8"><title>品質異常分析報告 '+esc(m.period.label)+'</title>'+
    '<style>'+css+'</style></head><body>'+h+'</body></html>';
}
function prNeedPageCounter(win){
  try {
    var wPx = (PR_W_MM - PR_MG*2) * 96/25.4, hPx = (PR_H_MM - PR_MG*2 - PR_PAD*2) * 96/25.4;
    var body = win.document.body, old = body.style.width;
    body.style.width = Math.round(wPx) + 'px';
    var h = body.scrollHeight;
    body.style.width = old;
    return h > hPx;
  } catch(e){ return false; }
}
function prAddPageCounter(win){
  try {
    var st = win.document.createElement('style');
    st.textContent = "@page{ @bottom-left{ content:'第 ' counter(page) ' 頁／共 ' counter(pages) ' 頁'; font-size:8pt; color:#555; } }";
    win.document.head.appendChild(st);
  } catch(e){}
}
$('#btnPrint').on('click', function(){
  if(!DATA){ alert('請先查詢'); return; }
  var w = window.open('', '_blank', 'width=1280,height=900,scrollbars=yes,resizable=yes');
  if(!w){ alert('瀏覽器擋掉了彈出視窗，請允許本站彈出後再試'); return; }
  w.document.write(qaaPrintHtml()); w.document.close(); w.focus();
  try { if(window.EGPrintLog) EGPrintLog.record({ source:'qa_abnormal_analysis', doc_name:'品質異常分析報告 '+DATA.meta.period.label, doc_kind:'form' }); } catch(e){}
  setTimeout(function(){
    if(prNeedPageCounter(w)) prAddPageCounter(w);
    w.print();
  }, 700);
});

$(document).ready(function(){
    $('#sidebar-menu').css('visibility', 'visible');
});
</script>
</body>
</html>
