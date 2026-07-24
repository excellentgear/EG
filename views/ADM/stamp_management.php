<?php
// 圖章管理 — 清冊登記（AS9100 圖章管理紀錄）＋ 掃描實體章上傳（去背＋日期帶框選）
// 印模：resource/js/eg_stamp.js（報價單/CAR/AS表單簽核同一套章）；掃描章上傳後三處簽核顯示自動改壓真章。
// 權限：檢閱=所有登入者；登記/上傳/停用/刪除=管理者 or「圖章管理員」角色（user_permissions.php 指派，module=stamp）。
session_start();
if (!isset($_SESSION['userName'])) { header("Location:../../index.php"); exit; }
include_once '../../src/common/_config.php';
include ("../../src/common/DBConnection.php");
$db = (new DBConnection())->getPDO();
$ownCompany = '';
try {
    $r = $db->query("SELECT customer_full, customer FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($r) $ownCompany = $r['customer_full'] ?: ($r['customer'] ?? '');
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>圖章管理 | 清冊登記與掃描章</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
  html,body{overflow-x:hidden;}
  .right_col{background:#efe7da;font-family:"Microsoft JhengHei","微軟正黑體",Arial,sans-serif;color:#3a2a17;padding:16px;min-height:100vh;}
  .panel-warm{max-width:1100px;margin:0 auto 16px;background:#fff;border:1px solid #d8c19a;border-radius:6px;padding:14px 18px;box-shadow:0 2px 8px rgba(90,61,30,.12);}
  .panel-warm h4{margin:0 0 10px;font-size:15px;color:#7a4e17;border-bottom:2px solid #f0a24b;padding-bottom:6px;}
  table.list{width:100%;border-collapse:collapse;font-size:13px;}
  table.list th{background:#f7e0bd;color:#5a3d1e;padding:6px 8px;border:1px solid #e0cba0;}
  table.list td{padding:5px 8px;border:1px solid #e8d9b8;vertical-align:middle;}
  .status-chip{display:inline-block;padding:1px 8px;border-radius:9px;font-size:11px;font-weight:bold;}
  .st-active{background:#f7e0bd;color:#5a3d1e;} .st-revoked{background:#e8dcc3;color:#8a7455;}
  input[type=number]::-webkit-inner-spin-button,input[type=number]::-webkit-outer-spin-button{-webkit-appearance:none;margin:0;}
  input[type=number]{-moz-appearance:textfield;appearance:textfield;}
  .pager{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:6px;}
  .pager .btn{min-width:30px;}
  /* 日期帶框選 */
  #bandBox{position:relative;width:300px;height:300px;border:1px solid #d8c19a;background:
      repeating-conic-gradient(#f3ece0 0 25%, #fff 0 50%) 0 0/20px 20px;user-select:none;margin:0 auto;}
  #bandBox img{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;pointer-events:none;}
  .band-line{position:absolute;left:0;right:0;height:0;border-top:2px dashed #dd5138;cursor:ns-resize;}
  .band-line::after{content:attr(data-lb);position:absolute;right:2px;top:-16px;font-size:11px;color:#dd5138;background:rgba(255,255,255,.85);padding:0 3px;border-radius:3px;}
  .band-shade{position:absolute;left:0;right:0;background:rgba(240,162,75,.18);pointer-events:none;}
  #roleBadge{float:right;font-size:12px;color:#7a4e17;font-weight:normal;}
  #roleBadge .fa-question-circle{cursor:pointer;color:#f0a24b;font-size:14px;}
</style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html' ?>
<div class="right_col" role="main">

<div class="panel-warm">
  <h4><i class="fa fa-certificate"></i> 圖章清冊（核發/停用登記）
    <span id="roleBadge">目前角色：<strong id="myRole">…</strong>　<i class="fa fa-question-circle" data-toggle="modal" data-target="#permModal" title="權限說明"></i></span>
  </h4>
  <div id="noPermMsg" style="display:none;padding:18px;text-align:center;color:#8a7455;font-size:14px;">
    <i class="fa fa-lock" style="font-size:26px;color:#f0a24b;"></i><br>
    您沒有圖章清冊的檢閱權限。<br>
    <span style="font-size:12px;">為避免圖章被瀏覽轉存，清冊僅開放「圖章檢閱」「圖章管理員」角色與管理者。如有需要請洽管理者至「人員權限設定 → 圖章管理」指派。</span>
  </div>
  <div id="mainArea">
  <div id="addBar" style="display:none;margin-bottom:10px;padding:8px;background:#fdf6ea;border:1px solid #e8d9b8;border-radius:4px;">
    <strong style="color:#7a4e17;"><i class="fa fa-plus-circle"></i> 新增登記：</strong>
    <select id="addKind" class="form-control input-sm" style="width:90px;display:inline-block;" title="個人章或部門章">
      <option value="user">個人章</option><option value="dept">部門章</option>
    </select>
    <select id="addUser" class="form-control input-sm" style="width:150px;display:inline-block;"></select>
    <select id="addDept" class="form-control input-sm" style="width:150px;display:none;"></select>
    <select id="addType" class="form-control input-sm" style="width:130px;display:inline-block;" title="印章種類"></select>
    <input type="date" id="addDate" class="form-control input-sm" style="width:150px;display:inline-block;" max="9999-12-31">
    <input type="text" id="addNote" class="form-control input-sm" style="width:200px;display:inline-block;" placeholder="備註（選填）">
    <button class="btn btn-primary btn-sm" id="btnAdd"><i class="fa fa-plus"></i> 登記核發</button>
    <button class="btn btn-default btn-sm" id="btnTypeMng" data-toggle="modal" data-target="#typeModal"><i class="fa fa-tags"></i> 種類管理</button>
    <span class="text-muted" style="font-size:12px;">同一人＋同一種類，同時只能有一筆「使用中」。</span>
  </div>
  <div class="pager">
    <span style="font-size:12.5px;">
      篩選：<input type="text" id="fltName" class="form-control input-sm" style="width:120px;display:inline-block;" placeholder="持有人姓名">
      <select id="fltType" class="form-control input-sm" style="width:130px;display:inline-block;"></select>
      <select id="fltStatus" class="form-control input-sm" style="width:110px;display:inline-block;">
        <option value="">全部狀態</option><option value="active">使用中</option><option value="revoked">已停用</option>
      </select>
      　<span id="sumInfo" class="text-muted"></span>
    </span>
    <span>
      <button class="btn btn-default btn-xs" id="btnCsv"><i class="fa fa-file-excel-o"></i> CSV</button>
      <button class="btn btn-default btn-xs" id="btnPrint"><i class="fa fa-print"></i> 列印/PDF</button>
      每頁 <select id="perSel" class="form-control input-sm" style="width:62px;display:inline-block;">
        <option>5</option><option selected>10</option><option>20</option><option>50</option></select> 筆
      <span id="pageBtns"></span>
    </span>
  </div>
  <table class="list"><thead><tr>
    <th style="width:90px;">印模</th><th style="width:100px;">持有人</th><th style="width:100px;">種類</th><th style="width:95px;">核發日期</th>
    <th style="width:100px;">停用/繳回日</th><th style="width:75px;">狀態</th><th>備註</th>
    <th style="width:85px;">掃描實體章</th><th style="width:190px;">操作</th>
  </tr></thead><tbody id="regBody"></tbody></table>
  </div><!-- /mainArea -->
</div>

<div class="panel-warm" id="basePanel" style="display:none;">
  <h4><i class="fa fa-folder-open-o"></i> 掃描章儲存路徑（僅管理者）</h4>
  <div style="display:flex;gap:8px;align-items:center;">
    <input type="text" id="baseDir" class="form-control input-sm" style="flex:1;font-family:Consolas,monospace;" placeholder="\\excellentnas\...\圖章（留空＝專案內 uploads\stamps）">
    <button class="btn btn-primary btn-sm" id="btnBaseSave"><i class="fa fa-save"></i> 儲存</button>
  </div>
  <p id="baseState" style="font-size:11.5px;margin:6px 0 0;"></p>
  <p class="text-muted" style="font-size:11.5px;margin:4px 0 0;">DB 只存檔名，路徑讀取時即時組出（鐵律5）；更換路徑後請自行把既有 PNG 檔搬到新資料夾。</p>
</div>

<!-- 掃描章管理 Modal -->
<div class="modal fade" id="scanModal" tabindex="-1"><div class="modal-dialog" style="width:720px;"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title">掃描實體章：<span id="scanUserName"></span></h4></div>
  <div class="modal-body">
    <p class="text-muted" style="font-size:12px;">上傳實際蓋章的掃描圖（JPG/PNG，紅色印泥、建議 300dpi），系統自動抽取紅色去背。上傳後拖拉兩條虛線框選「日期帶」位置——簽核顯示時該區域會鋪白蓋掉舊日期、改壓當次簽核日期。</p>
    <div style="margin-bottom:10px;">
      <input type="file" id="scanFile" accept=".jpg,.jpeg,.png" style="display:inline-block;">
      <button class="btn btn-primary btn-sm" id="btnUpload"><i class="fa fa-upload"></i> 上傳並去背</button>
      <button class="btn btn-danger btn-sm" id="btnAssetDel" style="display:none;"><i class="fa fa-trash"></i> 刪除掃描章（回退電子章）</button>
    </div>
    <div id="bandArea" style="display:none;">
      <div style="display:flex;gap:18px;justify-content:center;flex-wrap:wrap;">
        <div>
          <div id="bandBox">
            <img id="bandImg" alt="去背後掃描章">
            <div class="band-shade" id="bandShade"></div>
            <div class="band-line" id="lineTop" data-lb="日期帶上緣"></div>
            <div class="band-line" id="lineBot" data-lb="日期帶下緣"></div>
          </div>
          <p style="text-align:center;font-size:12px;margin-top:4px;">上緣 <span id="pctTop"></span>%　下緣 <span id="pctBot"></span>%</p>
        </div>
        <div style="text-align:center;">
          <p style="font-size:12px;color:#7a4e17;margin-bottom:4px;">簽核顯示預覽（壓今日日期）</p>
          <div id="scanPreview"></div>
          <button class="btn btn-primary btn-sm" id="btnBandSave" style="margin-top:10px;"><i class="fa fa-check"></i> 儲存日期帶位置</button>
        </div>
      </div>
    </div>
  </div>
</div></div></div>

<!-- 編輯登記 Modal -->
<div class="modal fade" id="editModal" tabindex="-1"><div class="modal-dialog" style="width:420px;"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title">編輯登記：<span id="editUserName"></span></h4></div>
  <div class="modal-body">
    <div class="form-group"><label style="font-size:12.5px;">印章種類</label>
      <select id="editType" class="form-control input-sm"></select></div>
    <div class="form-group"><label style="font-size:12.5px;">核發日期</label>
      <input type="date" id="editDate" class="form-control input-sm" max="9999-12-31"></div>
    <div class="form-group"><label style="font-size:12.5px;">備註</label>
      <input type="text" id="editNote" class="form-control input-sm"></div>
    <div style="text-align:right;"><button class="btn btn-primary btn-sm" id="btnEditSave"><i class="fa fa-check"></i> 儲存</button></div>
  </div>
</div></div></div>

<!-- 種類管理 Modal -->
<div class="modal fade" id="typeModal" tabindex="-1"><div class="modal-dialog" style="width:460px;"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title"><i class="fa fa-tags"></i> 印章種類管理</h4></div>
  <div class="modal-body">
    <p class="text-muted" style="font-size:12px;">種類做成主檔統一下拉選擇，避免備註各打各的；停用後新登記選不到、舊資料照常顯示。</p>
    <table class="list"><thead><tr><th>種類名稱</th><th style="width:70px;">狀態</th><th style="width:150px;">操作</th></tr></thead>
      <tbody id="typeBody"></tbody></table>
    <div style="margin-top:10px;display:flex;gap:6px;">
      <input type="text" id="newTypeName" class="form-control input-sm" placeholder="新種類名稱（如：檢驗章）">
      <button class="btn btn-primary btn-sm" id="btnTypeAdd" style="white-space:nowrap;"><i class="fa fa-plus"></i> 新增</button>
    </div>
  </div>
</div></div></div>

<!-- 停用/繳回 Modal -->
<div class="modal fade" id="revModal" tabindex="-1"><div class="modal-dialog" style="width:380px;"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title">停用/繳回：<span id="revUserName"></span></h4></div>
  <div class="modal-body">
    <div class="form-group"><label style="font-size:12.5px;">停用/繳回日期</label>
      <input type="date" id="revDate" class="form-control input-sm" max="9999-12-31"></div>
    <div style="text-align:right;"><button class="btn btn-warning btn-sm" id="btnRevSave"><i class="fa fa-ban"></i> 確認停用</button></div>
  </div>
</div></div></div>

<!-- 權限說明 Modal -->
<div class="modal fade" id="permModal" tabindex="-1"><div class="modal-dialog" style="width:460px;"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title"><i class="fa fa-question-circle"></i> 圖章管理權限說明</h4></div>
  <div class="modal-body" style="font-size:13px;">
    <table class="list">
      <tr><th style="width:110px;">角色</th><th>可做的事</th></tr>
      <tr><td>未指派角色者</td><td><strong>看不到清冊內容</strong>（避免圖章被瀏覽轉存惡意複製）</td></tr>
      <tr><td>圖章檢閱</td><td>唯讀：檢閱清冊、匯出 CSV / 列印</td></tr>
      <tr><td>圖章管理員</td><td>檢閱＋登記核發（個人章/部門章）、編輯、停用/繳回、刪除；種類管理；上傳/刪除掃描實體章、調整日期帶</td></tr>
      <tr><td>管理者</td><td>以上全部＋設定掃描章儲存路徑</td></tr>
    </table>
    <p class="text-muted" style="font-size:12px;margin-top:8px;">「圖章檢閱」「圖章管理員」角色請至 <strong>人員權限設定（user_permissions）→ 圖章管理</strong> 區塊指派。簽核單據上的印章顯示不受此限（看得到單據就看得到章）。</p>
  </div>
</div></div></div>

</div><!-- /right_col -->
</div><!-- /main_container -->
</div><!-- /container body -->
<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script>window.__ownCompany = <?= json_encode($ownCompany, JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="../../resource/js/eg_stamp.js?v=<?php echo @filemtime(__DIR__.'/../../resource/js/eg_stamp.js'); ?>"></script>
<script>
const API='../../src/store/store_Stamp_API.php';
let canManage=false, isAdmin=false, USERS=[], page=1, per=10, total=0, curScanUid=0, curScanName='', curAsset=null, curEditId=0;
function esc(s){return $('<div>').text(s==null?'':s).html();}
function dot(d){return (d||'').substring(0,10).replace(/-/g,'.');}
function today(){const t=new Date();return t.getFullYear()+'-'+String(t.getMonth()+1).padStart(2,'0')+'-'+String(t.getDate()).padStart(2,'0');}

// ── 通用輸入互動（雙擊清空/聚焦全選/Enter 逐欄）──
$(document).on('dblclick','input[type=text],input[type=date]',function(){ $(this).val(''); if(this.id==='fltName'){loadList(1);} });
$(document).on('focus','input[type=text]',function(){ this.select(); });
$('#addKind,#addUser,#addDept,#addType,#addDate,#addNote').on('keydown',function(e){
  if(e.key!=='Enter') return; e.preventDefault();
  const seq=['addKind',($('#addKind').val()==='dept'?'addDept':'addUser'),'addType','addDate','addNote'], i=seq.indexOf(this.id);
  if(i>-1&&i<seq.length-1) $('#'+seq[i+1]).focus(); else $('#btnAdd').click();
});

let TYPES=[], DEPTS=[];
$('#addKind').on('change',function(){
  const isDept=this.value==='dept';
  $('#addUser').toggle(!isDept); $('#addDept').toggle(isDept);
});
function typeOpts(sel,withAll){
  const act=TYPES.filter(t=>+t.is_active===1);
  return (withAll?'<option value="">全部種類</option>':'<option value="">（未分類）</option>')
    +act.map(t=>`<option value="${t.id}" ${String(sel)===String(t.id)?'selected':''}>${esc(t.type_name)}</option>`).join('');
}
function loadMeta(){
  $.getJSON(API+'?action=meta',m=>{
    if(!m.ok){alert(m.error||'載入失敗');return;}
    canManage=m.canManage; isAdmin=m.isAdmin; USERS=m.users||[]; TYPES=m.types||[];
    $('#myRole').text(isAdmin?'管理者':(canManage?'圖章管理員':(m.canView?'圖章檢閱（唯讀）':'無權限')));
    if(m.canView===false){ $('#mainArea').hide(); $('#noPermMsg').show(); return; }
    $('#fltType').html(typeOpts('',true));
    if(canManage){
      $('#addBar').show();
      DEPTS=m.depts||[];
      $('#addUser').html('<option value="">— 選擇人員 —</option>'+USERS.map(u=>`<option value="${u.id}">${esc(u.user_cname)}</option>`).join(''));
      $('#addDept').html('<option value="">— 選擇部門 —</option>'+DEPTS.map(d=>`<option value="${d.id}">${esc(d.name)}</option>`).join(''));
      $('#addType').html(typeOpts('',false));
      $('#addDate').val(today());
      renderTypeMng();
    }
    if(isAdmin){ $('#basePanel').show(); $('#baseDir').val(m.base||''); baseState(m.base,m.base_ok); }
    loadList(1);
  });
}
function baseState(base,ok){
  $('#baseState').html(base?('目前路徑：<code>'+esc(base)+'</code> '+(ok?'<span style="color:#26b99a;">✔ 可存取</span>':'<span style="color:#dd5138;">✘ 資料夾不存在（上傳時會嘗試自動建立）</span>')):'');
}
$('#btnBaseSave').on('click',function(){
  $.post(API+'?action=set_base',{base:$('#baseDir').val().trim()},r=>{
    if(!r.ok){alert(r.error||'儲存失敗');return;}
    $('#baseDir').val(r.base); baseState(r.base,r.base_ok); alert('已儲存');
  },'json');
});

// ── 清冊列表（後端分頁＋彙總）──
function loadList(p){
  page=p||page;
  const q=$('#fltName').val().trim(), st=$('#fltStatus').val(), tid=$('#fltType').val()||'';
  $.getJSON(API,{action:'list',q,status:st,type_id:tid,page,per},r=>{
    if(!r.ok){alert(r.error||'載入失敗');return;}
    total=r.total;
    $('#sumInfo').text(`使用中 ${r.summary.active} 顆／已停用 ${r.summary.revoked} 顆，共 ${total} 筆`);
    $('#regBody').html(r.rows.map(x=>{
      const stampHtml=EGStamp.stamp(x.holder_name, dot(x.issue_date), false);
      return `<tr>
      <td style="text-align:center;">${stampHtml}</td>
      <td>${esc(x.holder_name)}${x.dept_id?' <span class="status-chip" style="background:#e8dcc3;color:#4a3a20;">部門章</span>':''}</td>
      <td>${x.type_name?esc(x.type_name):'<span class="text-muted">（未分類）</span>'}</td>
      <td>${esc(x.issue_date||'')}</td>
      <td>${esc(x.revoke_date||'—')}</td>
      <td><span class="status-chip st-${x.status}">${x.status==='active'?'使用中':'已停用'}</span></td>
      <td>${esc(x.note||'')}</td>
      <td style="text-align:center;">${+x.has_asset?'<span style="color:#7a4e17;font-weight:bold;">已上傳</span>':'<span class="text-muted">—</span>'}</td>
      <td>${canManage?`
        ${x.user_id?`<button class="btn btn-default btn-xs scan-btn" data-uid="${x.user_id}" data-name="${esc(x.holder_name)}"><i class="fa fa-image"></i> 掃描章</button>`:''}
        <button class="btn btn-default btn-xs edit-btn" data-id="${x.id}" data-name="${esc(x.holder_name)}" data-date="${x.issue_date}" data-note="${esc(x.note||'')}" data-type="${x.type_id||''}"><i class="fa fa-pencil"></i></button>
        ${x.status==='active'?`<button class="btn btn-warning btn-xs rev-btn" data-id="${x.id}" data-name="${esc(x.holder_name)}"><i class="fa fa-ban"></i> 停用</button>`:''}
        <button class="btn btn-danger btn-xs del-btn" data-id="${x.id}" data-name="${esc(x.holder_name)}"><i class="fa fa-trash"></i></button>`:'<span class="text-muted">—</span>'}
      </td></tr>`;}).join('')||'<tr><td colspan="9" class="text-muted">尚無登記資料。</td></tr>');
    renderPager();
  });
}
function renderPager(){
  const pages=Math.max(1,Math.ceil(total/per)); if(page>pages)page=pages;
  let h='';
  h+=`<button class="btn btn-default btn-xs pg-btn" data-p="${page-1}" ${page<=1?'disabled':''}>‹</button> `;
  for(let i=Math.max(1,page-2);i<=Math.min(pages,page+2);i++)
    h+=`<button class="btn ${i===page?'btn-primary':'btn-default'} btn-xs pg-btn" data-p="${i}">${i}</button> `;
  h+=`<button class="btn btn-default btn-xs pg-btn" data-p="${page+1}" ${page>=pages?'disabled':''}>›</button>`;
  $('#pageBtns').html(h);
}
$('#pageBtns').on('click','.pg-btn',function(){ loadList(+$(this).data('p')); });
$('#perSel').on('change',function(){ per=+this.value; loadList(1); });
$('#fltName').on('input',function(){ clearTimeout(this._t); this._t=setTimeout(()=>loadList(1),400); });
$('#fltStatus,#fltType').on('change',()=>loadList(1));

// ── 新增/編輯/停用/刪除 ──
$('#btnAdd').on('click',function(){
  const isDept=$('#addKind').val()==='dept';
  const hid=isDept?$('#addDept').val():$('#addUser').val();
  if(!hid){alert(isDept?'請選擇部門':'請選擇人員');return;}
  const p={issue_date:$('#addDate').val(),type_id:$('#addType').val()||'',note:$('#addNote').val().trim()};
  if(isDept) p.dept_id=hid; else p.user_id=hid;
  $.post(API+'?action=add',p,r=>{
    if(!r.ok){alert(r.error||'登記失敗');return;}
    $('#addUser').val(''); $('#addDept').val(''); $('#addNote').val(''); $('#addDate').val(today()); loadList(1);
  },'json');
});
$('#regBody').on('click','.edit-btn',function(){
  curEditId=$(this).data('id');
  $('#editUserName').text($(this).data('name'));
  $('#editType').html(typeOpts($(this).data('type'),false));
  $('#editDate').val($(this).data('date')); $('#editNote').val($(this).data('note'));
  $('#editModal').modal('show');
});
$('#btnEditSave').on('click',function(){
  $.post(API+'?action=update',{id:curEditId,type_id:$('#editType').val()||'',issue_date:$('#editDate').val(),note:$('#editNote').val().trim()},r=>{
    if(!r.ok){alert(r.error||'儲存失敗');return;}
    $('#editModal').modal('hide'); loadList();
  },'json');
});

// ── 種類管理 ──
function renderTypeMng(){
  $('#typeBody').html(TYPES.map(t=>`<tr>
    <td><input type="text" class="form-control input-sm type-name" data-id="${t.id}" value="${esc(t.type_name)}"></td>
    <td style="text-align:center;">${+t.is_active?'<span style="color:#3c763d;">啟用</span>':'<span class="text-muted">已停用</span>'}</td>
    <td>
      <button class="btn btn-default btn-xs type-save" data-id="${t.id}"><i class="fa fa-check"></i> 存</button>
      <button class="btn btn-warning btn-xs type-toggle" data-id="${t.id}">${+t.is_active?'停用':'啟用'}</button>
      <button class="btn btn-danger btn-xs type-del" data-id="${t.id}"><i class="fa fa-trash"></i></button>
    </td></tr>`).join('')||'<tr><td colspan="3" class="text-muted">尚無種類，請於下方新增。</td></tr>');
}
function reloadTypes(cb){
  $.getJSON(API+'?action=meta',m=>{
    if(!m.ok) return;
    TYPES=m.types||[];
    $('#fltType').html(typeOpts($('#fltType').val(),true));
    $('#addType').html(typeOpts($('#addType').val(),false));
    renderTypeMng();
    if(cb)cb();
  });
}
$('#btnTypeAdd').on('click',function(){
  const name=$('#newTypeName').val().trim();
  if(!name){alert('請輸入種類名稱');return;}
  $.post(API+'?action=type_save',{id:0,type_name:name},r=>{
    if(!r.ok){alert(r.error||'新增失敗');return;}
    $('#newTypeName').val(''); reloadTypes();
  },'json');
});
$('#typeBody').on('click','.type-save',function(){
  const id=$(this).data('id'), name=$('.type-name[data-id="'+id+'"]').val().trim();
  if(!name){alert('請輸入種類名稱');return;}
  $.post(API+'?action=type_save',{id,type_name:name},r=>{
    if(!r.ok){alert(r.error||'儲存失敗');return;}
    reloadTypes(()=>loadList());
  },'json');
});
$('#typeBody').on('click','.type-toggle',function(){
  $.post(API+'?action=type_toggle',{id:$(this).data('id')},r=>{
    if(!r.ok){alert(r.error||'切換失敗');return;}
    reloadTypes();
  },'json');
});
$('#typeBody').on('click','.type-del',function(){
  if(!confirm('確定刪除此種類？（已被登記使用的種類無法刪除，可改停用）')) return;
  $.post(API+'?action=type_delete',{id:$(this).data('id')},r=>{
    if(!r.ok){alert(r.error||'刪除失敗');return;}
    reloadTypes();
  },'json');
});
let curRevId=0;
$('#regBody').on('click','.rev-btn',function(){
  curRevId=$(this).data('id');
  $('#revUserName').text($(this).data('name'));
  $('#revDate').val(today());
  $('#revModal').modal('show');
});
$('#btnRevSave').on('click',function(){
  const d=$('#revDate').val();
  if(!/^\d{4}-\d{2}-\d{2}$/.test(d)){alert('請選擇停用/繳回日期');return;}
  $.post(API+'?action=revoke',{id:curRevId,revoke_date:d},r=>{
    if(!r.ok){alert(r.error||'停用失敗');return;}
    $('#revModal').modal('hide'); loadList();
  },'json');
});
$('#regBody').on('click','.del-btn',function(){
  const id=$(this).data('id'), name=$(this).data('name');
  if(!confirm(`確定刪除「${name}」這筆登記？（僅供誤登記時使用，刪除不可復原）`)) return;
  $.post(API+'?action=delete',{id},r=>{ if(!r.ok){alert(r.error||'刪除失敗');return;} loadList(); },'json');
});

// ── CSV / 列印 ──
$('#btnCsv').on('click',function(){
  location.href=API+'?action=csv&q='+encodeURIComponent($('#fltName').val().trim())+'&status='+encodeURIComponent($('#fltStatus').val())+'&type_id='+encodeURIComponent($('#fltType').val()||'');
});
$('#btnPrint').on('click',function(){
  const q=$('#fltName').val().trim(), st=$('#fltStatus').val(), tid=$('#fltType').val()||'';
  $.getJSON(API,{action:'list',q,status:st,type_id:tid,all:1},r=>{
    if(!r.ok){alert(r.error||'載入失敗');return;}
    const rows=r.rows.map(x=>`<tr>
      <td style="text-align:center;">${EGStamp.stamp(x.holder_name,dot(x.issue_date),false)}</td>
      <td>${esc(x.holder_name)}${x.dept_id?'（部門章）':''}</td>
      <td>${x.type_name?esc(x.type_name):'（未分類）'}</td>
      <td>${esc(x.issue_date||'')}</td><td>${esc(x.revoke_date||'—')}</td>
      <td>${x.status==='active'?'使用中':'已停用'}</td><td>${esc(x.note||'')}</td>
      <td style="text-align:center;">${+x.has_asset?'已上傳':'—'}</td></tr>`).join('');
    // 單一表格交給瀏覽器原生分頁（列印分頁鐵則，禁止 JS 量高度自算）
    const w=window.open('','_blank');
    w.document.write(`<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="utf-8"><title>圖章清冊</title><style>
      body{font-family:"Microsoft JhengHei",sans-serif;color:#3a2a17;font-size:12px;}
      h3{margin:0 0 4px;} .sub{color:#8a7455;font-size:11px;margin-bottom:8px;}
      table{width:100%;border-collapse:collapse;} th,td{border:1px solid #b09468;padding:4px 6px;}
      th{background:#f7e0bd;} thead{display:table-header-group;} tr{page-break-inside:avoid;}
      svg{width:56px;height:56px;}
    </style></head><body>
      <h3>圖章清冊</h3><div class="sub">使用中 ${r.summary.active} 顆／已停用 ${r.summary.revoked} 顆，共 ${r.total} 筆　列印日期：${today()}</div>
      <table><thead><tr><th style="width:70px;">印模</th><th>持有對象</th><th>種類</th><th>核發日期</th><th>停用/繳回日</th><th>狀態</th><th>備註</th><th>掃描章</th></tr></thead>
      <tbody>${rows}</tbody></table></body></html>`);
    w.document.close();
    setTimeout(()=>{w.print();},400);   // 等掃描章圖載入
  });
});

// ── 掃描章 Modal ──
$('#regBody').on('click','.scan-btn',function(){
  curScanUid=$(this).data('uid'); curScanName=$(this).data('name');
  $('#scanUserName').text(curScanName);
  $('#scanFile').val('');
  refreshScanUI();
  $('#scanModal').modal('show');
});
function refreshScanUI(){
  // 重新抓對照表確認此人是否已有掃描章
  $.getJSON(API+'?action=asset_map',r=>{
    curAsset=(r.ok&&r.map&&r.map[curScanName])?r.map[curScanName]:null;
    if(curAsset&&curAsset.uid!==curScanUid) curAsset=null;   // 同名不同人保護
    if(curAsset){
      EGStamp.setAssets(r.map);
      $('#btnAssetDel').show(); $('#bandArea').show();
      $('#bandImg').attr('src',API+'?action=asset_img&user_id='+curScanUid+'&t='+curAsset.t);
      setBand(curAsset.top,curAsset.bot);
    }else{
      $('#btnAssetDel').hide(); $('#bandArea').hide();
    }
  });
}
$('#btnUpload').on('click',function(){
  const f=$('#scanFile')[0].files[0];
  if(!f){alert('請先選擇掃描圖檔');return;}
  const fd=new FormData();
  fd.append('user_id',curScanUid); fd.append('file',f);
  $(this).prop('disabled',true).text('處理中…');
  $.ajax({url:API+'?action=asset_upload',method:'POST',data:fd,processData:false,contentType:false,dataType:'json'})
    .done(r=>{ if(!r.ok){alert(r.error||'上傳失敗');return;} refreshScanUI(); loadList(); })
    .fail(x=>alert((x.responseJSON&&x.responseJSON.error)||'上傳失敗'))
    .always(()=>$('#btnUpload').prop('disabled',false).html('<i class="fa fa-upload"></i> 上傳並去背'));
});
$('#btnAssetDel').on('click',function(){
  if(!confirm(`確定刪除「${curScanName}」的掃描章？簽核顯示將回退為電子章（純SVG）。`)) return;
  $.post(API+'?action=asset_delete',{user_id:curScanUid},r=>{
    if(!r.ok){alert(r.error||'刪除失敗');return;}
    refreshScanUI(); loadList();
  },'json');
});

// ── 日期帶拖拉框選 ──
let bandTop=32, bandBot=66, dragLine=null;
function setBand(t,b){
  bandTop=+t; bandBot=+b;
  $('#lineTop').css('top',bandTop+'%'); $('#lineBot').css('top',bandBot+'%');
  $('#bandShade').css({top:bandTop+'%',height:(bandBot-bandTop)+'%'});
  $('#pctTop').text(bandTop.toFixed(1)); $('#pctBot').text(bandBot.toFixed(1));
  $('#scanPreview').html(EGStamp.scan(curScanName, dot(today()), false, {uid:curScanUid, top:bandTop, bot:bandBot, t:(curAsset?curAsset.t:0)}));
  $('#scanPreview svg').attr({width:120,height:120});
}
$('#lineTop,#lineBot').on('mousedown',function(e){ dragLine=this.id; e.preventDefault(); });
$(document).on('mousemove',function(e){
  if(!dragLine) return;
  const box=$('#bandBox')[0].getBoundingClientRect();
  let pct=Math.max(0,Math.min(100,(e.clientY-box.top)/box.height*100));
  if(dragLine==='lineTop') setBand(Math.min(pct,bandBot-4),bandBot);
  else setBand(bandTop,Math.max(pct,bandTop+4));
}).on('mouseup',function(){ dragLine=null; });
$('#btnBandSave').on('click',function(){
  $.post(API+'?action=asset_band',{user_id:curScanUid,band_top:bandTop.toFixed(2),band_bottom:bandBot.toFixed(2)},r=>{
    if(!r.ok){alert(r.error||'儲存失敗');return;}
    alert('已儲存日期帶位置'); refreshScanUI(); loadList();
  },'json');
});

loadMeta();
</script>
</body>
</html>
