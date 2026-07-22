<?php
session_start();
if (!isset($_SESSION['userName'])) { header("Location:../../index.php"); exit; }

include_once '../../src/common/_config.php';
include ("../../src/common/DBConnection.php");
$db_connection = new DBConnection();
$conn = $db_connection->getPDO();

$stmt = $conn->prepare("SELECT id FROM user WHERE user_uname = ?");
$stmt->execute([$_SESSION['userName']]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$currentUser) { header("Location:../../index.php"); exit; }
$id = $currentUser['id'];

// 權限：沿用 as_doc 模組角色 + 頁面 ACRUD（同 as_document_management.php 慣例，簡化版）
$current_script_path = $_SERVER['PHP_SELF'];
$deptPerm = '';
try {
    $st = $conn->prepare("SELECT page_id, group_id FROM system_module_pages WHERE page_url LIKE '%views/ADM/as_document_management.php' LIMIT 1");
    $st->execute();
    if ($pg = $st->fetch(PDO::FETCH_ASSOC)) {
        $st = $conn->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND scope='page' AND module_code=?");
        $st->execute([$id, $pg['page_id']]);
        $perms = $st->fetchAll(PDO::FETCH_COLUMN);
        if (empty($perms) && !empty($pg['group_id'])) {
            $st = $conn->prepare("SELECT module_code FROM system_modules WHERE group_id=? LIMIT 1");
            $st->execute([$pg['group_id']]);
            if ($gCode = $st->fetchColumn()) {
                $st = $conn->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND scope='group' AND module_code=?");
                $st->execute([$id, $gCode]);
                $perms = $st->fetchAll(PDO::FETCH_COLUMN);
            }
        }
        $chars=[]; foreach ($perms as $p) $chars=array_merge($chars,str_split($p));
        $deptPerm = implode('', array_unique($chars));
    }
} catch (Exception $e) {}
include_once '../../src/common/role_features_helper.php';
$asFeatures    = rf_load_user_features_override($conn, $id, 'as_doc');
$asIsRoleAdmin = in_array('all', $asFeatures, true);
$canView = $asIsRoleAdmin || strpos($deptPerm,'A')!==false || strpos($deptPerm,'R')!==false || in_array('asdoc_view',$asFeatures,true);
if (!$canView) { header("Location:../../src/store/Login.php?msg=".urlencode("無權限檢視頁面")); exit; }
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>AS 程序書線上編輯 | Excellentgear</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
:root{--amber:#d99a4e;--amber-d:#b06f27;--sand:#faf3e7;--ink:#3a2c1a;}
.od-wrap{display:flex;gap:14px;padding:0 14px 30px;}
.od-list{flex:0 0 300px;max-height:calc(100vh - 150px);overflow:auto;border:1px solid #e6d8c3;border-radius:6px;background:#fffdf9;}
.od-list h5{margin:0;padding:8px 10px;background:var(--sand);border-bottom:1px solid #e6d8c3;font-size:13px;color:var(--amber-d);}
.od-item{padding:7px 10px;border-bottom:1px solid #f0e7d7;cursor:pointer;font-size:13px;}
.od-item:hover{background:#fdf6ea;}
.od-item.active{background:#f6e3c8;border-left:3px solid var(--amber);}
.od-item .no{color:#9a7b4f;font-size:11px;}
.od-badge{font-size:10px;padding:1px 5px;border-radius:8px;margin-left:4px;}
.b-draft{background:#f0a24b;color:#fff;} .b-pub{background:#8bbf7a;color:#fff;} .b-none{background:#e0d4bf;color:#6b5638;} .b-lock{background:#dd5138;color:#fff;}
.od-main{flex:1;min-width:0;}
.od-toolbar{position:sticky;top:0;z-index:20;background:#fff8ee;border:1px solid #e6d8c3;border-radius:6px;padding:6px 8px;display:flex;flex-wrap:wrap;gap:5px;align-items:center;margin-bottom:10px;}
.od-toolbar button{border:1px solid #d8c3a0;background:#fff;border-radius:4px;padding:3px 8px;font-size:12px;cursor:pointer;color:var(--ink);}
.od-toolbar button:hover{background:#f6e3c8;}
.od-toolbar .sep{width:1px;height:20px;background:#e0d0b5;margin:0 3px;}
.od-cover{border:1px solid var(--amber);background:var(--sand);border-radius:6px;padding:10px 14px;margin-bottom:12px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;}
.od-cover .t{font-size:16px;font-weight:700;color:var(--ink);}
.od-cover .m{font-size:12px;color:#7a5f38;}
.od-sec{border:1px solid #e6d8c3;border-radius:6px;margin-bottom:12px;background:#fff;}
.od-sec-hd{display:flex;align-items:center;gap:6px;padding:5px 8px;background:var(--sand);border-bottom:1px solid #e6d8c3;border-radius:6px 6px 0 0;}
.od-sec-hd .idx{font-weight:700;color:var(--amber-d);}
.od-sec-hd input.tt{border:1px solid transparent;background:transparent;font-weight:700;color:var(--ink);flex:1;padding:2px 4px;border-radius:4px;}
.od-sec-hd input.tt:focus{border-color:var(--amber);background:#fff;outline:none;}
.od-sec-hd .mini{border:none;background:transparent;color:#a07b48;cursor:pointer;padding:2px 5px;font-size:13px;}
.od-sec-hd .mini:hover{color:var(--amber-d);}
.od-edit{min-height:60px;padding:8px 12px;font-size:14px;line-height:1.7;color:var(--ink);outline:none;}
.od-edit:focus{background:#fffdf7;}
.od-edit table{border-collapse:collapse;} .od-edit td,.od-edit th{border:1px solid #c9a978;padding:3px 6px;min-width:40px;}
.od-empty{color:#b9a display;color:#c3b291;}
.od-actbar{position:sticky;bottom:0;background:#fff8ee;border:1px solid #e6d8c3;border-radius:6px;padding:8px 10px;display:flex;gap:8px;align-items:center;margin-top:6px;}
.od-actbar .spacer{flex:1;}
.readonly-note{background:#fdf0dc;border:1px solid #e9c98f;color:#8a5a1a;padding:5px 10px;border-radius:5px;font-size:12px;}
.od-side{position:fixed;top:0;right:0;width:420px;max-width:92vw;height:100vh;background:#fff;border-left:2px solid var(--amber);box-shadow:-3px 0 12px rgba(0,0,0,.15);z-index:1050;transform:translateX(100%);transition:transform .2s;display:flex;flex-direction:column;}
.od-side.open{transform:translateX(0);}
.od-side h5{margin:0;padding:10px 12px;background:var(--sand);border-bottom:1px solid #e6d8c3;color:var(--amber-d);display:flex;justify-content:space-between;}
.od-side .body{flex:1;overflow:auto;padding:12px;font-size:13px;line-height:1.7;color:var(--ink);}
.btn-amber{background:var(--amber);border-color:var(--amber-d);color:#fff;}
.btn-amber:hover{background:var(--amber-d);color:#fff;}
.spin{display:none;}
</style>
</head>
<body class="nav-sm">
<div class="container body"><div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html' ?>
<div class="right_col" role="main">
  <div class="page-title"><div class="title_left"><h3>AS 程序書 線上編輯 <small>結構化段落・自動版本建議（試作）</small></h3></div></div>
  <div class="clearfix"></div>

  <div class="od-wrap">
    <!-- 文件清單 -->
    <div class="od-list">
      <h5><i class="fa fa-folder-open-o"></i> 程序書 / 手冊</h5>
      <div id="odList"><div style="padding:14px;color:#999;">載入中…</div></div>
    </div>

    <!-- 編輯區 -->
    <div class="od-main">
      <div id="odIdle" style="padding:40px;text-align:center;color:#b09a78;">
        <i class="fa fa-file-text-o" style="font-size:40px;"></i>
        <p style="margin-top:10px;">請由左側選一份文件開始</p>
        <p style="font-size:12px;">舊上傳檔案不受影響；此處為結構化線上內容，發布後才成為新版本。</p>
      </div>

      <div id="odEditor" style="display:none;">
        <div class="od-cover">
          <div><div class="t" id="cvName">—</div><div class="m" id="cvNo">—</div></div>
          <div style="text-align:right;">
            <div class="m">目前版本：<b id="cvVer">—</b></div>
            <div class="m" id="cvStatus">—</div>
          </div>
        </div>

        <div id="roNote" class="readonly-note" style="display:none;"><i class="fa fa-lock"></i> <span id="roMsg">唯讀檢視中——按「啟用編輯」取得編輯權。</span></div>

        <div class="od-toolbar" id="odToolbar" style="display:none;">
          <button data-cmd="bold" title="粗體"><b>B</b></button>
          <button data-cmd="italic" title="斜體"><i>I</i></button>
          <button data-cmd="underline" title="底線"><u>U</u></button>
          <span class="sep"></span>
          <button data-cmd="insertUnorderedList" title="項目符號"><i class="fa fa-list-ul"></i></button>
          <button data-cmd="insertOrderedList" title="編號清單"><i class="fa fa-list-ol"></i></button>
          <button data-block="h4" title="小標題">H</button>
          <button data-block="p" title="內文">¶</button>
          <span class="sep"></span>
          <button id="tbTable" title="插入表格"><i class="fa fa-table"></i> 表格</button>
          <button id="tbRow" title="表格加一列">＋列</button>
          <button id="tbCol" title="表格加一欄">＋欄</button>
          <button data-cmd="removeFormat" title="清除格式"><i class="fa fa-eraser"></i></button>
        </div>

        <div id="odSections"></div>

        <div id="addSecWrap" style="display:none;text-align:center;margin:8px 0;">
          <button class="btn btn-sm btn-default" id="btnAddSec"><i class="fa fa-plus"></i> 新增段落</button>
        </div>

        <div class="od-actbar">
          <button class="btn btn-sm btn-default" id="btnEnable"><i class="fa fa-pencil"></i> 啟用編輯</button>
          <button class="btn btn-sm btn-amber" id="btnSave" style="display:none;"><i class="fa fa-save"></i> 存草稿</button>
          <button class="btn btn-sm btn-default" id="btnPrefill" style="display:none;" title="用 PhpWord 抽出目前版本 Word 原檔內文，供分段整理"><i class="fa fa-magic"></i> 抽舊檔內文</button>
          <span id="saveHint" class="m" style="color:#7a5f38;font-size:12px;"></span>
          <span class="spacer"></span>
          <button class="btn btn-sm btn-default" id="btnPreview"><i class="fa fa-eye"></i> 預覽/列印</button>
          <button class="btn btn-sm btn-success" id="btnPublish" style="display:none;"><i class="fa fa-check-circle"></i> 發布新版本</button>
        </div>
      </div>
    </div>
  </div>
</div></div></div>

<!-- 抽舊檔內文側欄 -->
<div class="od-side" id="odSide">
  <h5><span><i class="fa fa-file-word-o"></i> 舊檔原文（供複製分段）</span><button class="mini" onclick="document.getElementById('odSide').classList.remove('open')" style="border:none;background:none;cursor:pointer;">✕</button></h5>
  <div class="body" id="odSideBody"></div>
</div>

<!-- 發布 Modal -->
<div class="modal fade" id="pubModal" tabindex="-1" role="dialog"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header" style="background:var(--sand);"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title"><i class="fa fa-check-circle"></i> 發布新版本</h4></div>
  <div class="modal-body">
    <div class="alert alert-info" id="pubSuggest" style="font-size:12px;padding:8px 10px;">計算版本建議中…</div>
    <div class="row">
      <div class="form-group col-md-4"><label>版本號 *</label><input type="text" class="form-control" id="pubVer"></div>
      <div class="form-group col-md-4"><label>文件狀況</label>
        <select class="form-control" id="pubStatus"><option>制訂</option><option selected>修訂</option></select></div>
      <div class="form-group col-md-4"><label>制修訂日期</label><input type="date" class="form-control" id="pubDate" max="9999-12-31"></div>
    </div>
    <div class="form-group"><label>制修訂頁次</label><input type="text" class="form-control" id="pubPages" placeholder="如 全冊 / 5.作業內容"></div>
    <div class="form-group"><label>制修訂摘要</label><textarea class="form-control" id="pubSummary" rows="3"></textarea></div>
    <p class="text-muted" style="font-size:12px;">發布後：此結構化內容成為新的正式版本，舊上傳檔仍保留為歷史版本（備份）。</p>
  </div>
  <div class="modal-footer">
    <button class="btn btn-default" data-dismiss="modal">取消</button>
    <button class="btn btn-success" id="pubConfirm"><i class="fa fa-check"></i> 確認發布</button>
  </div>
</div></div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.bundle.min.js"></script>
<script>
const API = '../../src/store/AS_DocOnline_API.php';
let CUR = null;          // 目前開啟文件狀態
let EDITING = false;     // 是否已取得編輯鎖
let DIRTY = false;
const esc = s => $('<i>').text(s==null?'':s).html();

// ── 清單 ──
function loadList(keepId){
  $.getJSON(API+'?action=list', r=>{
    if(r.status!=='success'){ $('#odList').html('<div style="padding:14px;color:#c00;">'+(r.message||'載入失敗')+'</div>'); return; }
    window.CAN_EDIT = r.can_edit; window.CAN_PUB = r.can_publish; window.ME = r.me;
    let lv='';let h='';
    r.rows.forEach(d=>{
      if(d.doc_level!==lv){ lv=d.doc_level; h+=`<h5 style="position:sticky;top:0;">${esc(lv)}</h5>`; }
      let badge;
      if(d.locked_by && +d.locked_by!==+r.me) badge=`<span class="od-badge b-lock" title="編輯中">鎖</span>`;
      else if(d.draft_status==='draft'&&+d.has_content>0) badge='<span class="od-badge b-draft">草稿</span>';
      else if(d.draft_status==='published'||+d.has_content>0) badge='<span class="od-badge b-pub">已上線</span>';
      else badge='<span class="od-badge b-none">未建</span>';
      h+=`<div class="od-item${keepId==d.id?' active':''}" data-id="${d.id}">
            <div class="no">${esc(d.doc_no)}</div>${esc(d.doc_name)}${badge}</div>`;
    });
    $('#odList').html(h||'<div style="padding:14px;color:#999;">無程序書/手冊</div>');
  });
}
$(document).on('click','.od-item',function(){
  if(DIRTY && !confirm('尚有未存草稿，切換將遺失變更，仍要切換？')) return;
  $('.od-item').removeClass('active'); $(this).addClass('active');
  openDoc($(this).data('id'));
});

// ── 開檔 ──
function openDoc(docId){
  EDITING=false; DIRTY=false;
  $.getJSON(API+'?action=get&doc_id='+docId, r=>{
    if(r.status!=='success'){ alert(r.message||'載入失敗'); return; }
    CUR=r;
    $('#odIdle').hide(); $('#odEditor').show();
    $('#cvName').text(r.doc.doc_name); $('#cvNo').text(r.doc.doc_no+'　'+r.doc.doc_level+' / '+(r.doc.doc_type||''));
    $('#cvVer').text(r.doc.current_version||'—');
    $('#cvStatus').text(r.current_version?(r.current_version.change_status||'')+' '+(r.current_version.revised_date||''):'');
    renderSections(r.sections);
    // 鎖/權限狀態
    $('#odToolbar').hide(); $('#addSecWrap').hide();
    $('#btnSave,#btnPrefill,#btnPublish').hide();
    $('#btnEnable').show().prop('disabled',false);
    $('#saveHint').text('');
    if(!window.CAN_EDIT){ $('#btnEnable').hide(); showRO('你只有檢視權限（唯讀）。'); }
    else if(r.locked_by_other){ $('#btnEnable').prop('disabled',true); showRO('文件正由「'+esc(r.locked_by_name)+'」編輯中，暫無法編輯。'); }
    else { $('#roNote').hide(); }
    setEditable(false);
  });
}
function showRO(msg){ $('#roMsg').html(msg); $('#roNote').show(); }

function renderSections(secs){
  let h='';
  secs.forEach((s,i)=>{ h+=secHtml(s,i); });
  $('#odSections').html(h);
  refreshIdx();
}
function secHtml(s,i){
  return `<div class="od-sec" data-key="${esc(s.section_key)}">
    <div class="od-sec-hd">
      <span class="idx"></span>
      <input class="tt" value="${esc(s.title)}" readonly>
      <button class="mini act-up" title="上移">▲</button>
      <button class="mini act-down" title="下移">▼</button>
      <button class="mini act-del" title="刪除段落" style="display:none;">🗑</button>
    </div>
    <div class="od-edit" data-ph="（此段落內容）">${s.content_html||''}</div>
  </div>`;
}
function refreshIdx(){ $('#odSections .od-sec').each((i,el)=>{ $(el).find('.idx').text((i+1)+'.'); }); }

// ── 啟用編輯（取鎖）──
$('#btnEnable').on('click',function(){
  if(!CUR) return;
  $.post(API+'?action=lock',{doc_id:CUR.doc.id}, r=>{
    if(r.status!=='success'){ alert(r.message||'無法取得編輯權'); if(r.locked) showRO(r.message); return; }
    EDITING=true; $('#roNote').hide();
    $('#odToolbar').show(); $('#addSecWrap').show();
    $('#btnEnable').hide(); $('#btnSave,#btnPrefill').show();
    if(window.CAN_PUB) $('#btnPublish').show();
    $('#odSections .act-del').show();
    $('#odSections .tt').prop('readonly',false);
    setEditable(true);
  },'json');
});
function setEditable(on){
  $('#odSections .od-edit').attr('contenteditable', on?'true':'false');
}

// ── 富文本工具列（document.execCommand，內網瀏覽器皆支援）──
let lastSel=null;
$(document).on('mouseup keyup','.od-edit',function(){ lastSel=window.getSelection().getRangeAt&&window.getSelection().rangeCount?window.getSelection().getRangeAt(0):null; });
$(document).on('input','.od-edit',()=>{ DIRTY=true; $('#saveHint').text('● 未儲存'); });
$('#odToolbar button[data-cmd]').on('mousedown',e=>e.preventDefault());
$('#odToolbar button[data-cmd]').on('click',function(){ document.execCommand($(this).data('cmd'),false,null); DIRTY=true; });
$('#odToolbar button[data-block]').on('mousedown',e=>e.preventDefault());
$('#odToolbar button[data-block]').on('click',function(){ document.execCommand('formatBlock',false,$(this).data('block')); DIRTY=true; });
$('#tbTable').on('mousedown',e=>e.preventDefault());
$('#tbTable').on('click',function(){
  const r=parseInt(prompt('列數',2)||0), c=parseInt(prompt('欄數',2)||0);
  if(!r||!c) return;
  let t='<table>';for(let i=0;i<r;i++){t+='<tr>';for(let j=0;j<c;j++)t+='<td>&nbsp;</td>';t+='</tr>';}t+='</table><p><br></p>';
  document.execCommand('insertHTML',false,t); DIRTY=true;
});
$('#tbRow,#tbCol').on('mousedown',e=>e.preventDefault());
$('#tbRow').on('click',()=>{ const td=$(window.getSelection().anchorNode).closest('td'); const tr=td.closest('tr'); if(!tr.length)return; const n=tr.children().length; let nr='<tr>';for(let i=0;i<n;i++)nr+='<td>&nbsp;</td>';nr+='</tr>'; tr.after(nr); DIRTY=true; });
$('#tbCol').on('click',()=>{ const td=$(window.getSelection().anchorNode).closest('td'); const tbl=td.closest('table'); if(!tbl.length)return; const ci=td.index(); tbl.find('tr').each(function(){ $(this).children().eq(ci).after('<td>&nbsp;</td>'); }); DIRTY=true; });

// ── 段落 增/刪/移 ──
$('#btnAddSec').on('click',function(){
  const title=prompt('段落標題','新段落'); if(title===null) return;
  const key='custom_'+Date.now();
  $('#odSections').append(secHtml({section_key:key,title:title,content_html:''}, 0));
  const $new=$('#odSections .od-sec').last();
  $new.find('.act-del').show(); $new.find('.tt').prop('readonly',false);
  $new.find('.od-edit').attr('contenteditable','true');
  refreshIdx(); DIRTY=true;
});
$(document).on('click','.act-up',function(){ if(!EDITING)return; const s=$(this).closest('.od-sec'); s.prev('.od-sec').before(s); refreshIdx(); DIRTY=true; });
$(document).on('click','.act-down',function(){ if(!EDITING)return; const s=$(this).closest('.od-sec'); s.next('.od-sec').after(s); refreshIdx(); DIRTY=true; });
$(document).on('click','.act-del',function(){ if(!EDITING)return; if(!confirm('刪除此段落？'))return; $(this).closest('.od-sec').remove(); refreshIdx(); DIRTY=true; });
$(document).on('input','.tt',()=>{ DIRTY=true; });

function collectSections(){
  const arr=[];
  $('#odSections .od-sec').each(function(i){
    arr.push({section_key:$(this).data('key'), title:$(this).find('.tt').val()||'（未命名）',
              sort_order:(i+1)*10, content_html:$(this).find('.od-edit').html()});
  });
  return arr;
}

// ── 存草稿 ──
$('#btnSave').on('click',function(){
  if(!CUR)return;
  const $b=$(this).prop('disabled',true);
  $.post(API+'?action=save_draft',{doc_id:CUR.doc.id,sections:JSON.stringify(collectSections())}, r=>{
    $b.prop('disabled',false);
    if(r.status!=='success'){ alert(r.message||'存檔失敗'); return; }
    DIRTY=false; $('#saveHint').text('已存 '+r.saved_at);
    loadList(CUR.doc.id);
  },'json');
});

// ── 抽舊檔內文 ──
$('#btnPrefill').on('click',function(){
  if(!CUR)return;
  $('#odSideBody').html('讀取中…'); $('#odSide').addClass('open');
  $.post(API+'?action=prefill_from_word',{doc_id:CUR.doc.id}, r=>{
    if(r.status!=='success'){ $('#odSideBody').html('<span style="color:#c00;">'+esc(r.message)+'</span>'); return; }
    $('#odSideBody').html('<div style="color:#7a5f38;margin-bottom:8px;">共 '+r.para_count+' 段，請圈選複製貼入對應段落。</div>'+r.text_html);
  },'json');
});

// ── 預覽 ──
$('#btnPreview').on('click',function(){ if(CUR) window.open(API+'?action=render&doc_id='+CUR.doc.id,'_blank'); });

// ── 發布 ──
$('#btnPublish').on('click',function(){
  if(!CUR)return;
  if(DIRTY && !confirm('尚有未存草稿，建議先存草稿再發布。仍要繼續（將以目前已存草稿發布）？')) return;
  $('#pubSuggest').text('計算版本建議中…');
  $('#pubModal').modal('show');
  $.getJSON(API+'?action=suggest_version&doc_id='+CUR.doc.id, r=>{
    if(r.status!=='success'){ $('#pubSuggest').text(r.message||'計算失敗'); return; }
    $('#pubSuggest').html('基準版：<b>'+esc(r.base_version||'（首次電子化）')+'</b>　建議版次：<b>'+esc(r.suggest_version)+'</b>　'+(r.has_base?'變更 '+(r.changed.length+r.added.length)+' 段':'首次建置'));
    $('#pubVer').val(r.suggest_version);
    $('#pubStatus').val(r.suggest_status);
    $('#pubPages').val(r.suggest_pages);
    $('#pubSummary').val(r.suggest_summary);
    if(!$('#pubDate').val()) $('#pubDate').val(new Date().toISOString().slice(0,10));
  });
});
$('#pubConfirm').on('click',function(){
  const $b=$(this).prop('disabled',true);
  $.post(API+'?action=publish',{doc_id:CUR.doc.id,version:$('#pubVer').val(),change_status:$('#pubStatus').val(),
    revised_date:$('#pubDate').val(),revised_pages:$('#pubPages').val(),revised_summary:$('#pubSummary').val()}, r=>{
    $b.prop('disabled',false);
    if(r.status!=='success'){ alert(r.message||'發布失敗'); return; }
    $('#pubModal').modal('hide'); DIRTY=false; EDITING=false;
    alert(r.message); openDoc(CUR.doc.id); loadList(CUR.doc.id);
  },'json');
});

// 離開釋放鎖
window.addEventListener('beforeunload',function(){
  if(EDITING && CUR){ navigator.sendBeacon(API+'?action=unlock', new Blob([new URLSearchParams({doc_id:CUR.doc.id}).toString()],{type:'application/x-www-form-urlencoded'})); }
});
// 通用：聚焦文字輸入自動全選（UI 規範）
$(document).on('focus','#pubModal input[type=text]',function(){ this.select(); });

loadList();
</script>
</body></html>
