<?php
/**
 * drawing_text_recognize.php — 圖面文字辨識
 * 建立：2026-10-12
 *
 * 使用者需求：上傳圖面（圖片／PDF／DXF），辨識出文字與數字，讓使用者挑選哪些是料號、
 * 客戶、備註等，再帶進既有的「新增料號」流程建立。免費、全部在本機跑：
 *   ‧圖片／PDF：系統既有的 Tesseract OCR（本頁用自帶的中英文辨識模型，不動系統安裝，
 *     不需對外連線），PDF 由瀏覽器端 pdf.js 轉成每頁圖片再送出（比照批圖編輯器既有做法）。
 *   ‧DXF（向量圖）：直接解析 TEXT/MTEXT 實體裡的文字字串，100% 準確、不是 OCR；
 *     不支援 DWG（AutoCAD 專屬二進位格式，沒有免費本機解析方式）。
 *
 * 本頁只讀不寫（不碰任何資料表）。真的要建立料號時改開 master_data_management.php
 * （?new_part_no= 等深連結），寫入仍走那一頁既有的 openPartModal(0)/save_part（鐵律4），
 * 本頁完全不重刻一份新增料號的邏輯。
 *
 * 權限：drawing_ocr 模組角色 drawing_ocr_use，系統管理員自動具備。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/TD/drawing_text_recognize.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/drawing_ocr_lib.php';

$db  = (new DBConnection())->getPDO();
$uid = (int)($_SESSION['id'] ?? 0);

$can_use   = drawing_ocr_can_use($db, $uid);
$roleLabel = drawing_ocr_is_admin() ? '系統管理者' : ($can_use ? '可使用' : '無權限');
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>圖面文字辨識</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
<style>
#sidebar-menu { visibility: hidden; }
:root{ --ink:#4A3524; --sand:#F7E0BD; --amber:#F0A24B; --amber-d:#C77C1A;
       --coral:#DD5138; --line:#E4D3BC; --muted:#a08a6f; --good:#5a8f5a; }
body { background:#F6F1EA; }
.right_col .page-title { margin:8px 0 6px; overflow:hidden; clear:both; }
.page-title h3 { color:var(--ink); margin:0; display:flex; align-items:center; gap:10px;
                 flex-wrap:wrap; font-size:22px; }
.page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid var(--amber-d);
                 border-radius:15px; background:#fff; color:var(--amber-d); }
.page-help-btn:hover { background:var(--amber-d); color:#fff; }
.role-tag { font-size:12px; background:#EFE3CF; color:#6B4423; border-radius:10px;
            padding:2px 10px; font-weight:normal; }
.warm-panel { background:#fff; border:1px solid var(--line); border-radius:8px;
              padding:12px 14px; margin-bottom:12px; }
.dtr-tabs { display:flex; gap:8px; margin-bottom:12px; }
.dtr-tab-btn { padding:7px 16px; border:1px solid var(--line); border-radius:16px; background:#fff;
               color:var(--ink); cursor:pointer; font-size:13px; }
.dtr-tab-btn.on { background:var(--amber); color:#fff; border-color:var(--amber-d); }
.dtr-pane { display:none; }
.dtr-pane.on { display:block; }
.dtr-main { display:flex; gap:14px; align-items:flex-start; }
.dtr-left { flex:1; min-width:0; background:#fff; border:1px solid var(--line); border-radius:8px; padding:12px; }
.dtr-right { width:340px; flex:0 0 340px; background:#fff; border:1px solid var(--line); border-radius:8px; padding:12px; }
.dtr-upload { border:2px dashed var(--line); border-radius:8px; padding:16px; text-align:center; margin-bottom:10px; }
.dtr-upload input[type=file] { display:inline-block; }
.dtr-stage-bar { position:sticky; top:0; background:#FFF6E8; border:1px solid var(--amber); border-radius:6px;
                 padding:8px 10px; margin-bottom:8px; display:none; align-items:center; gap:8px; flex-wrap:wrap; z-index:5; }
.dtr-stage-bar.show { display:flex; }
.dtr-stage-text { font-size:13px; color:var(--ink); flex:1; min-width:120px; word-break:break-all; }
.dtr-imgwrap { position:relative; display:inline-block; max-width:100%; }
.dtr-imgwrap img, .dtr-imgwrap svg { max-width:100%; display:block; border:1px solid var(--line); }
.dtr-box { position:absolute; border:1.5px solid rgba(240,162,75,.85); background:rgba(240,162,75,.12);
           cursor:pointer; box-sizing:border-box; }
.dtr-box:hover { background:rgba(240,162,75,.28); }
.dtr-box.staged { border-color:var(--coral); background:rgba(221,81,56,.28); }
.dtr-svgtext { cursor:pointer; }
.dtr-svgtext rect { fill:rgba(240,162,75,.12); stroke:rgba(240,162,75,.85); stroke-width:.5; }
.dtr-svgtext:hover rect { fill:rgba(240,162,75,.28); }
.dtr-svgtext.staged rect { fill:rgba(221,81,56,.28); stroke:var(--coral); }
.dtr-cand-row { display:flex; gap:6px; align-items:center; border-bottom:1px solid var(--line);
                padding:6px 0; }
.dtr-cand-row input[type=text] { flex:1; min-width:0; font-size:12px; padding:3px 6px; }
.dtr-cand-row select { font-size:12px; padding:3px; }
.dtr-kind-pn { color:var(--coral); font-weight:700; }
.dtr-empty-hint { color:var(--muted); font-size:12px; text-align:center; padding:20px 0; }
.dtr-pageqty-row { display:flex; gap:8px; align-items:center; margin-bottom:8px; }
.dtr-toast { position:fixed; left:50%; top:16px; transform:translateX(-50%); background:#4A3524; color:#fff;
             padding:8px 18px; border-radius:6px; font-size:13px; z-index:99999; opacity:0; transition:opacity .2s; }
.dtr-toast.show { opacity:1; }
.help-doc h4 { font-size:14px; color:var(--ink); margin:14px 0 6px; border-bottom:1px solid var(--line); padding-bottom:4px; }
.help-doc h4:first-child { margin-top:0; }
.help-doc p, .help-doc li { font-size:13px; line-height:1.8; color:#5a4530; }
.help-doc ul { padding-left:22px; margin:4px 0 8px; }
.help-doc code { background:#F3E8D6; color:#8a5a2b; padding:1px 5px; border-radius:3px; }
.dtr-mask { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:10500; display:none; }
.dtr-mask.show { display:flex; align-items:flex-start; justify-content:center; padding:40px 12px; }
.dtr-mask .box { background:#fff; border-radius:8px; width:760px; max-width:96vw;
                 max-height:86vh; display:flex; flex-direction:column; }
.dtr-mask .bh { padding:10px 14px; border-bottom:1px solid var(--line); font-weight:700;
                color:var(--ink); display:flex; align-items:center; gap:8px; }
.dtr-mask .bb { padding:14px 18px; overflow:auto; }
@media print { .page-help-btn, .dtr-mask { display:none !important; } }
</style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">

        <div class="page-title">
            <h3><i class="fa fa-font" style="color:var(--coral);"></i> 圖面文字辨識
                <span class="role-tag">目前身分：<?= htmlspecialchars($roleLabel) ?></span>
                <a href="../pages/master_data_management.php" class="btn btn-default btn-sm"
                   style="margin-left:auto;" title="挑好的料號文字會送到這一頁的新增料號跳窗">
                   <i class="fa fa-cubes"></i> 主檔管理</a>
                <button id="btnPageHelp" class="page-help-btn"><i class="fa fa-question-circle"></i> 使用說明</button>
            </h3>
        </div>

<?php if (!$can_use): ?>
        <div class="warm-panel">
            您沒有本頁的使用權限。請洽系統管理員到使用者權限設定頁的「圖面文字辨識」角色區塊，
            勾選 <code>drawing_ocr_use</code>。
        </div>
<?php else: ?>

        <div class="dtr-tabs">
            <button class="dtr-tab-btn on" data-tab="ocr" onclick="dtrSwitchTab('ocr',this)">圖片 / PDF（辨識）</button>
            <button class="dtr-tab-btn" data-tab="dxf" onclick="dtrSwitchTab('dxf',this)">DXF 向量圖（文字解析）</button>
        </div>

        <!-- ══ Tab A：圖片/PDF OCR ══ -->
        <div class="dtr-pane on" id="dtrPaneOcr">
            <div class="dtr-main">
                <div class="dtr-left">
                    <div class="dtr-upload">
                        <input type="file" id="dtrOcrFile" accept="image/png,image/jpeg,image/webp,image/bmp,application/pdf">
                        <div style="font-size:12px;color:var(--muted);margin-top:6px;">
                            支援圖片（PNG/JPG/WEBP/BMP）或 PDF，上傳後自動辨識（本機 Tesseract OCR，免費、不對外連線）。
                        </div>
                    </div>
                    <div id="dtrPdfPageRow" class="dtr-pageqty-row" style="display:none;">
                        <span style="font-size:12px;">PDF 共 <b id="dtrPdfTotal">0</b> 頁，要辨識哪幾頁：</span>
                        <input type="text" id="dtrPdfPages" value="1" style="width:100px;" class="form-control input-sm" placeholder="1,3-5">
                        <button class="btn btn-sm btn-warning" onclick="dtrRunPdfPages()">開始辨識</button>
                    </div>
                    <div id="dtrStageBarOcr" class="dtr-stage-bar">
                        <span class="dtr-stage-text" id="dtrStageTextOcr"></span>
                        <button class="btn btn-xs btn-default" onclick="dtrClearStage()">清除選取</button>
                        <select id="dtrStageKindOcr" class="form-control input-sm" style="width:auto;">
                            <option value="pn">料號</option>
                            <option value="cust">客戶</option>
                            <option value="remark">備註</option>
                            <option value="skip">其他（不理會）</option>
                        </select>
                        <button class="btn btn-sm btn-primary" onclick="dtrAddStageToCand()">加入候選清單</button>
                    </div>
                    <div id="dtrOcrCanvasWrap"><div class="dtr-empty-hint">尚未上傳圖片／PDF</div></div>
                </div>
                <div class="dtr-right">
                    <h5 style="margin-top:0;color:var(--ink);">候選清單</h5>
                    <div id="dtrCandList"><div class="dtr-empty-hint">點圖面上的文字框加入</div></div>
                </div>
            </div>
        </div>

        <!-- ══ Tab B：DXF 向量文字 ══ -->
        <div class="dtr-pane" id="dtrPaneDxf">
            <div class="dtr-main">
                <div class="dtr-left">
                    <div class="dtr-upload">
                        <input type="file" id="dtrDxfFile" accept=".dxf">
                        <div style="font-size:12px;color:var(--muted);margin-top:6px;">
                            僅支援 ASCII DXF（.dxf）向量圖檔，文字為精確解析非OCR；不支援 DWG，請在 CAD 軟體另存成 DXF。
                        </div>
                    </div>
                    <div id="dtrDxfCounts" style="font-size:12px;color:var(--muted);margin-bottom:8px;"></div>
                    <div id="dtrStageBarDxf" class="dtr-stage-bar">
                        <span class="dtr-stage-text" id="dtrStageTextDxf"></span>
                        <button class="btn btn-xs btn-default" onclick="dtrClearStage()">清除選取</button>
                        <select id="dtrStageKindDxf" class="form-control input-sm" style="width:auto;">
                            <option value="pn">料號</option>
                            <option value="cust">客戶</option>
                            <option value="remark">備註</option>
                            <option value="skip">其他（不理會）</option>
                        </select>
                        <button class="btn btn-sm btn-primary" onclick="dtrAddStageToCand()">加入候選清單</button>
                    </div>
                    <div id="dtrDxfSvgWrap"><div class="dtr-empty-hint">尚未上傳 DXF 檔</div></div>
                </div>
                <div class="dtr-right">
                    <h5 style="margin-top:0;color:var(--ink);">候選清單</h5>
                    <div id="dtrCandListDxf"><div class="dtr-empty-hint">點圖面上的文字加入</div></div>
                </div>
            </div>
        </div>

<?php endif; ?>

    </div>
</div>
</div>

<!-- ── 使用說明（鐵律7）──────────────────────────────────────────────── -->
<div class="dtr-mask" id="helpUseMask">
  <div class="box">
    <div class="bh"><i class="fa fa-question-circle"></i> 使用說明
        <span style="margin-left:auto"></span>
        <button class="btn btn-xs btn-default" onclick="document.getElementById('helpUseMask').classList.remove('show')">關閉</button>
    </div>
    <div class="bb help-doc">
      <h4>這個頁面在回答什麼問題</h4>
      <p>收到客戶或廠商給的圖面（掃描圖、PDF、或 CAD 的 DXF 檔），要在系統裡建一個新料號，
         但文字要人工重打——本頁把圖面上的文字辨識出來，讓你用滑鼠挑選哪一段是料號、哪一段是
         客戶名稱，再一鍵帶到既有的「新增料號」畫面，不必自己重新打一次。</p>

      <h4>兩種辨識方式，差在哪</h4>
      <ul>
        <li><b>圖片／PDF（OCR 辨識）</b>：用本機 Tesseract OCR 掃描文字，<b>免費、完全在本機跑、
            不需要對外連網</b>。缺點是 OCR 偶爾會認錯（最常見是 <code>0</code>／<code>O</code>、
            <code>Q</code>／<code>G</code> 看反），辨識出來的文字<b>一定要人工核對再使用</b>，
            系統不會自動把它寫進料號主檔。</li>
        <li><b>DXF 向量圖（文字解析）</b>：如果你手上就是 CAD 原始的 .dxf 檔（不是掃描圖或已轉出的
            PDF），裡面的文字本來就是精確的字串、<b>不是圖片、不需要辨識，100% 準確</b>，
            不會有 0/O 看反的問題。<b>不支援 DWG</b>（AutoCAD 專屬二進位格式），
            請先在 CAD 軟體另存成 DXF 再上傳。</li>
      </ul>

      <h4>操作步驟</h4>
      <ul>
        <li>上傳圖片／PDF／DXF，系統自動辨識並畫出可點選的文字框。PDF 會先問要辨識哪幾頁
            （頁碼可用 <code>1,3-5</code> 這種寫法）。</li>
        <li><b>點一下</b>文字框＝標記起來（可一次點多個框，例如料號分兩行印的情況），上方會出現
            選取列，顯示目前選到的文字內容。</li>
        <li>選好類型（料號／客戶／備註／其他不理會），按「加入候選清單」，右側清單會多一列，
            文字可以再手動修改（修正 OCR 認錯的字）。</li>
        <li>候選清單裡每一列標記為「料號」的，會出現「建立此料號」按鈕，按下去會<b>另開新分頁</b>
            開啟主檔管理的新增料號視窗，並把料號文字（以及標記為客戶的文字）自動帶入，
            剩下的欄位（客戶要從主檔挑選、規格、數量等）請在那一頁補齊後儲存。</li>
      </ul>

      <h4>為什麼不直接自動建立料號</h4>
      <p>OCR 認錯字、同一個客戶名稱可能有好幾種寫法，直接寫進主檔風險很高（會建出一筆垃圾料號）。
         本頁刻意只做到「辨識＋挑選＋帶入」，實際寫入仍透過主檔管理既有的新增料號畫面，
         由人工確認後再按儲存。</p>

      <h4>目前的範圍與尚未做的事</h4>
      <ul>
        <li>本頁<b>不寫入任何資料</b>，只是辨識與挑選工具。</li>
        <li>圖片的辨識準確度受掃描品質、字體、解析度影響，模糊或手寫字辨識效果會差很多。</li>
        <li>DXF 解析支援 TEXT／MTEXT／LINE／CIRCLE／ARC／LWPOLYLINE；舊式
            POLYLINE＋VERTEX 複合實體暫不支援。</li>
        <li>先獨立做成這一頁，之後若要掛到圖面查閱或主檔管理當外掛入口，再視需要調整。</li>
      </ul>

      <h4>權限</h4>
      <p>功能碼 <code>drawing_ocr_use</code>，在使用者權限設定頁的「圖面文字辨識」角色區塊指派；
         系統管理員自動具備。</p>
    </div>
  </div>
</div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_input_rules.js') ?>"></script>

<script>
// ═══════════════════════════════════════════════════════════════════════════
// 共用：toast 提示
// ═══════════════════════════════════════════════════════════════════════════
function dtrToast(msg) {
    var el = document.getElementById('dtrToastEl');
    if (!el) {
        el = document.createElement('div');
        el.id = 'dtrToastEl';
        el.className = 'dtr-toast';
        document.body.appendChild(el);
    }
    el.textContent = msg;
    el.classList.add('show');
    clearTimeout(window._dtrToastT);
    window._dtrToastT = setTimeout(function () { el.classList.remove('show'); }, 2600);
}

// ═══════════════════════════════════════════════════════════════════════════
// 分頁切換
// ═══════════════════════════════════════════════════════════════════════════
var DTR_TAB = 'ocr';
function dtrSwitchTab(tab, btn) {
    DTR_TAB = tab;
    document.querySelectorAll('.dtr-tab-btn').forEach(function (b) { b.classList.toggle('on', b === btn); });
    document.getElementById('dtrPaneOcr').classList.toggle('on', tab === 'ocr');
    document.getElementById('dtrPaneDxf').classList.toggle('on', tab === 'dxf');
    dtrClearStage();
}

// ═══════════════════════════════════════════════════════════════════════════
// 候選清單（兩個分頁共用同一套模型與邏輯，鐵律4）
// ═══════════════════════════════════════════════════════════════════════════
var DTR_CANDS = [];       // [{id,text,kind}]
var DTR_CAND_SEQ = 1;
var DTR_STAGE = [];        // 目前被點選、尚未加入清單的文字（依點選先後排列）
var DTR_KIND_LABEL = { pn: '料號', cust: '客戶', remark: '備註', skip: '其他' };

function dtrStageBarIds() {
    return DTR_TAB === 'ocr'
        ? { bar: 'dtrStageBarOcr', text: 'dtrStageTextOcr', kind: 'dtrStageKindOcr' }
        : { bar: 'dtrStageBarDxf', text: 'dtrStageTextDxf', kind: 'dtrStageKindDxf' };
}
function dtrUpdateStageBar() {
    var ids = dtrStageBarIds();
    var bar = document.getElementById(ids.bar);
    var txt = document.getElementById(ids.text);
    if (!bar) return;
    if (!DTR_STAGE.length) { bar.classList.remove('show'); return; }
    bar.classList.add('show');
    txt.textContent = '目前選取：' + DTR_STAGE.map(function (s) { return s.text; }).join(' ');
}
function dtrToggleStage(text, boxEl) {
    var idx = DTR_STAGE.findIndex(function (s) { return s.el === boxEl; });
    if (idx >= 0) {
        DTR_STAGE.splice(idx, 1);
        boxEl.classList.remove('staged');
    } else {
        DTR_STAGE.push({ text: text, el: boxEl });
        boxEl.classList.add('staged');
    }
    dtrUpdateStageBar();
}
function dtrClearStage() {
    DTR_STAGE.forEach(function (s) { if (s.el) s.el.classList.remove('staged'); });
    DTR_STAGE = [];
    dtrUpdateStageBar();
}
function dtrAddStageToCand() {
    if (!DTR_STAGE.length) { dtrToast('請先在圖面上點選文字'); return; }
    var ids = dtrStageBarIds();
    var kind = document.getElementById(ids.kind).value;
    var text = DTR_STAGE.map(function (s) { return s.text; }).join(' ').trim();
    DTR_CANDS.push({ id: DTR_CAND_SEQ++, text: text, kind: kind });
    dtrClearStage();
    dtrRenderCandList();
    dtrToast('已加入候選清單');
}
function dtrRemoveCand(id) {
    DTR_CANDS = DTR_CANDS.filter(function (c) { return c.id !== id; });
    dtrRenderCandList();
}
function dtrUpdateCandText(id, val) {
    var c = DTR_CANDS.find(function (c) { return c.id === id; });
    if (c) c.text = val;
}
function dtrUpdateCandKind(id, val) {
    var c = DTR_CANDS.find(function (c) { return c.id === id; });
    if (c) c.kind = val;
    dtrRenderCandList();
}
function dtrRenderCandList() {
    var html = '';
    if (!DTR_CANDS.length) {
        html = '<div class="dtr-empty-hint">點圖面上的文字框加入</div>';
    } else {
        DTR_CANDS.forEach(function (c) {
            html += '<div class="dtr-cand-row">'
                + '<input type="text" value="' + dtrEsc(c.text) + '" onchange="dtrUpdateCandText(' + c.id + ',this.value)">'
                + '<select onchange="dtrUpdateCandKind(' + c.id + ',this.value)">'
                + ['pn', 'cust', 'remark', 'skip'].map(function (k) {
                    return '<option value="' + k + '"' + (k === c.kind ? ' selected' : '') + '>' + DTR_KIND_LABEL[k] + '</option>';
                }).join('')
                + '</select>'
                + '<button class="btn btn-xs btn-default" onclick="dtrRemoveCand(' + c.id + ')"><i class="fa fa-times"></i></button>'
                + '</div>';
            if (c.kind === 'pn') {
                html += '<div style="padding:4px 0 8px;"><button class="btn btn-xs btn-warning" onclick="dtrCreatePart(' + c.id + ')">'
                    + '<i class="fa fa-plus"></i> 建立此料號</button></div>';
            }
        });
    }
    var h1 = document.getElementById('dtrCandList');
    var h2 = document.getElementById('dtrCandListDxf');
    if (h1) h1.innerHTML = html;
    if (h2) h2.innerHTML = html;
}
function dtrEsc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

/** 把標記為「料號」的候選帶到主檔管理，另開新分頁開新增料號視窗（寫入仍在那一頁完成） */
function dtrCreatePart(id) {
    var c = DTR_CANDS.find(function (c) { return c.id === id; });
    if (!c) return;
    var remark = DTR_CANDS.filter(function (c) { return c.kind === 'remark'; }).map(function (c) { return c.text; }).join('；');
    var cust = DTR_CANDS.filter(function (c) { return c.kind === 'cust'; }).map(function (c) { return c.text; }).join(' ');
    var qs = 'new_part_no=' + encodeURIComponent(c.text)
        + (remark ? '&new_part_remark=' + encodeURIComponent(remark) : '')
        + (cust ? '&new_part_cust=' + encodeURIComponent(cust) : '');
    window.open('../pages/master_data_management.php?' + qs, '_blank');
}

