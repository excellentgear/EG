<?php
// AS 線上表單 — 設計器（格狀）
// 點格選取 → 右側屬性面板；合併儲存格；簽核區設定；即時預覽；存草稿/發布。
session_start();
if (!isset($_SESSION['userName'])) { header("Location:../../index.php"); exit; }
include_once '../../src/common/_config.php';
include ("../../src/common/DBConnection.php");
$db = (new DBConnection())->getPDO();
$template_id = isset($_GET['template_id']) ? (int)$_GET['template_id'] : 0;
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>表單設計器 | 線上表單</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<link href="../../resource/css/as_form.css?v=<?php echo @filemtime(__DIR__.'/../../resource/css/as_form.css'); ?>" rel="stylesheet">
<style>
  html{overflow-x:hidden;}   /* 只設 html：html+body 同設會讓 body 變第二個捲動容器 */
  .right_col{background:#efe7da;font-family:"Microsoft JhengHei","微軟正黑體",Arial,sans-serif;color:#3a2a17;min-height:100vh;}
  .wrap{clear:both;width:100%;display:flex;gap:14px;padding:12px 16px;align-items:flex-start;}
  .canvas{flex:1;min-width:0;}
  .side{width:300px;flex:none;background:#fff;border:1px solid #d8c19a;border-radius:6px;padding:12px;box-shadow:0 2px 8px rgba(90,61,30,.12);position:sticky;top:10px;}
  .side h4{margin:0 0 8px;font-size:14px;color:#7a4e17;border-bottom:2px solid #f0a24b;padding-bottom:5px;}
  .side .form-group{margin-bottom:8px;}
  .side label{font-size:12px;font-weight:600;color:#6b4e2a;margin-bottom:2px;}
  .topbar{clear:both;width:100%;background:#fff;border:1px solid #d8c19a;border-radius:6px;padding:10px 12px;margin-bottom:10px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;box-shadow:0 2px 8px rgba(90,61,30,.12);}   /* clear+width 防 top_nav float 擠壓 */
  .topbar .sep{width:1px;height:24px;background:#e0cba0;margin:0 4px;}
  /* 編輯格 */
  table.eg-edit{width:100%;border-collapse:collapse;table-layout:fixed;background:#fff;}
  table.eg-edit td{border:1px solid #c9a877;height:34px;padding:3px 5px;font-size:12px;cursor:pointer;vertical-align:middle;position:relative;}
  table.eg-edit td.dz{background:#fbf4e7;color:#c3ad86;text-align:center;font-size:16px;border:1px dashed #d8bf90;}
  table.eg-edit td.dz:hover{background:#f6e8cd;}
  table.eg-edit td.sel{outline:3px solid #dd8a3a;outline-offset:-3px;background:#fff3df !important;}
  table.eg-edit td.fill-target{outline:2px dashed #dd8a3a;outline-offset:-2px;background:#fdeed7 !important;}
  table.eg-edit td.multi-sel{outline:2px solid #dd8a3a;outline-offset:-2px;background:#fbe3c4 !important;}
  table.eg-edit .fill-handle{position:absolute;right:-1px;bottom:-1px;width:9px;height:9px;background:#dd8a3a;border:1px solid #fff;cursor:crosshair;z-index:4;}
  table.eg-edit .fill-handle:hover{transform:scale(1.3);}
  td.e-title{background:#f0a24b;color:#4a2c0a;font-weight:bold;text-align:center;}
  td.e-label{background:#f7e0bd;color:#5a3d1e;font-weight:bold;text-align:center;}
  td.e-field{background:#fff;}
  td.e-sig{background:#fffdf8;color:#9a8b73;text-align:center;}
  .celltag{display:inline-block;font-size:10px;color:#a06a28;background:#fbe7cd;border-radius:3px;padding:0 4px;margin-right:3px;}
  .preview-host{background:#fff;padding:22px 26px;border:1px solid #d8c19a;border-radius:6px;}
  .sec-table{width:100%;font-size:12px;}
  .sec-table th,.sec-table td{padding:3px 4px;border:1px solid #e0cba0;}
  .sec-table input,.sec-table select{font-size:12px;padding:1px 3px;height:26px;}
  .muted{color:#998;font-size:11px;}
  .req-mini{font-size:11px;}
  .btn-xs2{padding:1px 6px;font-size:11px;}
</style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html' ?>
<div class="right_col" role="main" style="padding:10px 14px;">
<div class="topbar">
  <strong style="color:#7a4e17;"><i class="fa fa-th"></i> 表單設計器</strong>
  <input type="text" class="form-control input-sm" id="tplName" placeholder="表單名稱" style="width:200px;">
  <span class="sep"></span>
  <label class="req-mini" style="margin:0;">欄數</label>
  <input type="number" class="form-control input-sm" id="gridCols" style="width:60px;" min="1" max="16">
  <button class="btn btn-default btn-sm" id="btnAddRow"><i class="fa fa-plus"></i> 列</button>
  <button class="btn btn-default btn-sm" id="btnAddCol"><i class="fa fa-plus"></i> 欄</button>
  <button class="btn btn-default btn-sm" id="btnDelRow" title="刪除選取/框選的整列，下方列自動上移補位（仿 Excel）"><i class="fa fa-minus"></i> 列</button>
  <button class="btn btn-default btn-sm" id="btnDelCol" title="刪除選取/框選的整欄，右側欄自動左移補位（仿 Excel）"><i class="fa fa-minus"></i> 欄</button>
  <span class="sep"></span>
  <label class="req-mini" style="margin:0;"><input type="checkbox" id="chkHeader" checked> 表頭</label>
  <label class="req-mini" style="margin:0;"><input type="checkbox" id="chkFooter" checked> 表尾</label>
  <span class="sep"></span>
  <button class="btn btn-default btn-sm" id="btnPrintSet"><i class="fa fa-print"></i> 列印設定</button>
  <button class="btn btn-info btn-sm" id="btnPreview"><i class="fa fa-eye"></i> 預覽</button>
  <button class="btn btn-success btn-sm" id="btnSave"><i class="fa fa-save"></i> 存草稿</button>
  <button class="btn btn-warning btn-sm" id="btnPublish"><i class="fa fa-upload"></i> 發布</button>
  <a class="btn btn-primary btn-sm" id="btnTestFill" target="_blank" style="display:none;" title="開新分頁實際填寫→送出簽核"><i class="fa fa-pencil"></i> 填寫測試</a>
  <a class="btn btn-default btn-sm" href="as_form_list.php" title="回表單清單（紀錄/授權/綁定在此）"><i class="fa fa-list"></i> 清單</a>
  <span id="statusBadge" style="font-size:12px;color:#7a5a2d;margin-left:6px;"></span>
</div>

<div class="wrap">
  <div class="canvas">
    <div id="editHost"></div>
    <p class="muted" style="margin-top:4px;"><i class="fa fa-mouse-pointer"></i> 選取格子後，抓住右下角<strong>橘色小方塊拖曳</strong>＝複製此格到經過的格（同 Excel 填滿）；<strong>左鍵拖過多格＝框選</strong>→ <strong>Delete</strong>/「清空」清內容、頂欄「<strong>−列/−欄</strong>」刪整列整欄並自動補位（Esc 取消框選）</p>
    <div style="margin-top:14px;">
      <h4 style="font-size:14px;color:#7a4e17;border-bottom:2px solid #f0a24b;padding-bottom:5px;">簽核區（section）</h4>
      <p class="muted" style="font-size:11px;">每個「簽名格」綁一個簽核區；step 相同＝平行、遞增＝依序。規則：submitter=填表本人、position=指定職稱、level=N階主管以上。</p>
      <table class="sec-table" id="secTable"><tbody></tbody></table>
      <button class="btn btn-default btn-xs2" id="btnAddSec" style="margin-top:6px;"><i class="fa fa-plus"></i> 新增簽核區</button>
    </div>
  </div>

  <div class="side">
    <h4>格子屬性</h4>
    <div id="propEmpty" class="muted">點選左側任一格編輯；點虛線空格可新增格。</div>
    <div id="propPanel" style="display:none;">
      <div class="form-group">
        <label>類型</label>
        <select class="form-control input-sm" id="pType">
          <option value="label">標題欄（label）</option>
          <option value="field">填寫欄（field）</option>
          <option value="title">大標題（title）</option>
          <option value="static">固定文字（static）</option>
          <option value="signature">簽名格（signature）</option>
          <option value="cs_block">會簽區塊（依勾選部門自動展開）</option>
          <option value="chart">圖表格（chart）</option>
          <option value="blank">空白</option>
        </select>
      </div>
      <div class="prop-csblock" style="display:none;">
        <div class="form-group">
          <label>參與會簽的部門（在此直接勾選）</label>
          <div id="pCsbDeptList" style="max-height:150px;overflow-y:auto;border:1px solid #e0cba0;border-radius:3px;padding:4px 6px;"></div>
          <span class="muted">勾選的部門會直接展開成主表格的列（或欄），一部門一組</span>
        </div>
        <div class="form-group">
          <label>展開方向</label>
          <select class="form-control input-sm" id="pCsbDir">
            <option value="down">往下併（一部門一列）</option>
            <option value="right">往右併（一部門一欄）</option>
          </select>
        </div>
        <div class="form-group">
          <label>區塊代號</label>
          <input type="text" class="form-control input-sm" id="pCsbKey" placeholder="cs">
        </div>
        <div class="form-group">
          <label>綁定簽核區（規則須為「會簽」）</label>
          <select class="form-control input-sm" id="pCsbSection"></select>
        </div>
        <div class="form-group">
          <label class="req-mini"><input type="checkbox" id="pCsbDec" checked> 顯示「同意/不同意」</label>
          <label class="req-mini" style="margin-left:10px;"><input type="checkbox" id="pCsbDecReq"> 必填</label>
        </div>
        <div class="form-group">
          <label class="req-mini"><input type="checkbox" id="pCsbNote" checked> 顯示「意見」欄</label>
          <label class="req-mini" style="margin-left:10px;"><input type="checkbox" id="pCsbNoteReq"> 必填</label>
        </div>
      </div>
      <div class="prop-chart" style="display:none;">
        <div class="form-group">
          <label>圖表類型</label>
          <select class="form-control input-sm" id="pChartKind">
            <option value="radar">雷達圖（至少3個數據）</option>
            <option value="bar">長條圖</option>
            <option value="line">折線圖</option>
          </select>
        </div>
        <div class="form-group">
          <label>數據來源欄位代號（逗號分隔）</label>
          <input type="text" class="form-control input-sm" id="pChartFields" placeholder="s1,s2,s3,s4,s5">
          <span class="muted">填表內數字欄/計算欄的代號，值變動圖表即時重繪</span>
        </div>
        <div class="form-group">
          <label>顯示名稱（選填，逗號分隔對應）</label>
          <input type="text" class="form-control input-sm" id="pChartLabels" placeholder="外觀,尺寸,材質,硬度,包裝">
        </div>
        <div class="form-group">
          <label>刻度上限（選填，留空自動）</label>
          <input type="number" class="form-control input-sm" id="pChartMax">
        </div>
      </div>
      <div class="form-group prop-text">
        <label>文字</label>
        <input type="text" class="form-control input-sm" id="pText">
      </div>
      <div class="form-group prop-field">
        <label>欄位代號 key（英數）</label>
        <input type="text" class="form-control input-sm" id="pKey" placeholder="如 apply_dept">
      </div>
      <div class="form-group prop-field">
        <label>填寫型別</label>
        <select class="form-control input-sm" id="pFtype">
          <option value="text">文字</option>
          <option value="textarea">多行文字</option>
          <option value="number">數字</option>
          <option value="date">日期</option>
          <option value="select">下拉選單</option>
          <option value="checkbox">勾選（多選項）</option>
          <option value="user_name">使用者姓名（自動帶入）</option>
          <option value="user_dept">使用者部門（自動帶入）</option>
          <option value="user_position">使用者職稱（自動帶入）</option>
          <option value="fixed_dept">固定部門（綁部門ID）</option>
          <option value="formula">計算欄（公式）</option>
          <option value="cs_depts">會簽部門勾選</option>
          <option value="cs_decision">會簽同意/不同意</option>
        </select>
      </div>
      <div class="form-group prop-csdepts" style="display:none;">
        <label>參與會簽的部門（可多選）</label>
        <div id="pCsDeptList" style="max-height:130px;overflow-y:auto;border:1px solid #e0cba0;border-radius:3px;padding:4px 6px;"></div>
        <label class="req-mini" style="margin-top:4px;"><input type="checkbox" id="pCsOrder"> 填表時可指定會簽順序（勾選旁出現順序欄）</label>
      </div>
      <div class="form-group prop-formula" style="display:none;">
        <label>公式（同 Excel，引用欄位代號）</label>
        <input type="text" class="form-control input-sm" id="pFormula" placeholder="=qty*price 或 SUM(w1,w2,w3)">
        <span class="muted">支援 + - * / ()、SUM/AVG/MIN/MAX/COUNT(欄位代號逗號分隔)；結果唯讀自動計算</span>
      </div>
      <div class="form-group prop-fixeddept" style="display:none;">
        <label>選擇部門</label>
        <select class="form-control input-sm" id="pFixedDept"></select>
        <span class="muted">存部門ID，之後部門改名會自動連動顯示新名稱</span>
      </div>
      <div class="form-group prop-options" style="display:none;">
        <label>選項（逗號分隔）</label>
        <input type="text" class="form-control input-sm" id="pOptions" placeholder="制定,修訂,廢止">
      </div>
      <div class="form-group prop-today" style="display:none;">
        <label class="req-mini"><input type="checkbox" id="pToday"> 預設帶入今日日期</label>
      </div>
      <div class="form-group prop-pattern" style="display:none;">
        <label>格式規則（regex，留空不檢查）</label>
        <input type="text" class="form-control input-sm" id="pPattern" placeholder="例：^2-[A-Z]{2}-\d{2}-\d{2}$">
        <span class="muted">編號類欄位用：填寫時不符規則會紅底提示，送出時後端再驗一次</span>
      </div>
      <div class="form-group prop-field">
        <label class="req-mini"><input type="checkbox" id="pRequired"> 必填</label>
      </div>
      <div class="form-group prop-sig" style="display:none;">
        <label>綁定簽核區</label>
        <select class="form-control input-sm" id="pSection"></select>
      </div>
      <div class="form-group prop-align">
        <label>對齊</label>
        <select class="form-control input-sm" id="pAlign"><option value="center">置中</option><option value="left">靠左</option></select>
      </div>
      <div class="row" style="margin:0 -4px;">
        <div class="col-xs-6" style="padding:0 4px;"><label class="req-mini">橫跨欄</label><input type="number" class="form-control input-sm" id="pCs" min="1"></div>
        <div class="col-xs-6" style="padding:0 4px;"><label class="req-mini">縱跨列</label><input type="number" class="form-control input-sm" id="pRs" min="1"></div>
      </div>
      <div style="margin-top:10px;display:flex;gap:5px;flex-wrap:wrap;">
        <button class="btn btn-primary btn-xs2" id="btnApply"><i class="fa fa-check"></i> 套用</button>
        <button class="btn btn-default btn-xs2" id="btnMergeR" title="與右邊合併">合併右</button>
        <button class="btn btn-default btn-xs2" id="btnMergeD" title="與下方合併">合併下</button>
        <button class="btn btn-default btn-xs2" id="btnUnmerge">取消合併</button>
        <button class="btn btn-danger btn-xs2" id="btnDelCell"><i class="fa fa-trash"></i> 清空</button>
      </div>
    </div>
  </div>
</div>

<!-- 列印設定 Modal -->
<div class="modal fade" id="printModal" tabindex="-1"><div class="modal-dialog" style="width:440px;"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title"><i class="fa fa-print"></i> 列印設定</h4></div>
  <div class="modal-body">
    <p class="muted" style="font-size:12px;">設定後，填寫頁/預覽按「列印」會自動套用此紙張與縮放；分頁仍由瀏覽器依內容原生換頁。</p>
    <div class="form-group"><label>紙張大小</label>
      <select class="form-control input-sm" id="prPaper"><option>A4</option><option>A5</option><option>B4</option><option>B5</option><option>Letter</option><option>Legal</option></select></div>
    <div class="form-group"><label>方向</label>
      <select class="form-control input-sm" id="prOrient"><option value="portrait">直向</option><option value="landscape">橫向</option></select></div>
    <div class="row">
      <div class="form-group col-xs-6"><label>邊界（mm）</label><input type="number" class="form-control input-sm" id="prMargin" min="0" max="40"></div>
      <div class="form-group col-xs-6"><label>頁數上限（高）</label><input type="number" class="form-control input-sm" id="prFitPages" min="1" max="20" placeholder="不限">
        <span class="muted">設 1＝整張縮進一頁；空＝不限頁數。寬度一律自動縮成一頁寬</span></div>
    </div>
    <div class="form-group"><label>手動縮放上限（%）</label><input type="number" class="form-control input-sm" id="prScale" min="30" max="100" placeholder="100">
      <span class="muted">一般留空即可；要整體再縮小才填（如 85）</span></div>
  </div>
  <div class="modal-footer"><button class="btn btn-primary btn-sm" id="prSave" data-dismiss="modal"><i class="fa fa-check"></i> 套用</button></div>
</div></div></div>

<!-- 預覽 Modal -->
<div class="modal fade" id="previewModal" tabindex="-1"><div class="modal-dialog" style="width:860px;"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">預覽（填寫模式）</h4></div>
  <div class="modal-body" style="background:#efe7da;"><div class="preview-host"><div id="previewHost"></div></div></div>
</div></div></div>

</div><!-- /right_col -->
</div><!-- /main_container -->
</div><!-- /container body -->
<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/as_form_render.js?v=<?php echo @filemtime(__DIR__.'/../../resource/js/as_form_render.js'); ?>"></script>
<script>
const API = '../../src/store/AS_Form_API.php';
const TEMPLATE_ID = <?php echo (int)$template_id; ?>;
let schema = {meta:{header:{},footer:{}}, grid:{cols:6}, cells:[], sections:[]};
let ctx = {};
let META = {positions:[], departments:[]};   // 職稱/部門下拉選項（用選的避免手打錯字）
let editRows = 6;               // 編輯畫布列數（可增）
let sel = null;                 // 選取格 "r_c"
const esc = EGForm.esc;

// ── 載入 ──
function load(){
  if(!TEMPLATE_ID){ $('#statusBadge').text('未指定 template_id'); return; }
  $.getJSON(API+'?action=meta', m=>{ if(m.ok){ META=m; } renderSections(); });
  $.getJSON(API+'?action=load&template_id='+TEMPLATE_ID, r=>{
    if(!r.ok){ alert(r.error||'載入失敗'); return; }
    schema = r.schema && typeof r.schema==='object' ? r.schema : {};
    schema.meta = schema.meta||{}; schema.meta.header = schema.meta.header||{}; schema.meta.footer = schema.meta.footer||{};
    schema.grid = schema.grid||{cols:6}; schema.cells = schema.cells||[]; schema.sections = schema.sections||[];
    ctx = r.ctx||{};
    $('#tplName').val(r.template.name||'');
    $('#gridCols').val(schema.grid.cols||6);
    $('#chkHeader').prop('checked', schema.meta.header.show!==false);
    $('#chkFooter').prop('checked', schema.meta.footer.show!==false);
    const docInfo=(r.ctx&&r.ctx.docNo)?('｜文件編號 '+r.ctx.docNo+(r.ctx.version||'')):'｜未綁定文件（入口頁可綁定）';
    $('#statusBadge').text('狀態：'+r.template.status+'（發布版 '+r.template.published_version+'）'+docInfo+(r.canDesign?'':'　⚠ 唯讀（無設計權）'));
    CAN_DESIGN=!!r.canDesign;
    // 已發布 → 顯示「填寫測試」入口
    if(r.template.published_version>0){ $('#btnTestFill').attr('href','as_form_fill.php?template_id='+TEMPLATE_ID).show(); }
    if(!r.canDesign){ $('.topbar button,.side button,.topbar input,.side input,.side select').prop('disabled',true); }
    recalcRows();
    renderEdit(); renderSections();
  });
}

function maxRowUsed(){ return schema.cells.reduce((m,c)=>Math.max(m, c.r+(c.rs||1)), 0); }
function recalcRows(){ editRows = Math.max(editRows, maxRowUsed()+1, 6); }

// 占用圖：回傳 owner[r][c]=cell 或 null，covered[r][c]=true 表被跨越
function occupancy(){
  const cols = schema.grid.cols;
  const owner = Array.from({length:editRows},()=>new Array(cols).fill(null));
  const covered = Array.from({length:editRows},()=>new Array(cols).fill(false));
  schema.cells.forEach(cell=>{
    const cs=cell.cs||1, rs=cell.rs||1;
    if(cell.r<editRows && cell.c<cols) owner[cell.r][cell.c]=cell;
    for(let dr=0;dr<rs;dr++)for(let dc=0;dc<cs;dc++){
      const rr=cell.r+dr, cc=cell.c+dc;
      if(rr<editRows&&cc<cols&&!(dr===0&&dc===0)) covered[rr][cc]=true;
    }
  });
  return {owner,covered};
}
function cellAt(r,c){ return schema.cells.find(x=>x.r===r&&x.c===c); }

// ── 渲染編輯格 ──
function renderEdit(){
  const cols = schema.grid.cols;
  const {owner,covered} = occupancy();
  let h = '<table class="eg-edit"><colgroup>'+Array.from({length:cols},()=>`<col style="width:${(100/cols).toFixed(3)}%">`).join('')+'</colgroup><tbody>';
  for(let r=0;r<editRows;r++){
    h+='<tr>';
    for(let c=0;c<cols;c++){
      if(covered[r][c]) continue;
      const cell = owner[r][c];
      if(!cell){ h+=`<td class="dz" data-r="${r}" data-c="${c}">+</td>`; continue; }
      const cs=cell.cs||1, rs=cell.rs||1;
      const span=(cs>1?` colspan="${cs}"`:'')+(rs>1?` rowspan="${rs}"`:'');
      const isSel = sel===(r+'_'+c);
      h+=`<td class="${editClass(cell)}${isSel?' sel':''}" data-r="${r}" data-c="${c}"${span}>${editInner(cell)}${isSel?'<span class="fill-handle" title="拖曳填滿（同 Excel）：往下/右拖曳複製此格"></span>':''}</td>`;
    }
    h+='</tr>';
  }
  h+='</tbody></table>';
  $('#editHost').html(h);
}
function editClass(cell){
  return {title:'e-title',label:'e-label',signature:'e-sig'}[cell.type]||'e-field';
}
function editInner(cell){
  if(cell.type==='title'||cell.type==='label'||cell.type==='static') return esc(cell.text||'（空）');
  if(cell.type==='signature') return `<span class="celltag">簽</span>${esc(cell.section||'?')}`;
  if(cell.type==='chart') return `<span class="celltag">圖表</span>${({radar:'雷達',bar:'長條',line:'折線'})[(cell.chart&&cell.chart.kind)||'radar']}｜${esc((cell.chart&&cell.chart.fields||[]).join(','))||'未設數據'}`;
  if(cell.type==='cs_block') return `<span class="celltag">會簽區塊</span>${cell.direction==='right'?'往右併':'往下併'}｜${(cell.dept_ids||[]).length}部門`;
  if(cell.type==='blank') return '';
  const ft={text:'文字',textarea:'多行',number:'數字',date:'日期',select:'下拉',checkbox:'勾選',user_name:'姓名',user_dept:'部門',user_position:'職稱',fixed_dept:'固定部門'}[cell.ftype]||'文字';
  if(cell.ftype==='fixed_dept') return `<span class="celltag">${ft}</span>${esc(cell.dept||'未選')}`;
  return `<span class="celltag">${ft}</span>${esc(cell.key||'未命名')}`;
}

// ── 框選多格（左鍵拖過多格）→ Delete 鍵或「清空」鈕一次清除 ──
let mqStart=null, mqRect=null, suppressClick=false;
$('#editHost').on('mousedown','td',function(e){
  if(e.which!==1 || $(e.target).hasClass('fill-handle')) return;
  const r=+$(this).data('r'), c=+$(this).data('c');
  if(isNaN(r)||isNaN(c)) return;
  mqStart={r,c};
});
$('#editHost').on('mouseover','td',function(){
  if(!mqStart || dragSrc) return;   // 填滿拖曳（控制點）優先，互不干擾
  const r=+$(this).data('r'), c=+$(this).data('c');
  if(isNaN(r)||isNaN(c) || (r===mqStart.r && c===mqStart.c && !mqRect)) return;
  mqRect={r1:Math.min(mqStart.r,r), c1:Math.min(mqStart.c,c), r2:Math.max(mqStart.r,r), c2:Math.max(mqStart.c,c)};
  $('body').css('user-select','none');
  $('#editHost td').removeClass('multi-sel').each(function(){
    const tr=+$(this).data('r'), tc=+$(this).data('c');
    if(tr>=mqRect.r1&&tr<=mqRect.r2&&tc>=mqRect.c1&&tc<=mqRect.c2) $(this).addClass('multi-sel');
  });
});
$(document).on('mouseup',function(){
  if(mqStart && mqRect){ suppressClick=true; setTimeout(()=>{suppressClick=false;},80); }
  mqStart=null; $('body').css('user-select','');
});
function clearRect(){
  if(!mqRect) return false;
  schema.cells=schema.cells.filter(x=>!(x.r>=mqRect.r1&&x.r<=mqRect.r2&&x.c>=mqRect.c1&&x.c<=mqRect.c2));
  mqRect=null; sel=null;
  renderEdit(); $('#propPanel').hide(); $('#propEmpty').show(); scheduleSave();
  return true;
}
$(document).on('keydown',function(e){
  if($(e.target).is('input,select,textarea')) return;
  if(e.key==='Delete' && mqRect){ e.preventDefault(); clearRect(); }
  if(e.key==='Escape' && mqRect){ mqRect=null; $('#editHost td').removeClass('multi-sel'); }
});

// ── 選取 & 屬性面板 ──
$('#editHost').on('click','td',function(){
  if(suppressClick){ suppressClick=false; return; }   // 剛完成框選，這次 click 不當成單選
  const r=+$(this).data('r'), c=+$(this).data('c');
  if($(this).hasClass('dz')){ // 新增格 → 焦點自動移至「文字」欄並全選，直接打字即可
    schema.cells.push({r,c,type:'label',text:'新欄位'});
    sel=r+'_'+c; recalcRows(); renderEdit(); fillProp();
    $('#pText').trigger('focus');   // focus 時已有全選行為
    scheduleSave();
    return;
  }
  sel=r+'_'+c; renderEdit(); fillProp();
});

function fillProp(){
  const [r,c]=sel.split('_').map(Number);
  const cell=cellAt(r,c);
  if(!cell){ $('#propPanel').hide(); $('#propEmpty').show(); return; }
  $('#propEmpty').hide(); $('#propPanel').show();
  $('#pType').val(cell.type||'label');
  $('#pText').val(cell.text||'');
  $('#pKey').val(cell.key||'');
  $('#pFtype').val(cell.ftype||'text');
  $('#pOptions').val((cell.options||[]).join(','));
  $('#pRequired').prop('checked',!!cell.required);
  $('#pToday').prop('checked',!!cell.today);
  $('#pPattern').val(cell.pattern||'');
  $('#pFormula').val(cell.formula||'');
  // 會簽部門多選（cs_depts）與會簽歸屬
  const cdids=(cell.dept_ids||[]).map(String);
  $('#pCsDeptList').html(META.departments.map(d=>
    `<label style="font-weight:normal;display:inline-block;margin:0 10px 2px 0;white-space:nowrap;"><input type="checkbox" class="csdept-chk" value="${d.id}"${cdids.includes(String(d.id))?' checked':''}> ${esc(d.name)}</label>`).join(''));
  $('#pCsOrder').prop('checked',!!cell.show_order);
  // 會簽區塊屬性
  const csbDids=(cell.dept_ids||[]).map(String);
  $('#pCsbDeptList').html(META.departments.map(d=>
    `<label style="font-weight:normal;display:inline-block;margin:0 10px 2px 0;white-space:nowrap;"><input type="checkbox" class="csbdept-chk" value="${d.id}"${csbDids.includes(String(d.id))?' checked':''}> ${esc(d.name)}</label>`).join(''));
  $('#pCsbDir').val(cell.direction||'down');
  $('#pCsbKey').val(cell.key||'cs');
  $('#pCsbSection').html((schema.sections||[]).filter(s=>(s.rule&&s.rule.type)==='countersign').map(s=>
    `<option value="${esc(s.key)}"${cell.section===s.key?' selected':''}>${esc(s.label||s.key)}</option>`).join('')
    ||'<option value="">（請先在下方新增「會簽」規則的簽核區）</option>');
  $('#pCsbDec').prop('checked',cell.show_dec!==false);
  $('#pCsbDecReq').prop('checked',!!cell.dec_required);
  $('#pCsbNote').prop('checked',cell.show_note!==false);
  $('#pCsbNoteReq').prop('checked',!!cell.note_required);
  const ch=cell.chart||{};
  $('#pChartKind').val(ch.kind||'radar');
  $('#pChartFields').val((ch.fields||[]).join(','));
  $('#pChartLabels').val((ch.labels||[]).join(','));
  $('#pChartMax').val(ch.max||'');
  // 固定部門下拉（META.departments 帶出，選中既有 dept_id）
  $('#pFixedDept').html('<option value="">請選擇部門</option>'+META.departments.map(d=>
    `<option value="${d.id}"${String(cell.dept_id||'')===String(d.id)?' selected':''}>${esc(d.name)}</option>`).join(''));
  $('#pAlign').val(cell.align||'center');
  $('#pCs').val(cell.cs||1);
  $('#pRs').val(cell.rs||1);
  fillSectionSelect(cell.section);
  syncPropVisibility();
}
function syncPropVisibility(){
  const t=$('#pType').val(), ft=$('#pFtype').val();
  $('.prop-text').toggle(t==='title'||t==='label'||t==='static');
  $('.prop-field').toggle(t==='field');
  $('.prop-sig').toggle(t==='signature');
  $('.prop-align').toggle(t==='label'||t==='static');
  $('.prop-options').toggle(t==='field' && ['select','checkbox'].includes(ft));
  $('.prop-today').toggle(t==='field' && ft==='date');
  $('.prop-pattern').toggle(t==='field' && ft==='text');
  $('.prop-fixeddept').toggle(t==='field' && ft==='fixed_dept');
  $('.prop-formula').toggle(t==='field' && ft==='formula');
  $('.prop-chart').toggle(t==='chart');
  $('.prop-csdepts').toggle(t==='field' && ft==='cs_depts');
  $('.prop-csblock').toggle(t==='cs_block');
}
$('#pType,#pFtype').on('change',syncPropVisibility);

function fillSectionSelect(cur){
  const opts=(schema.sections||[]).map(s=>`<option value="${esc(s.key)}"${s.key===cur?' selected':''}>${esc(s.label||s.key)}</option>`).join('');
  $('#pSection').html(opts||'<option value="">（尚無簽核區，請先於下方新增）</option>');
}

// 套用屬性（withSpan=true 才套跨欄/列——打字中途不套，避免誤刪被覆蓋的格）
function applyProp(withSpan){
  if(!sel) return;
  const [r,c]=sel.split('_').map(Number);
  const cell=cellAt(r,c); if(!cell) return;
  const t=$('#pType').val();
  cell.type=t;
  ['text','key','ftype','options','required','align','section','rows','today','pattern','dept_id','dept','formula','chart','dept_ids','show_order','cs_dept','direction','show_dec','dec_required','show_note','note_required'].forEach(k=>delete cell[k]);
  if(t==='title'||t==='label'||t==='static'){ cell.text=$('#pText').val(); if(t!=='title'){const a=$('#pAlign').val(); if(a==='left')cell.align='left';} }
  if(t==='field'){
    cell.key=$('#pKey').val().trim();
    cell.ftype=$('#pFtype').val();
    if(cell.ftype==='select'||cell.ftype==='checkbox'){ cell.options=$('#pOptions').val().split(',').map(s=>s.trim()).filter(Boolean); }
    if(cell.ftype==='textarea'){ cell.rows=6; }
    if(cell.ftype==='date' && $('#pToday').prop('checked')) cell.today=true;
    if(cell.ftype==='text'){ const p=$('#pPattern').val().trim(); if(p) cell.pattern=p; }
    if(cell.ftype==='fixed_dept'){
      const did=parseInt($('#pFixedDept').val())||0;
      if(did){ cell.dept_id=did; cell.dept=$('#pFixedDept option:selected').text(); }   // dept 僅設計器顯示備援，正式顯示以 ID 即時解析
    }
    if(cell.ftype==='formula'){ const fx=$('#pFormula').val().trim(); if(fx) cell.formula=fx; }
    if(cell.ftype==='cs_depts'){
      cell.dept_ids=$('#pCsDeptList .csdept-chk:checked').map(function(){return parseInt(this.value);}).get();
      if($('#pCsOrder').prop('checked')) cell.show_order=true;
    }
    if($('#pRequired').prop('checked')) cell.required=true;
  }
  if(t==='signature'){ cell.section=$('#pSection').val(); }
  if(t==='cs_block'){
    cell.key=$('#pCsbKey').val().trim()||'cs';
    cell.section=$('#pCsbSection').val()||'cs';
    cell.direction=$('#pCsbDir').val()||'down';
    cell.dept_ids=$('#pCsbDeptList .csbdept-chk:checked').map(function(){return parseInt(this.value);}).get();
    if(!$('#pCsbDec').prop('checked')) cell.show_dec=false;
    else if($('#pCsbDecReq').prop('checked')) cell.dec_required=true;
    if(!$('#pCsbNote').prop('checked')) cell.show_note=false;
    else if($('#pCsbNoteReq').prop('checked')) cell.note_required=true;
  }
  if(t==='chart'){
    cell.chart={ kind:$('#pChartKind').val()||'radar',
      fields:$('#pChartFields').val().split(',').map(s=>s.trim()).filter(Boolean),
      labels:$('#pChartLabels').val().split(',').map(s=>s.trim()).filter(Boolean) };
    const mx=parseFloat($('#pChartMax').val()); if(mx>0) cell.chart.max=mx;
  }
  if(withSpan!==false){
    const cs=Math.max(1,parseInt($('#pCs').val())||1), rs=Math.max(1,parseInt($('#pRs').val())||1);
    setSpan(cell,cs,rs);
  }
  recalcRows(); renderEdit();
  scheduleSave();   // 屬性修改 → 自動儲存（debounce）
}
$('#btnApply').on('click',()=>applyProp(true));
// 即時反映：打字/變動立刻更新左側格子（跨欄/列只在 change 時套，見上）
$('#pText,#pKey,#pOptions,#pPattern,#pFormula,#pChartFields,#pChartLabels,#pCsbKey').on('input',()=>applyProp(false));
$('#pType,#pFtype,#pAlign,#pRequired,#pSection,#pToday,#pFixedDept,#pChartKind,#pChartMax,#pCsOrder,#pCsbDir,#pCsbSection,#pCsbDec,#pCsbDecReq,#pCsbNote,#pCsbNoteReq').on('change',()=>applyProp(false));
$('#pCsDeptList').on('change','.csdept-chk',()=>applyProp(false));
$('#pCsbDeptList').on('change','.csbdept-chk',()=>applyProp(false));
$('#pCs,#pRs').on('change',()=>applyProp(true));

// ── 自動儲存（debounce 1.2 秒；任何 schema 變動後自動存草稿）──
let saveTimer=null, CAN_DESIGN=true;
function scheduleSave(){
  if(!CAN_DESIGN || !TEMPLATE_ID) return;
  clearTimeout(saveTimer);
  saveTimer=setTimeout(()=>doSave(null,true),1200);
}

// ── 輸入欄位通用互動（ai-rules/08）：聚焦全選、雙擊清空、Enter 跳下一欄 ──
const UX_INPUTS='.side input[type=text], .side input[type=number], .topbar input[type=text], .topbar input[type=number], #secTable input[type=text], #secTable input[type=number]';
$(document).on('focus',UX_INPUTS,function(){ if(this.select) this.select(); });
$(document).on('dblclick',UX_INPUTS,function(){
  this.value=''; $(this).trigger('input').trigger('change').trigger('focus');
});
$(document).on('keydown',UX_INPUTS+', .side select, #secTable select',function(e){
  if(e.key!=='Enter') return;
  e.preventDefault();
  // 同一容器（屬性面板/頂欄/簽核區）內可見的輸入欄依序跳
  const $scope=$(this).closest('.side,.topbar,#secTable');
  const $fields=$scope.find('input:visible:enabled, select:visible:enabled').not('[type=checkbox]');
  const idx=$fields.index(this);
  if(idx>=0 && idx<$fields.length-1) $fields.eq(idx+1).trigger('focus');
  else if($scope.hasClass('side')) applyProp(true);   // 面板最後一欄 Enter＝套用（含跨欄/列）
});

// 設定跨欄/列，清掉被覆蓋區的其他格
function setSpan(cell,cs,rs){
  const cols=schema.grid.cols;
  cs=Math.min(cs, cols-cell.c); rs=Math.max(1,rs);
  cell.cs=cs; cell.rs=rs;
  if(cs>1||rs>1){
    schema.cells=schema.cells.filter(x=>{
      if(x===cell) return true;
      const within = x.r>=cell.r && x.r<cell.r+rs && x.c>=cell.c && x.c<cell.c+cs;
      return !within;
    });
  }
  if(cs<=1) cell.cs=1; if(rs<=1) cell.rs=1;
}
$('#btnMergeR').on('click',()=>spanBtn(1,0));
$('#btnMergeD').on('click',()=>spanBtn(0,1));
function spanBtn(dcs,drs){
  if(!sel) return; const [r,c]=sel.split('_').map(Number); const cell=cellAt(r,c); if(!cell) return;
  setSpan(cell,(cell.cs||1)+dcs,(cell.rs||1)+drs); recalcRows(); renderEdit(); fillProp();
  scheduleSave();
}
$('#btnUnmerge').on('click',function(){
  if(!sel) return; const [r,c]=sel.split('_').map(Number); const cell=cellAt(r,c); if(!cell) return;
  cell.cs=1; cell.rs=1; renderEdit(); fillProp();
  scheduleSave();
});
$('#btnDelCell').on('click',function(){
  if(clearRect()) return;   // 有框選範圍 → 一次清除框選的格
  if(!sel) return; const [r,c]=sel.split('_').map(Number);
  schema.cells=schema.cells.filter(x=>!(x.r===r&&x.c===c));
  sel=null; renderEdit(); $('#propPanel').hide(); $('#propEmpty').show();
  scheduleSave();
});

// ── 滑鼠拖曳填滿（同 Excel）：選取格右下角控制點往下/右/上/左拖曳，複製此格到經過的格 ──
function uniqueKey(base){
  base=String(base||'').replace(/_\d+$/,'');
  const keys=new Set(schema.cells.map(x=>x.key).filter(Boolean));
  if(!keys.has(base)) return base;
  let i=2, k=base+'_2';
  while(keys.has(k)) k=base+'_'+(++i);
  return k;
}
// 把 src 複製到 (nr,nc)，保留跨欄/跨列（修正「跨欄複製變一欄」），清掉目標範圍內其他格
function cloneCellTo(src, nr, nc){
  if(nr<0||nc<0||nc>=schema.grid.cols) return false;
  const clone=JSON.parse(JSON.stringify(src));
  clone.r=nr; clone.c=nc;
  clone.cs=Math.min(src.cs||1, schema.grid.cols-nc);   // 保留跨欄（超邊界收斂）
  clone.rs=src.rs||1;                                   // 保留跨列
  if(clone.key) clone.key=uniqueKey(clone.key);
  schema.cells=schema.cells.filter(x=>!(x.r>=nr && x.r<nr+clone.rs && x.c>=nc && x.c<nc+clone.cs));
  schema.cells.push(clone);
  return true;
}
// 沿主軸（拖曳位移較大的方向）以「來源格跨距」為步長，從來源填到目標
function fillPath(sr,sc,tr,tc){
  const src=cellAt(sr,sc); if(!src) return [];
  const cs=src.cs||1, rs=src.rs||1;
  const dR=tr-sr, dC=tc-sc, path=[];
  if(Math.abs(dR)>=Math.abs(dC)){
    const step=dR>=0?rs:-rs; if(!step) return [];
    for(let r=sr+step; dR>=0? r<=tr : r>=tr; r+=step) path.push([r,sc]);
  } else {
    const step=dC>=0?cs:-cs; if(!step) return [];
    for(let c=sc+step; dC>=0? c<=tc : c>=tc; c+=step) path.push([sr,c]);
  }
  return path;
}
let dragSrc=null, dragTgt=null;
$('#editHost').on('mousedown','.fill-handle',function(e){
  e.preventDefault(); e.stopPropagation();
  if(!sel) return;
  const [r,c]=sel.split('_').map(Number);
  dragSrc={r,c}; dragTgt={r,c};
  $('body').css('user-select','none');
});
$('#editHost').on('mouseover','td',function(){
  if(!dragSrc) return;
  const tr=+$(this).data('r'), tc=+$(this).data('c');
  if(isNaN(tr)||isNaN(tc)) return;
  dragTgt={r:tr,c:tc};
  $('#editHost td').removeClass('fill-target');
  fillPath(dragSrc.r,dragSrc.c,tr,tc).forEach(([r,c])=>{
    $(`#editHost td[data-r="${r}"][data-c="${c}"]`).addClass('fill-target');
  });
});
$(document).on('mouseup',function(){
  if(!dragSrc) return;
  const s=dragSrc, t=dragTgt; dragSrc=null;
  $('body').css('user-select','');
  $('#editHost td').removeClass('fill-target');
  if(!t || (t.r===s.r && t.c===s.c)) return;
  const src=cellAt(s.r,s.c); if(!src) return;
  fillPath(s.r,s.c,t.r,t.c).forEach(([r,c])=>cloneCellTo(src,r,c));
  recalcRows(); renderEdit(); scheduleSave();
});

// ── 刪除整列/整欄，後面的自動補位（仿 Excel）──
function selRange(){
  if(mqRect) return mqRect;
  if(sel){ const [r,c]=sel.split('_').map(Number); return {r1:r,r2:r,c1:c,c2:c}; }
  return null;
}
$('#btnDelRow').on('click',function(){
  const rg=selRange(); if(!rg){ alert('請先點選（或框選）要刪除的列'); return; }
  const n=rg.r2-rg.r1+1;
  if(!confirm(`刪除第 ${rg.r1+1}～${rg.r2+1} 列（共 ${n} 列）？下方列會自動上移補位。`)) return;
  const out=[];
  schema.cells.forEach(x=>{
    const rs=x.rs||1, top=x.r, bot=top+rs-1;
    if(top>=rg.r1 && top<=rg.r2){
      const below=bot-rg.r2;                       // 起點在刪除範圍內：跨列延伸到範圍外→保留剩餘
      if(below>0){ x.r=rg.r1; x.rs=below; out.push(x); }
      return;                                       // 完全在範圍內→刪除
    }
    if(top<rg.r1 && bot>=rg.r1){                    // 起點在上方、跨進範圍→縮跨列
      const ov=Math.min(bot,rg.r2)-rg.r1+1;
      x.rs=Math.max(1,rs-ov); out.push(x); return;
    }
    if(top>rg.r2){ x.r-=n; }                        // 在下方→上移補位
    out.push(x);
  });
  schema.cells=out; editRows=Math.max(3,editRows-n);
  sel=null; mqRect=null;
  recalcRows(); renderEdit(); $('#propPanel').hide(); $('#propEmpty').show(); scheduleSave();
});
$('#btnDelCol').on('click',function(){
  const rg=selRange(); if(!rg){ alert('請先點選（或框選）要刪除的欄'); return; }
  const n=rg.c2-rg.c1+1;
  if(n>=schema.grid.cols){ alert('不能刪除全部欄位'); return; }
  if(!confirm(`刪除第 ${rg.c1+1}～${rg.c2+1} 欄（共 ${n} 欄）？右側欄會自動左移補位。`)) return;
  const out=[];
  schema.cells.forEach(x=>{
    const cs=x.cs||1, left=x.c, right=left+cs-1;
    if(left>=rg.c1 && left<=rg.c2){
      const rest=right-rg.c2;
      if(rest>0){ x.c=rg.c1; x.cs=rest; out.push(x); }
      return;
    }
    if(left<rg.c1 && right>=rg.c1){
      const ov=Math.min(right,rg.c2)-rg.c1+1;
      x.cs=Math.max(1,cs-ov); out.push(x); return;
    }
    if(left>rg.c2){ x.c-=n; }
    out.push(x);
  });
  schema.cells=out;
  schema.grid.cols=Math.max(1,schema.grid.cols-n); $('#gridCols').val(schema.grid.cols);
  sel=null; mqRect=null;
  recalcRows(); renderEdit(); $('#propPanel').hide(); $('#propEmpty').show(); scheduleSave();
});

// ── 結構 ──
$('#gridCols').on('change',function(){
  const v=Math.max(1,Math.min(16,parseInt(this.value)||6));
  schema.grid.cols=v; this.value=v; renderEdit(); scheduleSave();
});
$('#btnAddRow').on('click',()=>{ editRows++; renderEdit(); });
$('#btnAddCol').on('click',()=>{ schema.grid.cols++; $('#gridCols').val(schema.grid.cols); renderEdit(); scheduleSave(); });
$('#chkHeader').on('change',function(){ schema.meta.header.show=this.checked; scheduleSave(); });
$('#chkFooter').on('change',function(){ schema.meta.footer.show=this.checked; scheduleSave(); });
$('#tplName').on('input',scheduleSave);

// ── 簽核區 ──
const RULE_TYPES={submitter:'填表本人',position:'指定職稱',level:'N階主管以上',dept_manager:'單位主管(依表上部門欄)',countersign:'會簽(勾選部門)'};
const DM_MODES={level:'N階以上',position:'指定職稱以上'};
const CS_ORDER={parallel:'平行同時簽',preset:'依部門列出順序',filler:'填表時定順序'};
const CS_DIS={continue:'不同意→記錄繼續',return:'不同意→退回填表人'};
function renderSections(){
  const rows=(schema.sections||[]).map((s,i)=>{
    const rt=(s.rule&&s.rule.type)||'position';
    // 職稱下拉（position 表帶出；相容舊資料：有 position_id 用 id 對、否則用名稱對）
    const curPid=(s.rule&&s.rule.position_id)||'';
    const curPname=(s.rule&&s.rule.position)||'';
    const posOpts='<option value="">請選擇職稱</option>'+META.positions.map(p=>
      `<option value="${p.id}"${(String(curPid)===String(p.id)||(!curPid&&curPname===p.name))?' selected':''}>${esc(p.name)}</option>`).join('');
    // 階下拉（固定 1~3 階主管以上，同 AS 文件權限設定慣例）
    const curLvl=(s.rule&&s.rule.min_level)||'';
    const lvlOpts='<option value="">請選擇</option>'+[1,2,3].map(l=>
      `<option value="${l}"${String(curLvl)===String(l)?' selected':''}>${l} 階主管以上</option>`).join('');
    // 會簽設定（順序模式/不同意效果）
    const curOrd=(s.rule&&s.rule.order)||'parallel';
    const curDis=(s.rule&&s.rule.disagree)||'continue';
    const csOpts=`<select class="s-csorder" style="width:120px;">${Object.keys(CS_ORDER).map(k=>`<option value="${k}"${k===curOrd?' selected':''}>${CS_ORDER[k]}</option>`).join('')}</select>
      <select class="s-csdis" style="width:150px;">${Object.keys(CS_DIS).map(k=>`<option value="${k}"${k===curDis?' selected':''}>${CS_DIS[k]}</option>`).join('')}</select>`;
    // 單位主管：來源部門欄位（表上 user_dept/fixed_dept 欄）＋門檻模式
    const dmSrcCands=(schema.cells||[]).filter(c=>c.type==='field'&&['user_dept','fixed_dept'].includes(c.ftype)&&c.key);
    const curSrc=(s.rule&&s.rule.dept_source)||'';
    const curMode=(s.rule&&s.rule.mode)||'level';
    const dmOpts=`<select class="s-dmsrc" style="width:130px;" title="部門依表上哪個欄位的值決定（兼職者以表上所選為準）">
        ${dmSrcCands.length?dmSrcCands.map(c=>`<option value="${esc(c.key)}"${c.key===curSrc?' selected':''}>欄位:${esc(c.key)}</option>`).join(''):'<option value="">（表上先放「使用者部門」或「固定部門」欄位）</option>'}</select>
      <select class="s-dmmode" style="width:110px;">${Object.keys(DM_MODES).map(k=>`<option value="${k}"${k===curMode?' selected':''}>${DM_MODES[k]}</option>`).join('')}</select>`;
    return `<tr data-i="${i}">
      <td><input class="s-key" value="${esc(s.key||'')}" placeholder="key" style="width:80px;"></td>
      <td><input class="s-label" value="${esc(s.label||'')}" placeholder="標籤" style="width:90px;"></td>
      <td><input class="s-step" type="number" value="${s.step!=null?s.step:1}" style="width:50px;"></td>
      <td><select class="s-rtype">${Object.keys(RULE_TYPES).map(k=>`<option value="${k}"${k===rt?' selected':''}>${RULE_TYPES[k]}</option>`).join('')}</select></td>
      <td><select class="s-pos" style="width:110px;${(rt==='position'||(rt==='dept_manager'&&curMode==='position'))?'':'display:none;'}">${posOpts}</select>
          <span class="s-csopts" style="${rt==='countersign'?'':'display:none;'}">${csOpts}</span>
          <span class="s-dmopts" style="${rt==='dept_manager'?'':'display:none;'}">${dmOpts}</span></td>
      <td><select class="s-lvl" style="width:110px;${(rt==='level'||rt==='countersign'||(rt==='dept_manager'&&curMode==='level'))?'':'display:none;'}">${lvlOpts}</select></td>
      <td><button class="btn btn-danger btn-xs2 s-del">刪</button></td>
    </tr>`;
  }).join('');
  $('#secTable tbody').html(
    `<tr style="background:#f7e0bd;font-weight:600;"><td>key</td><td>標籤</td><td>step</td><td>規則</td><td>職稱</td><td>階</td><td></td></tr>`+rows);
}
$('#btnAddSec').on('click',function(){
  schema.sections.push({key:'sec'+(schema.sections.length+1),label:'簽核',step:schema.sections.length+1,rule:{type:'position'}});
  renderSections(); scheduleSave();
});
$('#secTable').on('click','.s-del',function(){
  const i=+$(this).closest('tr').data('i'); schema.sections.splice(i,1); renderSections(); scheduleSave();
});
// 簽核區欄位即時回寫（職稱/階皆為下拉，存 position_id＋名稱，避免手打錯字）
$('#secTable').on('change','input,select',function(){
  $('#secTable tr[data-i]').each(function(){
    const $tr=$(this);
    const i=+$tr.data('i'); const s=schema.sections[i]; if(!s) return;
    s.key=$tr.find('.s-key').val().trim();
    s.label=$tr.find('.s-label').val().trim();
    s.step=parseInt($tr.find('.s-step').val())||0;
    const rt=$tr.find('.s-rtype').val();
    s.rule=s.rule||{}; s.rule.type=rt;
    if(rt==='position'){
      const pid=parseInt($tr.find('.s-pos').val())||0;
      const pname=$tr.find('.s-pos option:selected').text();
      if(pid){ s.rule.position_id=pid; s.rule.position=pname; }
      else { delete s.rule.position_id; delete s.rule.position; }
      delete s.rule.min_level; delete s.rule.order; delete s.rule.disagree;
    } else if(rt==='level'){
      s.rule.min_level=parseInt($tr.find('.s-lvl').val())||1;
      delete s.rule.position; delete s.rule.position_id; delete s.rule.order; delete s.rule.disagree;
    } else if(rt==='countersign'){
      // 會簽：各被勾選部門的 N階主管以上簽；順序模式與不同意效果每張表單可設
      s.rule.min_level=parseInt($tr.find('.s-lvl').val())||2;
      s.rule.order=$tr.find('.s-csorder').val()||'parallel';
      s.rule.disagree=$tr.find('.s-csdis').val()||'continue';
      delete s.rule.position; delete s.rule.position_id; delete s.rule.dept_source; delete s.rule.mode;
    } else if(rt==='dept_manager'){
      // 單位主管：依表上部門欄位值決定單位（兼職以表上為準）；門檻＝N階以上或指定職稱以上
      s.rule.dept_source=$tr.find('.s-dmsrc').val()||'';
      s.rule.mode=$tr.find('.s-dmmode').val()||'level';
      if(s.rule.mode==='position'){
        const pid=parseInt($tr.find('.s-pos').val())||0;
        if(pid){ s.rule.position_id=pid; s.rule.position=$tr.find('.s-pos option:selected').text(); }
        delete s.rule.min_level;
      } else {
        s.rule.min_level=parseInt($tr.find('.s-lvl').val())||2;
        delete s.rule.position_id; delete s.rule.position;
      }
      delete s.rule.order; delete s.rule.disagree;
    } else {
      delete s.rule.position; delete s.rule.position_id; delete s.rule.min_level; delete s.rule.order; delete s.rule.disagree; delete s.rule.dept_source; delete s.rule.mode;
    }
    // 規則型別切換 → 顯示對應下拉
    const dmMode=$tr.find('.s-dmmode').val()||'level';
    $tr.find('.s-pos').toggle(rt==='position'||(rt==='dept_manager'&&dmMode==='position'));
    $tr.find('.s-csopts').toggle(rt==='countersign');
    $tr.find('.s-dmopts').toggle(rt==='dept_manager');
    $tr.find('.s-lvl').toggle(rt==='level'||rt==='countersign'||(rt==='dept_manager'&&dmMode==='level'));
  });
  scheduleSave();
});

// ── 預覽 / 存 / 發布 ──
// ── 列印設定 ──
$('#btnPrintSet').on('click',function(){
  const p=(schema.meta&&schema.meta.print)||{};
  $('#prPaper').val(p.paper||'A4');
  $('#prOrient').val(p.orientation||'portrait');
  $('#prMargin').val(p.margin!=null?p.margin:10);
  $('#prFitPages').val(p.fit_pages||'');
  $('#prScale').val(p.scale&&p.scale!==100?p.scale:'');
  $('#printModal').modal('show');
});
$('#prSave').on('click',function(){
  schema.meta=schema.meta||{};
  schema.meta.print={ paper:$('#prPaper').val(), orientation:$('#prOrient').val(),
    margin:parseInt($('#prMargin').val())||0 };
  const fp=parseInt($('#prFitPages').val())||0; if(fp>0) schema.meta.print.fit_pages=fp;
  const sc=parseInt($('#prScale').val())||0; if(sc>0&&sc<100) schema.meta.print.scale=sc;
  EGForm.applyPrintSettings(schema);
  scheduleSave();
});

$('#btnPreview').on('click',function(){
  syncMeta();
  $('#previewHost').html(EGForm.renderForm(schema,{mode:'fill',ctx}));
  EGForm.bindFormUX($('#previewHost'));   // 預覽可實際輸入試算公式/圖表
  EGForm.applyPrintSettings(schema);
  $('#previewModal').modal('show');
});
function syncMeta(){
  schema.meta=schema.meta||{}; schema.meta.title=$('#tplName').val();
  schema.meta.header=schema.meta.header||{}; schema.meta.header.show=$('#chkHeader').prop('checked');
  schema.meta.footer=schema.meta.footer||{}; schema.meta.footer.show=$('#chkFooter').prop('checked');
}
function doSave(cb,silent){
  syncMeta();
  $.post(API+'?action=save_schema',{template_id:TEMPLATE_ID, name:$('#tplName').val(), schema_json:JSON.stringify(schema)}, r=>{
    if(!r.ok){ if(!silent) alert(r.error||'儲存失敗'); return; }
    $('#statusBadge').text((silent?'已自動儲存 ':'已存草稿 ')+new Date().toLocaleTimeString());
    if(cb) cb();
  },'json').fail(x=>{ if(!silent) alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
$('#btnSave').on('click',()=>doSave());
$('#btnPublish').on('click',function(){
  if(!confirm('發布會凍結目前設計為新版本，供填寫使用。確定發布？')) return;
  doSave(()=>{
    $.post(API+'?action=publish',{template_id:TEMPLATE_ID}, r=>{
      if(!r.ok){ alert(r.error||'發布失敗'); return; }
      // 發布自動建議文件改版：綁定文件的模板再發布 → 建議開「文件制修申請單」（預填目標文件與原因）
      if(r.suggest_revision){
        const s=r.suggest_revision;
        if(confirm('已發布為第 '+r.version+' 版。\n\n此表單綁定文件「'+s.doc_no+' '+s.doc_name+'」，依文件管制程序，設計改版建議開立「文件制修申請單」。\n\n要現在開單嗎？（會自動預填目標文件與原因）')){
          window.open('as_form_fill.php?template_id='+s.template_id+'&prefill='+encodeURIComponent(JSON.stringify(s.prefill)),'_blank');
        }
      } else {
        alert('已發布為第 '+r.version+' 版');
      }
      load();
    },'json').fail(x=>alert('發布失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)));
  });
});

load();
</script>
</body>
</html>
