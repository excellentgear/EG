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
<link href="../../resource/css/as_form.css?v=<?php echo @filemtime(__DIR__.'/../../resource/css/as_form.css'); ?>" rel="stylesheet">
<style>
  body{background:#efe7da;font-family:"Microsoft JhengHei","微軟正黑體",Arial,sans-serif;color:#3a2a17;}
  .wrap{display:flex;gap:14px;padding:12px 16px;align-items:flex-start;}
  .canvas{flex:1;min-width:0;}
  .side{width:300px;flex:none;background:#fff;border:1px solid #d8c19a;border-radius:6px;padding:12px;box-shadow:0 2px 8px rgba(90,61,30,.12);position:sticky;top:10px;}
  .side h4{margin:0 0 8px;font-size:14px;color:#7a4e17;border-bottom:2px solid #f0a24b;padding-bottom:5px;}
  .side .form-group{margin-bottom:8px;}
  .side label{font-size:12px;font-weight:600;color:#6b4e2a;margin-bottom:2px;}
  .topbar{background:#fff;border:1px solid #d8c19a;border-radius:6px;padding:10px 12px;margin-bottom:10px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;box-shadow:0 2px 8px rgba(90,61,30,.12);}
  .topbar .sep{width:1px;height:24px;background:#e0cba0;margin:0 4px;}
  /* 編輯格 */
  table.eg-edit{width:100%;border-collapse:collapse;table-layout:fixed;background:#fff;}
  table.eg-edit td{border:1px solid #c9a877;height:34px;padding:3px 5px;font-size:12px;cursor:pointer;vertical-align:middle;position:relative;}
  table.eg-edit td.dz{background:#fbf4e7;color:#c3ad86;text-align:center;font-size:16px;border:1px dashed #d8bf90;}
  table.eg-edit td.dz:hover{background:#f6e8cd;}
  table.eg-edit td.sel{outline:3px solid #dd8a3a;outline-offset:-3px;background:#fff3df !important;}
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
<body>
<div class="topbar">
  <strong style="color:#7a4e17;"><i class="fa fa-th"></i> 表單設計器</strong>
  <input type="text" class="form-control input-sm" id="tplName" placeholder="表單名稱" style="width:200px;">
  <span class="sep"></span>
  <label class="req-mini" style="margin:0;">欄數</label>
  <input type="number" class="form-control input-sm" id="gridCols" style="width:60px;" min="1" max="16">
  <button class="btn btn-default btn-sm" id="btnAddRow"><i class="fa fa-plus"></i> 列</button>
  <button class="btn btn-default btn-sm" id="btnAddCol"><i class="fa fa-plus"></i> 欄</button>
  <span class="sep"></span>
  <label class="req-mini" style="margin:0;"><input type="checkbox" id="chkHeader" checked> 表頭</label>
  <label class="req-mini" style="margin:0;"><input type="checkbox" id="chkFooter" checked> 表尾</label>
  <span class="sep"></span>
  <button class="btn btn-info btn-sm" id="btnPreview"><i class="fa fa-eye"></i> 預覽</button>
  <button class="btn btn-success btn-sm" id="btnSave"><i class="fa fa-save"></i> 存草稿</button>
  <button class="btn btn-warning btn-sm" id="btnPublish"><i class="fa fa-upload"></i> 發布</button>
  <span id="statusBadge" style="font-size:12px;color:#7a5a2d;margin-left:6px;"></span>
</div>

<div class="wrap">
  <div class="canvas">
    <div id="editHost"></div>
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
          <option value="blank">空白</option>
        </select>
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
          <option value="checkbox">勾選</option>
        </select>
      </div>
      <div class="form-group prop-options" style="display:none;">
        <label>選項（逗號分隔）</label>
        <input type="text" class="form-control input-sm" id="pOptions" placeholder="制定,修訂,廢止">
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

<!-- 預覽 Modal -->
<div class="modal fade" id="previewModal" tabindex="-1"><div class="modal-dialog" style="width:860px;"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">預覽（填寫模式）</h4></div>
  <div class="modal-body" style="background:#efe7da;"><div class="preview-host"><div id="previewHost"></div></div></div>
</div></div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
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
    $('#statusBadge').text('狀態：'+r.template.status+'（發布版 '+r.template.published_version+'）'+(r.canDesign?'':'　⚠ 唯讀（無設計權）'));
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
      h+=`<td class="${editClass(cell)}${isSel?' sel':''}" data-r="${r}" data-c="${c}"${span}>${editInner(cell)}</td>`;
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
  if(cell.type==='blank') return '';
  const ft={text:'文字',textarea:'多行',number:'數字',date:'日期',select:'下拉',checkbox:'勾選'}[cell.ftype]||'文字';
  return `<span class="celltag">${ft}</span>${esc(cell.key||'未命名')}`;
}

// ── 選取 & 屬性面板 ──
$('#editHost').on('click','td',function(){
  const r=+$(this).data('r'), c=+$(this).data('c');
  if($(this).hasClass('dz')){ // 新增格
    schema.cells.push({r,c,type:'label',text:'新欄位'});
    sel=r+'_'+c; recalcRows(); renderEdit(); fillProp();
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
  $('#pAlign').val(cell.align||'center');
  $('#pCs').val(cell.cs||1);
  $('#pRs').val(cell.rs||1);
  fillSectionSelect(cell.section);
  syncPropVisibility();
}
function syncPropVisibility(){
  const t=$('#pType').val();
  $('.prop-text').toggle(t==='title'||t==='label'||t==='static');
  $('.prop-field').toggle(t==='field');
  $('.prop-sig').toggle(t==='signature');
  $('.prop-align').toggle(t==='label'||t==='static');
  $('.prop-options').toggle(t==='field' && ['select','checkbox'].includes($('#pFtype').val()));
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
  ['text','key','ftype','options','required','align','section','rows'].forEach(k=>delete cell[k]);
  if(t==='title'||t==='label'||t==='static'){ cell.text=$('#pText').val(); if(t!=='title'){const a=$('#pAlign').val(); if(a==='left')cell.align='left';} }
  if(t==='field'){
    cell.key=$('#pKey').val().trim();
    cell.ftype=$('#pFtype').val();
    if(cell.ftype==='select'||cell.ftype==='checkbox'){ cell.options=$('#pOptions').val().split(',').map(s=>s.trim()).filter(Boolean); }
    if(cell.ftype==='textarea'){ cell.rows=6; }
    if($('#pRequired').prop('checked')) cell.required=true;
  }
  if(t==='signature'){ cell.section=$('#pSection').val(); }
  if(withSpan!==false){
    const cs=Math.max(1,parseInt($('#pCs').val())||1), rs=Math.max(1,parseInt($('#pRs').val())||1);
    setSpan(cell,cs,rs);
  }
  recalcRows(); renderEdit();
}
$('#btnApply').on('click',()=>applyProp(true));
// 即時反映：打字/變動立刻更新左側格子（跨欄/列只在 change 時套，見上）
$('#pText,#pKey,#pOptions').on('input',()=>applyProp(false));
$('#pType,#pFtype,#pAlign,#pRequired,#pSection').on('change',()=>applyProp(false));
$('#pCs,#pRs').on('change',()=>applyProp(true));

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
}
$('#btnUnmerge').on('click',function(){
  if(!sel) return; const [r,c]=sel.split('_').map(Number); const cell=cellAt(r,c); if(!cell) return;
  cell.cs=1; cell.rs=1; renderEdit(); fillProp();
});
$('#btnDelCell').on('click',function(){
  if(!sel) return; const [r,c]=sel.split('_').map(Number);
  schema.cells=schema.cells.filter(x=>!(x.r===r&&x.c===c));
  sel=null; renderEdit(); $('#propPanel').hide(); $('#propEmpty').show();
});

// ── 結構 ──
$('#gridCols').on('change',function(){
  const v=Math.max(1,Math.min(16,parseInt(this.value)||6));
  schema.grid.cols=v; this.value=v; renderEdit();
});
$('#btnAddRow').on('click',()=>{ editRows++; renderEdit(); });
$('#btnAddCol').on('click',()=>{ schema.grid.cols++; $('#gridCols').val(schema.grid.cols); renderEdit(); });
$('#chkHeader').on('change',function(){ schema.meta.header.show=this.checked; });
$('#chkFooter').on('change',function(){ schema.meta.footer.show=this.checked; });

// ── 簽核區 ──
const RULE_TYPES={submitter:'填表本人',position:'指定職稱',level:'N階主管以上'};
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
    return `<tr data-i="${i}">
      <td><input class="s-key" value="${esc(s.key||'')}" placeholder="key" style="width:80px;"></td>
      <td><input class="s-label" value="${esc(s.label||'')}" placeholder="標籤" style="width:90px;"></td>
      <td><input class="s-step" type="number" value="${s.step!=null?s.step:1}" style="width:50px;"></td>
      <td><select class="s-rtype">${Object.keys(RULE_TYPES).map(k=>`<option value="${k}"${k===rt?' selected':''}>${RULE_TYPES[k]}</option>`).join('')}</select></td>
      <td><select class="s-pos" style="width:110px;${rt==='position'?'':'display:none;'}">${posOpts}</select></td>
      <td><select class="s-lvl" style="width:110px;${rt==='level'?'':'display:none;'}">${lvlOpts}</select></td>
      <td><button class="btn btn-danger btn-xs2 s-del">刪</button></td>
    </tr>`;
  }).join('');
  $('#secTable tbody').html(
    `<tr style="background:#f7e0bd;font-weight:600;"><td>key</td><td>標籤</td><td>step</td><td>規則</td><td>職稱</td><td>階</td><td></td></tr>`+rows);
}
$('#btnAddSec').on('click',function(){
  schema.sections.push({key:'sec'+(schema.sections.length+1),label:'簽核',step:schema.sections.length+1,rule:{type:'position'}});
  renderSections();
});
$('#secTable').on('click','.s-del',function(){
  const i=+$(this).closest('tr').data('i'); schema.sections.splice(i,1); renderSections();
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
      delete s.rule.min_level;
    } else if(rt==='level'){
      s.rule.min_level=parseInt($tr.find('.s-lvl').val())||1;
      delete s.rule.position; delete s.rule.position_id;
    } else {
      delete s.rule.position; delete s.rule.position_id; delete s.rule.min_level;
    }
    // 規則型別切換 → 顯示對應下拉
    $tr.find('.s-pos').toggle(rt==='position');
    $tr.find('.s-lvl').toggle(rt==='level');
  });
});

// ── 預覽 / 存 / 發布 ──
$('#btnPreview').on('click',function(){
  syncMeta();
  $('#previewHost').html(EGForm.renderForm(schema,{mode:'fill',ctx}));
  $('#previewModal').modal('show');
});
function syncMeta(){
  schema.meta=schema.meta||{}; schema.meta.title=$('#tplName').val();
  schema.meta.header=schema.meta.header||{}; schema.meta.header.show=$('#chkHeader').prop('checked');
  schema.meta.footer=schema.meta.footer||{}; schema.meta.footer.show=$('#chkFooter').prop('checked');
}
function doSave(cb){
  syncMeta();
  $.post(API+'?action=save_schema',{template_id:TEMPLATE_ID, name:$('#tplName').val(), schema_json:JSON.stringify(schema)}, r=>{
    if(!r.ok){ alert(r.error||'儲存失敗'); return; }
    $('#statusBadge').text('已存草稿 '+new Date().toLocaleTimeString());
    if(cb) cb();
  },'json').fail(x=>alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)));
}
$('#btnSave').on('click',()=>doSave());
$('#btnPublish').on('click',function(){
  if(!confirm('發布會凍結目前設計為新版本，供填寫使用。確定發布？')) return;
  doSave(()=>{
    $.post(API+'?action=publish',{template_id:TEMPLATE_ID}, r=>{
      if(!r.ok){ alert(r.error||'發布失敗'); return; }
      alert('已發布為第 '+r.version+' 版'); load();
    },'json').fail(x=>alert('發布失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)));
  });
});

load();
</script>
</body>
</html>
