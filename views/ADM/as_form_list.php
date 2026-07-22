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
<style>
  body{background:#efe7da;font-family:"Microsoft JhengHei","微軟正黑體",Arial,sans-serif;color:#3a2a17;padding:16px;}
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
<body>
<div class="panel-warm">
  <h4><i class="fa fa-file-text-o"></i> 線上表單模板
    <span style="float:right;">
      <input type="text" id="newName" class="form-control input-sm" placeholder="新表單名稱" style="width:220px;display:inline-block;">
      <button class="btn btn-primary btn-sm" id="btnCreate"><i class="fa fa-plus"></i> 建立表單</button>
    </span>
  </h4>
  <table class="list"><thead><tr>
    <th style="width:50px;">ID</th><th>表單名稱</th><th style="width:90px;">狀態</th><th style="width:70px;">發布版</th>
    <th style="width:130px;">最後更新</th><th style="width:330px;">操作</th>
  </tr></thead><tbody id="tplBody"></tbody></table>
</div>

<div class="panel-warm" id="recPanel" style="display:none;">
  <h4><i class="fa fa-files-o"></i> 填寫紀錄：<span id="recTplName"></span></h4>
  <table class="list"><thead><tr>
    <th style="width:50px;">ID</th><th>標題</th><th style="width:90px;">狀態</th><th style="width:100px;">填表人</th>
    <th style="width:130px;">建立時間</th><th style="width:100px;">操作</th>
  </tr></thead><tbody id="recBody"></tbody></table>
</div>

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

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script>
const API='../../src/store/AS_Form_API.php';
let canBuild=false, curGrantTpl=0;
function esc(s){return $('<div>').text(s==null?'':s).html();}
function chip(st){const lb={draft:'草稿',published:'已發布',in_review:'簽核中',approved:'已完成',rejected:'已駁回',void:'作廢'}[st]||st;return `<span class="status-chip st-${st}">${lb}</span>`;}

function loadTpl(){
  $.getJSON(API+'?action=list',r=>{
    if(!r.ok){alert(r.error||'載入失敗');return;}
    canBuild=r.canBuild;
    if(!canBuild) $('#newName,#btnCreate').hide();
    $('#tplBody').html(r.rows.map(t=>`<tr>
      <td>${t.id}</td><td>${esc(t.name)}</td><td>${chip(t.status)}</td>
      <td style="text-align:center;">${t.published_version||'—'}</td>
      <td>${esc((t.updated_at||'').substring(0,16))}</td>
      <td>
        <a class="btn btn-default btn-xs" href="as_form_designer.php?template_id=${t.id}"><i class="fa fa-th"></i> 設計</a>
        ${t.published_version>0?`<a class="btn btn-success btn-xs" href="as_form_fill.php?template_id=${t.id}"><i class="fa fa-pencil"></i> 新填一張</a>`:''}
        <button class="btn btn-info btn-xs rec-btn" data-id="${t.id}" data-name="${esc(t.name)}"><i class="fa fa-files-o"></i> 紀錄</button>
        ${canBuild?`<button class="btn btn-warning btn-xs grant-btn" data-id="${t.id}" data-name="${esc(t.name)}"><i class="fa fa-user-plus"></i> 授權</button>`:''}
      </td></tr>`).join('')||'<tr><td colspan="6" class="text-muted">尚無表單，請先建立。</td></tr>');
  });
}

$('#btnCreate').on('click',function(){
  const name=$('#newName').val().trim();
  if(!name){alert('請輸入表單名稱');return;}
  $.post(API+'?action=create',{name},r=>{
    if(!r.ok){alert(r.error||'建立失敗');return;}
    location.href='as_form_designer.php?template_id='+r.template_id;
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

loadTpl();
</script>
</body>
</html>
