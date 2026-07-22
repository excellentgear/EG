<?php
// AS 線上表單 — 渲染器 PoC（第一期）
// schema JSON → 單一 <table>（colspan/rowspan，瀏覽器原生分頁）
// 供「設計器預覽 / 填寫頁 / 列印」共用。此頁先做：載入模板 → 可填 → 可列印。
session_start();
if (!isset($_SESSION['userName'])) { header("Location:../../index.php"); exit; }
include_once '../../src/common/_config.php';
include ("../../src/common/DBConnection.php");
$db = (new DBConnection())->getPDO();

$template_id = isset($_GET['template_id']) ? (int)$_GET['template_id'] : 0;
$form_doc_id = isset($_GET['form_doc_id']) ? (int)$_GET['form_doc_id'] : 0;

if ($template_id) {
    $st = $db->prepare("SELECT id, form_doc_id, name, current_schema, published_version, status FROM as_form_template WHERE id=? AND is_deleted=0");
    $st->execute([$template_id]);
} elseif ($form_doc_id) {
    $st = $db->prepare("SELECT id, form_doc_id, name, current_schema, published_version, status FROM as_form_template WHERE form_doc_id=? AND is_deleted=0 ORDER BY id DESC LIMIT 1");
    $st->execute([$form_doc_id]);
} else {
    // 預設載入樣本
    $st = $db->query("SELECT id, form_doc_id, name, current_schema, published_version, status FROM as_form_template WHERE is_deleted=0 ORDER BY id LIMIT 1");
}
$tpl = $st->fetch(PDO::FETCH_ASSOC);
if (!$tpl) { echo "找不到表單模板。請先於設計器建立或種入樣本。"; exit; }
$schema = $tpl['current_schema'] ?: '{}';
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($tpl['name']); ?> | 線上表單</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<style>
  :root{
    --warm-line:#c9a877; --warm-label:#f7e0bd; --warm-label-text:#5a3d1e;
    --warm-title:#f0a24b; --warm-title-text:#4a2c0a; --warm-focus:#dd8a3a;
  }
  body{background:#efe7da;font-family:"Microsoft JhengHei","微軟正黑體",Arial,sans-serif;color:#3a2a17;}
  .form-toolbar{max-width:820px;margin:14px auto 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
  .form-sheet{max-width:820px;margin:12px auto 40px;background:#fff;padding:26px 30px;box-shadow:0 2px 10px rgba(90,61,30,.18);}
  table.eg-form{width:100%;border-collapse:collapse;table-layout:fixed;}
  table.eg-form td{border:1px solid var(--warm-line);padding:5px 7px;vertical-align:middle;font-size:13px;word-break:break-word;}
  td.cell-title{background:var(--warm-title);color:var(--warm-title-text);font-size:19px;font-weight:bold;text-align:center;letter-spacing:3px;padding:10px;}
  td.cell-label{background:var(--warm-label);color:var(--warm-label-text);font-weight:bold;text-align:center;white-space:nowrap;}
  td.cell-label.align-left{text-align:left;}
  td.cell-field{background:#fff;}
  td.cell-sig{background:#fffdf8;height:70px;vertical-align:bottom;text-align:center;color:#9a8b73;}
  td.cell-sig .sig-hint{color:#b7a488;font-size:11px;}
  .eg-form input[type=text],.eg-form input[type=number],.eg-form input[type=date],.eg-form select,.eg-form textarea{
    width:100%;border:none;background:transparent;font-size:13px;color:#3a2a17;padding:2px 3px;outline:none;}
  .eg-form input:focus,.eg-form select:focus,.eg-form textarea:focus{background:#fdf4e6;box-shadow:inset 0 -2px 0 var(--warm-focus);}
  .eg-form textarea{resize:vertical;min-height:120px;line-height:1.6;}
  /* 數字框無上下增減鈕 */
  .eg-form input[type=number]::-webkit-outer-spin-button,
  .eg-form input[type=number]::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
  .eg-form input[type=number]{-moz-appearance:textfield;appearance:textfield;}
  .req-star{color:#dd5138;margin-left:2px;}
  .poc-note{max-width:820px;margin:0 auto;background:#fcf3e3;border:1px solid #e6cfa4;color:#7a5a2d;padding:8px 12px;border-radius:4px;font-size:12px;}
  @media print{
    body{background:#fff;}
    .form-toolbar,.poc-note{display:none;}
    .form-sheet{box-shadow:none;margin:0;max-width:none;padding:0;}
    table.eg-form td{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  }
</style>
</head>
<body>
  <div class="poc-note">
    <i class="fa fa-flask"></i> 第一期渲染 PoC — 由模板 <strong><?php echo htmlspecialchars($tpl['name']); ?></strong>（狀態：<?php echo htmlspecialchars($tpl['status']); ?>）的 schema 即時產生。此頁證明格狀模型可表達真實表單並原生列印；儲存/簽核為下一步。
  </div>
  <div class="form-toolbar">
    <button class="btn btn-warning btn-sm" onclick="window.print()"><i class="fa fa-print"></i> 列印</button>
    <span class="text-muted" style="font-size:12px;">template_id=<?php echo (int)$tpl['id']; ?></span>
  </div>
  <div class="form-sheet">
    <div id="formHost"></div>
  </div>

<script src="../../resource/js/jquery.min.js"></script>
<script>
const SCHEMA = <?php echo $schema; ?>;

// ── 格狀渲染器：schema → 單一 <table>（colspan/rowspan）──────────────
// 供設計器預覽 / 填寫頁 / 列印共用。mode: 'fill'(可填) | 'view'(唯讀)
function renderForm(schema, opts){
  opts = opts || {}; const mode = opts.mode || 'fill'; const data = opts.data || {};
  const cols = (schema.grid && schema.grid.cols) || 6;
  const cells = (schema.cells || []).slice();
  // 占用圖：標記被 colspan/rowspan 覆蓋的格子，避免重複輸出
  const maxR = cells.reduce((m,c)=>Math.max(m, c.r + (c.rs||1)), 0);
  const occ = Array.from({length:maxR}, ()=>new Array(cols).fill(false));
  // 以 (r,c) 建索引
  const at = {};
  cells.forEach(c=>{ at[c.r+'_'+c.c] = c; });

  let html = '<table class="eg-form">';
  // 欄寬：等寬（設計器之後可自訂 colWidths）
  html += '<colgroup>' + Array.from({length:cols}, ()=>`<col style="width:${(100/cols).toFixed(4)}%">`).join('') + '</colgroup>';
  for(let r=0;r<maxR;r++){
    html += '<tr>';
    for(let c=0;c<cols;c++){
      if(occ[r][c]) continue;
      const cell = at[r+'_'+c];
      if(!cell){ occ[r][c]=true; html += '<td></td>'; continue; }
      const cs = cell.cs||1, rs = cell.rs||1;
      for(let dr=0;dr<rs;dr++) for(let dc=0;dc<cs;dc++){ if(occ[r+dr]) occ[r+dr][c+dc]=true; }
      const span = (cs>1?` colspan="${cs}"`:'') + (rs>1?` rowspan="${rs}"`:'');
      html += `<td${span} class="${cellClass(cell)}">${cellInner(cell, mode, data)}</td>`;
    }
    html += '</tr>';
  }
  html += '</table>';
  return html;
}

function cellClass(cell){
  switch(cell.type){
    case 'title': return 'cell-title';
    case 'label': return 'cell-label' + (cell.align==='left'?' align-left':'');
    case 'signature': return 'cell-sig';
    default: return 'cell-field';
  }
}

function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m])); }

function cellInner(cell, mode, data){
  const type = cell.type;
  if(type==='title') return esc(cell.text);
  if(type==='label')  return esc(cell.text) + (cell.required?'<span class="req-star">*</span>':'');
  if(type==='static') return esc(cell.text);
  if(type==='signature'){
    const v = data['__sig_'+(cell.section||cell.key)];
    if(v) return `<div>${esc(v.name)}</div><div class="sig-hint">${esc(v.at||'')}</div>`;
    return '<span class="sig-hint">（待簽核）</span>';
  }
  // field
  const key = cell.key, val = data[key]!=null ? data[key] : '';
  const req = cell.required ? ' data-req="1"' : '';
  const ro  = (mode==='view') ? ' readonly disabled' : '';
  const star = cell.required ? '<span class="req-star">*</span>' : '';
  switch(cell.ftype){
    case 'textarea':
      return `<textarea data-key="${esc(key)}"${req}${ro} rows="${cell.rows||4}">${esc(val)}</textarea>`;
    case 'number':
      return `<input type="number" data-key="${esc(key)}" value="${esc(val)}"${req}${ro}>`;
    case 'date':
      return `<input type="date" data-key="${esc(key)}" value="${esc(val)}"${req}${ro}>`;
    case 'select':{
      const opts = ['<option value=""></option>'].concat((cell.options||[]).map(o=>
        `<option${String(val)===String(o)?' selected':''}>${esc(o)}</option>`)).join('');
      return `<select data-key="${esc(key)}"${req}${ro}>${opts}</select>${star}`;
    }
    case 'checkbox':
      return `<label style="font-weight:normal;"><input type="checkbox" data-key="${esc(key)}"${val?' checked':''}${ro}> ${esc(cell.text||'')}</label>`;
    default:
      return `<input type="text" data-key="${esc(key)}" value="${esc(val)}"${req}${ro}>${star}`;
  }
}

// ── UI 互動規範（ai-rules/08）：雙擊清空、聚焦全選、Enter 跳欄、數字尾0省略 ──
function bindFormUX($host){
  const $fields = $host.find('input[data-key],select[data-key],textarea[data-key]');
  $fields.on('focus', function(){ if(this.type!=='date' && this.select) this.select(); });
  $fields.on('dblclick', function(){ if(this.type!=='checkbox'){ this.value=''; $(this).trigger('focus'); } });
  $fields.on('keydown', function(e){
    if(e.key!=='Enter' || this.tagName==='TEXTAREA') return;
    e.preventDefault();
    const idx = $fields.index(this);
    if(idx < $fields.length-1) $fields.eq(idx+1).trigger('focus');
    else $(this).closest('form,.form-sheet').find('[data-submit]').trigger('click');
  });
  // 數字尾 0 省略（3.50→3.5、3.00→3）
  $host.find('input[type=number]').on('blur', function(){
    if(this.value!=='' && !isNaN(this.value)) this.value = String(parseFloat(this.value));
  });
}

$(function(){
  const $host = $('#formHost');
  $host.html(renderForm(SCHEMA, {mode:'fill'}));
  bindFormUX($host);
});
</script>
</body>
</html>