// ═══════════════════════════════════════════════════════════════════════════
// Tab A：圖片 / PDF OCR
// ═══════════════════════════════════════════════════════════════════════════
var DTR_OCR_CSRF = null; // 本頁為純讀取端點，不需要 CSRF（見 DrawingOcr_API.php 註解）
function dtrRunOcrOnFile(file) {
    document.getElementById('dtrOcrCanvasWrap').innerHTML = '<div class="dtr-empty-hint"><i class="fa fa-spinner fa-spin"></i> 辨識中，請稍候…</div>';
    var fd = new FormData();
    fd.append('action', 'ocr_image');
    fd.append('img', file);
    fetch('../../src/store/DrawingOcr_API.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.ok) { dtrToast(res.error || '辨識失敗'); document.getElementById('dtrOcrCanvasWrap').innerHTML = '<div class="dtr-empty-hint">辨識失敗：' + dtrEsc(res.error || '') + '</div>'; return; }
            var url = URL.createObjectURL(file);
            dtrRenderOcrResult(url, res.width, res.height, res.lines);
        })
        .catch(function (e) { dtrToast('辨識失敗：' + e.message); });
}
function dtrRenderOcrResult(imgUrl, natW, natH, lines) {
    var wrap = document.getElementById('dtrOcrCanvasWrap');
    wrap.innerHTML = '<div class="dtr-imgwrap" id="dtrImgWrap"><img id="dtrOcrImg" src="' + imgUrl + '"></div>'
        + '<div style="font-size:11px;color:var(--muted);margin-top:4px;">共偵測到 ' + lines.length + ' 行文字，點文字框加入候選清單</div>';
    var img = document.getElementById('dtrOcrImg');
    var place = function () {
        var dispW = img.clientWidth, dispH = img.clientHeight;
        var sx = dispW / natW, sy = dispH / natH;
        var host = document.getElementById('dtrImgWrap');
        lines.forEach(function (ln) {
            var b = document.createElement('div');
            b.className = 'dtr-box';
            b.style.left = (ln.left * sx) + 'px';
            b.style.top = (ln.top * sy) + 'px';
            b.style.width = (ln.width * sx) + 'px';
            b.style.height = (ln.height * sy) + 'px';
            b.title = ln.text + '（信心 ' + ln.conf + '%）';
            b.onclick = function () { dtrToggleStage(ln.text, b); };
            host.appendChild(b);
        });
    };
    if (img.complete) place(); else img.onload = place;
}
document.addEventListener('DOMContentLoaded', function () {
    var ocrFile = document.getElementById('dtrOcrFile');
    if (ocrFile) ocrFile.addEventListener('change', function () {
        var f = this.files && this.files[0];
        if (!f) return;
        if (f.type === 'application/pdf' || /\.pdf$/i.test(f.name)) {
            dtrOpenPdf(f);
        } else {
            document.getElementById('dtrPdfPageRow').style.display = 'none';
            dtrRunOcrOnFile(f);
        }
    });
});

