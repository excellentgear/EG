<?php
// AS 線上表單 — 入口頁（模板清單 / 填寫紀錄 / 單一表單授權）
// 測試動線：建立表單 → 設計 → 發布 → 新填 → 簽核。
session_start();
if (!isset($_SESSION['userName'])) { header("Location:../../index.php"); exit; }
include_once '../../src/common/_config.php';
include ("../../src/common/DBConnection.php");
$db = (new DBConnection())->getPDO();
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>線上表單清單 | AS9100</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
  html{overflow-x:hidden;}   /* 只設 html：html+body 同設會讓 body 變第二個捲動容器 */
  .right_col{background:#efe7da;font-family:"Microsoft JhengHei","微軟正黑體",Arial,sans-serif;color:#3a2a17;padding:16px;min-height:100vh;}
  .panel-warm{max-width:1000px;margin:0 auto 16px;background:#fff;border:1px solid #d8c19a;border-radius:6px;padding:14px 18px;box-shadow:0 2px 8px rgba(90,61,30,.12);}
  .panel-warm h4{margin:0 0 10px;font-size:15px;color:#7a4e17;border-bottom:2px solid #f0a24b;padding-bottom:6px;}
  table.list{width:100%;border-collapse:collapse;font-size:13px;}
  table.list th{background:#f7e0bd;color:#5a3d1e;padding:6px 8px;border:1px solid #e0cba0;}
  table.list td{padding:5px 8px;border:1px solid #e8d9b8;vertical-align:middle;}
  .status-chip{display:inline-block;padding:1px 8px;border-radius:9px;font-size:11px;font-weight:bold;}
  .st-draft{background:#f7e0bd;color:#5a3d1e;} .st-published{background:#e8dcc3;color:#4a3a20;}
  .st-in_review{background:#f0a24b;color:#4a2c0a;} .st-approved{background:#e8dcc3;color:#4a3a20;}
  .st-rejected{background:#dd5138;color:#fff;}
</style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html' ?>
<div class="right_col" role="main">
<div class="panel-warm">
  <h4><i class="fa fa-file-text-o"></i> 線上表單模板
    <span style="float:right;">
      <input type="text" id="newName" class="form-control input-sm" placeholder="新表單名稱" style="width:180px;display:inline-block;">
      <select id="newBindDoc" class="form-control input-sm" style="width:230px;display:inline-block;" title="綁定四階表單文件（表尾自動顯示其編號＋版次）"><option value="">綁定文件編號（選填）</option></select>
      <button class="btn btn-primary btn-sm" id="btnCreate"><i class="fa fa-plus"></i> 建立表單</button>
    </span>
  </h4>
  <p class="text-muted" style="font-size:12px;margin:0 0 8px;">測試動線：建立表單 → <strong>設計</strong>（設定欄位/簽核區）→ 設計器按<strong>發布</strong> → 回此清單按 <strong>新填一張</strong>（或設計器的「填寫測試」）→ 填寫 → 送出簽核 → 簽核人由通知或「紀錄」開啟簽核。</p>
  <table class="list"><thead><tr>
    <th style="width:50px;">ID</th><th>表單名稱</th><th style="width:120px;">文件編號</th><th style="width:90px;">狀態</th><th style="width:70px;">發布版</th>
    <th style="width:130px;">最後更新</th><th style="width:380px;">操作</th>
  </tr></thead><tbody id="tplBody"></tbody></table>
</div>

<div class="panel-warm" id="recPanel" style="display:none;">
  <h4><i class="fa fa-files-o"></i> 填寫紀錄：<span id="recTplName"></span></h4>
  <table class="list"><thead><tr>
    <th style="width:50px;">ID</th><th>標題</th><th style="width:90px;">狀態</th><th style="width:100px;">填表人</th>
    <th style="width:130px;">建立時間</th><th style="width:100px;">操作</th>
  </tr></thead><tbody id="recBody"></tbody></table>
</div>

<!-- 綁定文件編號 Modal -->
<div class="modal fade" id="bindModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title">綁定文件編號：<span id="bindTplName"></span></h4></div>
  <div class="modal-body">
    <p class="text-muted" style="font-size:12px;">綁定四階表單文件後，表單表尾會自動顯示該文件的編號＋版次（文件改版自動連動）。</p>
    <select id="bindDocSel" class="form-control input-sm"></select>
    <div style="margin-top:10px;text-align:right;">
      <button class="btn btn-primary btn-sm" id="btnBindSave"><i class="fa fa-check"></i> 儲存綁定</button>
    </div>
  </div>
</div></div></div>

<!-- 授權 Modal -->
<div class="modal fade" id="grantModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title">單一表單授權：<span id="grantTplName"></span></h4></div>
  <div class="modal-body">
    <p class="text-muted" style="font-size:12px;">授權後，該人員可設計/編輯<strong>此張表單</strong>（僅限此表單）。僅能授權給同部門人員。</p>
    <div id="grantAddRow" style="margin-bottom:10px;">
      <select id="grantMember" class="form-control input-sm" style="width:200px;display:inline-block;"></select>
      <button class="btn btn-primary btn-sm" id="btnGrantAdd"><i class="fa fa-plus"></i> 授權</button>
    </div>
    <table class="list"><thead><tr><th>被授權人</th><th>授權人</th><th>授權時間</th><th style="width:120px;">狀態/操作</th></tr></thead>
      <tbody id="grantBody"></tbody></table>
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
<script>
const API='../../src/store/AS_Form_API.php';
let canBuild=false, curGrantTpl=0, FORM_DOCS=[];
function esc(s){return $('<div>').text(s==null?'':s).html();}
function chip(st){const lb={draft:'草稿',published:'已發布',in_review:'簽核中',approved:'已完成',rejected:'已駁回',void:'作廢'}[st]||st;return `<span class="status-chip st-${st}">${lb}</span>`;}

function loadMeta(){
  $.getJSON(API+'?action=meta',m=>{
    if(!m.ok) return;
    FORM_DOCS=m.form_docs||[];
    const opts=FORM_DOCS.map(d=>`<option value="${d.id}">${esc(d.doc_no)}｜${esc(d.doc_name)}</option>`).join('');
    $('#newBindDoc').append(opts);
    $('#bindDocSel').html('<option value="">（不綁定）</option>'+opts);
  });
}

function loadTpl(){
  $.getJSON(API+'?action=list',r=>{
    if(!r.ok){alert(r.error||'載入失敗');return;}
    canBuild=r.canBuild;
    if(!canBuild) $('#newName,#newBindDoc,#btnCreate').hide();
    $('#tplBody').html(r.rows.map(t=>`<tr>
      <td>${t.id}</td><td>${esc(t.name)}</td>
      <td>${t.doc_no?esc(t.doc_no)+esc(t.doc_version||''):'<span class="text-muted">未綁定</span>'}</td>
      <td>${chip(t.status)}</td>
      <td style="text-align:center;">${t.published_version||'—'}</td>
      <td>${esc((t.updated_at||'').substring(0,16))}</td>
      <td>
        <a class="btn btn-default btn-xs" href="as_form_designer.php?template_id=${t.id}"><i class="fa fa-th"></i> 設計</a>
        ${t.published_version>0?`<a class="btn btn-success btn-xs" href="as_form_fill.php?template_id=${t.id}"><i class="fa fa-pencil"></i> 新填一張</a>`:''}
        <button class="btn btn-info btn-xs rec-btn" data-id="${t.id}" data-name="${esc(t.name)}"><i class="fa fa-files-o"></i> 紀錄</button>
        ${canBuild?`<button class="btn btn-default btn-xs bind-btn" data-id="${t.id}" data-name="${esc(t.name)}" data-fdid="${t.form_doc_id||''}" title="綁定/改綁四階表單文件（表尾自動顯示其編號＋版次）"><i class="fa fa-link"></i> 綁定</button>`:''}
        ${canBuild?`<button class="btn btn-warning btn-xs grant-btn" data-id="${t.id}" data-name="${esc(t.name)}"><i class="fa fa-user-plus"></i> 授權</button>`:''}
        ${canBuild?`<button class="btn btn-danger btn-xs del-btn" data-id="${t.id}" data-name="${esc(t.name)}"><i class="fa fa-trash"></i></button>`:''}
      </td></tr>`).join('')||'<tr><td colspan="7" class="text-muted">尚無表單，請先建立。</td></tr>');
  });
}

// ── 刪除模板（軟刪除；既有填寫紀錄保留）──
$('#tplBody').on('click','.del-btn',function(){
  const tid=$(this).data('id'), name=$(this).data('name');
  if(!confirm('確定刪除表單模板「'+name+'」？\n\n已填寫的紀錄會保留可查，但此模板將從清單移除、不可再新填。')) return;
  $.post(API+'?action=template_delete',{template_id:tid},r=>{
    if(!r.ok){alert(r.error||'刪除失敗');return;}
    loadTpl();
  },'json');
});

$('#btnCreate').on('click',function(){
  const name=$('#newName').val().trim();
  if(!name){alert('請輸入表單名稱');return;}
  $.post(API+'?action=create',{name, form_doc_id:$('#newBindDoc').val()||''},r=>{
    if(!r.ok){alert(r.error||'建立失敗');return;}
    location.href='as_form_designer.php?template_id='+r.template_id;
  },'json');
});

// ── 綁定文件編號 ──
$('#tplBody').on('click','.bind-btn',function(){
  curBindTpl=$(this).data('id');
  $('#bindTplName').text($(this).data('name'));
  $('#bindDocSel').val(String($(this).data('fdid')||''));
  $('#bindModal').modal('show');
});
let curBindTpl=0;
$('#btnBindSave').on('click',function(){
  $.post(API+'?action=bind_doc',{template_id:curBindTpl, form_doc_id:$('#bindDocSel').val()||0},r=>{
    if(!r.ok){alert(r.error||'綁定失敗');return;}
    $('#bindModal').modal('hide'); loadTpl();
  },'json');
});

$('#tplBody').on('click','.rec-btn',function(){
  const tid=$(this).data('id');
  $('#recTplName').text($(this).data('name'));
  $.getJSON(API+'?action=instance_list&template_id='+tid,r=>{
    if(!r.ok){alert(r.error||'載入失敗');return;}
    $('#recBody').html(r.rows.map(i=>`<tr>
      <td>${i.id}</td><td>${esc(i.title||'（未命名）')}</td><td>${chip(i.status)}</td>
      <td>${esc(i.created_by)}</td><td>${esc((i.created_at||'').substring(0,16))}</td>
      <td><a class="btn btn-default btn-xs" href="as_form_fill.php?instance_id=${i.id}"><i class="fa fa-eye"></i> 開啟</a></td>
    </tr>`).join('')||'<tr><td colspan="6" class="text-muted">尚無填寫紀錄。</td></tr>');
    $('#recPanel').show();
    $('html,body').animate({scrollTop:$('#recPanel').offset().top-10},200);
  });
});

// ── 授權 ──
$('#tplBody').on('click','.grant-btn',function(){
  curGrantTpl=$(this).data('id');
  $('#grantTplName').text($(this).data('name'));
  loadGrants();
  $('#grantModal').modal('show');
});
function loadGrants(){
  $.getJSON(API+'?action=grant_list&template_id='+curGrantTpl,r=>{
    if(!r.ok){alert(r.error||'載入失敗');return;}
    $('#grantMember').html(r.members.map(m=>`<option value="${m.id}">${esc(m.user_cname)}</option>`).join('')||'<option value="">（同部門無其他人員）</option>');
    $('#grantBody').html(r.rows.map(g=>`<tr>
      <td>${esc(g.grantee_name||g.grantee_id)}</td>
      <td>${esc(g.granted_by_name||'')}</td>
      <td>${esc((g.granted_at||'').substring(0,16))}</td>
      <td>${g.revoked_at?`<span class="text-muted">已於 ${esc(g.revoked_at.substring(0,10))} 撤銷</span>`
          :`<span style="color:#3c763d;">生效中</span> <button class="btn btn-danger btn-xs g-rev" data-gid="${g.id}">撤銷</button>`}</td>
    </tr>`).join('')||'<tr><td colspan="4" class="text-muted">尚無授權紀錄。</td></tr>');
  });
}
$('#btnGrantAdd').on('click',function(){
  const gid=$('#grantMember').val();
  if(!gid){alert('無可授權對象');return;}
  $.post(API+'?action=grant_add',{template_id:curGrantTpl,grantee_id:gid},r=>{
    if(!r.ok){alert(r.error||'授權失敗');return;}
    loadGrants();
  },'json');
});
$('#grantBody').on('click','.g-rev',function(){
  if(!confirm('確定撤銷此授權？')) return;
  $.post(API+'?action=grant_revoke',{grant_id:$(this).data('gid')},r=>{
    if(!r.ok){alert(r.error||'撤銷失敗');return;}
    loadGrants();
  },'json');
});

loadMeta();
loadTpl();
</script>
</body>
</html>