// ── PDF → 逐頁轉圖（走前端 pdf.js，比照批圖編輯器既有做法，本機免裝 Ghostscript）──
const PDFJS_BASE = '../../resource/js/pdfjs/';
const PDFJS_V = '<?= @filemtime(__DIR__ . '/../../resource/js/pdfjs/pdf.min.js') ?>';
var dtrPdfjsLoading = null;
function dtrEnsurePdfJs() {
    if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
    if (dtrPdfjsLoading) return dtrPdfjsLoading;
    dtrPdfjsLoading = new Promise(function (resolve, reject) {
        var s = document.createElement('script');
        s.src = PDFJS_BASE + 'pdf.min.js?v=' + PDFJS_V;
        s.onload = function () {
            if (!window.pdfjsLib) { dtrPdfjsLoading = null; reject(new Error('pdfjsLib 未載入')); return; }
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_BASE + 'pdf.worker.min.js?v=' + PDFJS_V;
            resolve(window.pdfjsLib);
        };
        s.onerror = function () { dtrPdfjsLoading = null; reject(new Error('找不到 pdf.js')); };
        document.head.appendChild(s);
    });
    return dtrPdfjsLoading;
}
function parsePdfPageSpec(spec, total) {
    var out = [];
    String(spec || '').split(/[,，、\s]+/).forEach(function (seg) {
        if (!seg) return;
        var m = seg.match(/^(\d+)\s*[-–~～至到]\s*(\d+)$/);
        if (m) {
            var a = parseInt(m[1], 10), b = parseInt(m[2], 10);
            if (a > b) { var t = a; a = b; b = t; }
            for (var i = a; i <= b; i++) if (i >= 1 && i <= total && out.indexOf(i) === -1) out.push(i);
            return;
        }
        var n = parseInt(seg, 10);
        if (n >= 1 && n <= total && out.indexOf(n) === -1) out.push(n);
    });
    return out;
}
var DTR_PDF_DOC = null;
function dtrOpenPdf(file) {
    dtrToast('PDF 讀取中…');
    dtrEnsurePdfJs().then(function (lib) {
        var reader = new FileReader();
        reader.onload = function () {
            lib.getDocument({ data: new Uint8Array(reader.result) }).promise.then(function (doc) {
                DTR_PDF_DOC = doc;
                if (doc.numPages === 1) { dtrRunPdfPage(doc, 1); return; }
                document.getElementById('dtrPdfTotal').textContent = doc.numPages;
                document.getElementById('dtrPdfPages').value = '1';
                document.getElementById('dtrPdfPageRow').style.display = '';
            }).catch(function (e) { dtrToast('PDF 讀取失敗：' + e.message); });
        };
        reader.readAsArrayBuffer(file);
    }).catch(function (e) { dtrToast('無法載入 PDF 轉圖元件：' + e.message); });
}
function dtrRunPdfPages() {
    if (!DTR_PDF_DOC) return;
    var pages = parsePdfPageSpec(document.getElementById('dtrPdfPages').value, DTR_PDF_DOC.numPages);
    if (!pages.length) { dtrToast('請輸入要辨識的頁碼'); return; }
    if (pages.length > 1) { dtrToast('目前一次只能辨識一頁，已取第一頁'); }
    dtrRunPdfPage(DTR_PDF_DOC, pages[0]);
}
function dtrRunPdfPage(doc, pageNo) {
    var MAX_SIDE = 4000;
    doc.getPage(pageNo).then(function (page) {
        var base = page.getViewport({ scale: 1 });
        var scale = 3; // ≈216dpi，工程圖小字要夠解析度
        var longest = Math.max(base.width, base.height);
        if (longest * scale > MAX_SIDE) scale = Math.max(1, MAX_SIDE / longest);
        var vp = page.getViewport({ scale: scale });
        var cv = document.createElement('canvas');
        cv.width = Math.round(vp.width); cv.height = Math.round(vp.height);
        var ctx = cv.getContext('2d');
        page.render({ canvasContext: ctx, viewport: vp }).promise.then(function () {
            cv.toBlob(function (blob) {
                var file = new File([blob], 'pdf_page' + pageNo + '.png', { type: 'image/png' });
                dtrRunOcrOnFile(file);
            }, 'image/png');
        });
    }).catch(function (e) { dtrToast('PDF 轉圖失敗：' + e.message); });
}

// ═══════════════════════════════════════════════════════════════════════════
// Tab B：DXF 向量文字解析
// ═══════════════════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', function () {
    var dxfFile = document.getElementById('dtrDxfFile');
    if (dxfFile) dxfFile.addEventListener('change', function () {
        var f = this.files && this.files[0];
        if (!f) return;
        dtrRunDxf(f);
    });
});
function dtrRunDxf(file) {
    document.getElementById('dtrDxfSvgWrap').innerHTML = '<div class="dtr-empty-hint"><i class="fa fa-spinner fa-spin"></i> 解析中，請稍候…</div>';
    var fd = new FormData();
    fd.append('action', 'dxf_parse');
    fd.append('dxf', file);
    fetch('../../src/store/DrawingOcr_API.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.ok) { document.getElementById('dtrDxfSvgWrap').innerHTML = '<div class="dtr-empty-hint">解析失敗：' + dtrEsc(res.error || '') + '</div>'; return; }
            dtrRenderDxf(res);
        })
        .catch(function (e) { dtrToast('解析失敗：' + e.message); });
}
function dtrFy(y, bbox) { return bbox.miny + bbox.maxy - y; } // Y 軸翻轉（DXF 向上為正，畫面向下為正）
function dtrRenderDxf(d) {
    var c = d.counts;
    document.getElementById('dtrDxfCounts').textContent =
        '文字 ' + c.texts + '　線段 ' + c.lines + '　圓 ' + c.circles + '　弧 ' + c.arcs + '　多段線 ' + c.polylines;
    var bbox = d.bbox;
    var w = Math.max(bbox.maxx - bbox.minx, 1), h = Math.max(bbox.maxy - bbox.miny, 1);
    var pad = Math.max(w, h) * 0.06;
    var sw = Math.max(w, h) * 0.0012;
    var svg = '<svg viewBox="' + (bbox.minx - pad) + ' ' + (bbox.miny - pad) + ' ' + (w + pad * 2) + ' ' + (h + pad * 2) + '" style="width:100%;max-height:70vh;background:#fff;">';
    svg += '<g stroke="#999" stroke-width="' + sw + '" fill="none">';
    (d.lines || []).forEach(function (s) {
        svg += '<line x1="' + s.x1 + '" y1="' + dtrFy(s.y1, bbox) + '" x2="' + s.x2 + '" y2="' + dtrFy(s.y2, bbox) + '"/>';
    });
    (d.circles || []).forEach(function (cc) {
        svg += '<circle cx="' + cc.cx + '" cy="' + dtrFy(cc.cy, bbox) + '" r="' + cc.r + '"/>';
    });
    (d.arcs || []).forEach(function (a) {
        svg += '<circle cx="' + a.cx + '" cy="' + dtrFy(a.cy, bbox) + '" r="' + a.r + '" stroke-dasharray="' + (sw * 4) + ',' + (sw * 2) + '"/>';
    });
    (d.polylines || []).forEach(function (p) {
        var pts = p.pts.map(function (pt) { return pt[0] + ',' + dtrFy(pt[1], bbox); }).join(' ');
        svg += (p.closed ? '<polygon points="' : '<polyline points="') + pts + '"/>';
    });
    svg += '</g>';
    (d.texts || []).forEach(function (t, i) {
        var y = dtrFy(t.y, bbox);
        var rot = -(t.rot || 0);
        var fs = Math.max(t.h || (Math.max(w, h) * 0.02), Math.max(w, h) * 0.012);
        var tw = fs * 0.62 * t.text.length;
        svg += '<g class="dtr-svgtext" id="dtrDxfTxt' + i + '" transform="rotate(' + rot + ' ' + t.x + ' ' + y + ')" onclick="dtrDxfPick(' + i + ')">'
            + '<rect x="' + (t.x - fs * 0.15) + '" y="' + (y - fs * 0.9) + '" width="' + (tw + fs * 0.3) + '" height="' + (fs * 1.1) + '"/>'
            + '<text x="' + t.x + '" y="' + y + '" font-size="' + fs + '" fill="#4A3524">' + dtrEsc(t.text) + '</text>'
            + '</g>';
    });
    svg += '</svg>';
    document.getElementById('dtrDxfSvgWrap').innerHTML = svg;
    window._dtrDxfTexts = d.texts;
}
function dtrDxfPick(i) {
    var el = document.getElementById('dtrDxfTxt' + i);
    var t = window._dtrDxfTexts[i];
    if (!el || !t) return;
    dtrToggleStage(t.text, el);
}
</script>

<script>
$(document).ready(function () {
    $('#btnPageHelp').on('click', function () {
        document.getElementById('helpUseMask').classList.add('show');
    });
    $('#helpUseMask').on('click', function (e) {
        if (e.target === this) this.classList.remove('show');
    });
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') document.getElementById('helpUseMask').classList.remove('show');
    });
});
$(window).on('load', function () {
    $('#sidebar-menu').css('visibility', 'visible');
});
</script>
</body>
</html>
