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
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
:root{--amber:#d99a4e;--amber-d:#b06f27;--sand:#faf3e7;--ink:#3a2c1a;}
.od-wrap{display:flex;gap:14px;padding:0 14px 30px;}
.od-list{flex:0 0 288px;max-height:calc(100vh - 150px);overflow:auto;border:1px solid #e6d8c3;border-radius:6px;background:#fffdf9;}
.od-list h5{margin:0;padding:8px 10px;background:var(--sand);border-bottom:1px solid #e6d8c3;font-size:13px;color:var(--amber-d);position:sticky;top:0;z-index:2;}
.od-item{padding:7px 10px;border-bottom:1px solid #f0e7d7;cursor:pointer;font-size:13px;}
.od-item:hover{background:#fdf6ea;}
.od-item.active{background:#f6e3c8;border-left:3px solid var(--amber);}
.od-item .no{color:#9a7b4f;font-size:11px;}
.od-badge{font-size:10px;padding:1px 5px;border-radius:8px;margin-left:4px;}
.b-draft{background:#f0a24b;color:#fff;} .b-pub{background:#8bbf7a;color:#fff;} .b-none{background:#e0d4bf;color:#6b5638;} .b-lock{background:#dd5138;color:#fff;}
.od-main{flex:1;min-width:0;}
.od-cover{border:1px solid var(--amber);background:var(--sand);border-radius:6px;padding:10px 14px;margin-bottom:10px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;}
.od-cover .t{font-size:16px;font-weight:700;color:var(--ink);}
.od-cover .m{font-size:12px;color:#7a5f38;}
.readonly-note{background:#fdf0dc;border:1px solid #e9c98f;color:#8a5a1a;padding:5px 10px;border-radius:5px;font-size:12px;margin-bottom:8px;}
.od-actbar{position:sticky;top:52px;z-index:31;background:#fff8ee;border:1px solid #e6d8c3;border-radius:6px;padding:8px 10px;display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap;box-shadow:0 3px 10px rgba(0,0,0,.08);}
.od-actbar .spacer{flex:1;}
.btn-amber{background:var(--amber);border-color:var(--amber-d);color:#fff;}
.btn-amber:hover{background:var(--amber-d);color:#fff;}
</style>
</head>
<body class="nav-sm">
<div class="container body"><div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html' ?>
<div class="right_col" role="main">
  <div class="page-title"><div class="title_left"><h3>AS 程序書 線上編輯 <small>單一內文・TinyMCE・自動版本建議（試作）</small></h3></div></div>
  <div class="clearfix"></div>

  <div class="od-wrap">
    <div class="od-list">
      <h5><i class="fa fa-folder-open-o"></i> 程序書 / 手冊</h5>
      <div id="odList"><div style="padding:14px;color:#999;">載入中…</div></div>
    </div>

    <div class="od-main">
      <div id="odIdle" style="padding:40px;text-align:center;color:#b09a78;">
        <i class="fa fa-file-text-o" style="font-size:40px;"></i>
        <p style="margin-top:10px;">請由左側選一份文件開始</p>
        <p style="font-size:12px;">舊上傳檔不受影響；此處為結構化線上內容，發布後才成為新版本。</p>
      </div>

      <div id="odEditor" style="display:none;">
        <div class="od-cover">
          <div><div class="t" id="cvName">—</div><div class="m" id="cvNo">—</div></div>
          <div style="text-align:right;"><div class="m">目前版本：<b id="cvVer">—</b></div><div class="m" id="cvStatus"></div></div>
        </div>
        <div id="roNote" class="readonly-note" style="display:none;"><i class="fa fa-lock"></i> <span id="roMsg"></span></div>

        <div class="od-actbar">
          <button class="btn btn-sm btn-default" id="btnEnable"><i class="fa fa-pencil"></i> 啟用編輯</button>
          <button class="btn btn-sm btn-amber" id="btnSave" style="display:none;"><i class="fa fa-save"></i> 存草稿</button>
          <button class="btn btn-sm btn-default" id="btnAutoFmt" style="display:none;" title="統一字級/清多餘空行/可辨識段落標題自動轉H4"><i class="fa fa-magic"></i> 自動排版</button>
          <span id="saveHint" style="color:#7a5f38;font-size:12px;"></span>
          <span class="spacer"></span>
          <button class="btn btn-sm btn-default" id="btnPreview"><i class="fa fa-eye"></i> 預覽/列印</button>
          <button class="btn btn-sm btn-success" id="btnPublish" style="display:none;"><i class="fa fa-check-circle"></i> 發布新版本</button>
        </div>

        <textarea id="odBody"></textarea>

        <p class="text-muted" style="font-size:12px;margin-top:6px;">
          圖片：工具列「插入圖片」上傳，或從電腦直接貼上（<b>從 Word 複製的圖片瀏覽器無法帶入，需另存成圖片再上傳</b>）。段落標題請用「標題4（H4）」，系統依標題自動判定改版。
        </p>
      </div>
    </div>
  </div>
</div></div></div>

<!-- 發布 Modal -->
<div class="modal fade" id="pubModal" tabindex="-1" role="dialog"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header" style="background:#faf3e7;"><button type="button" class="close" data-dismiss="modal">&times;</button>
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
    <p class="text-muted" style="font-size:12px;">發布後此結構化內容成為新正式版本，舊上傳檔仍保留為歷史版本（備份）。</p>
  </div>
  <div class="modal-footer">
    <button class="btn btn-default" data-dismiss="modal">取消</button>
    <button class="btn btn-success" id="pubConfirm"><i class="fa fa-check"></i> 確認發布</button>
  </div>
</div></div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/tinymce/tinymce.min.js"></script>
<script>
const API = '../../src/store/AS_DocOnline_API.php';
let CUR=null, EDITING=false, DIRTY=false, ED=null, EDReady=false;
const esc = s => $('<i>').text(s==null?'':s).html();

// ── TinyMCE 初始化（單一內文；圖片上傳走後端；貼上清洗 file:// 圖）──
tinymce.init({
  selector:'#odBody',
  license_key:'gpl',
  base_url:'../../resource/js/tinymce',
  height:560, menubar:false, language:'zh_TW',
  toolbar_sticky:true, toolbar_sticky_offset:100,
  plugins:'lists table image link autoresize autolink searchreplace visualblocks code fullscreen',
  toolbar:'undo redo | blocks fontsizeinput | bold italic underline forecolor | alignleft aligncenter alignjustify | bullist numlist outdent indent | table image link | removeformat code fullscreen',
  block_formats:'內文=p; 標題4(段落標題)=h4; 標題5=h5; 標題6=h6',
  font_size_input_default_unit:'pt',
  branding:false, promotion:false, convert_urls:false, autoresize_bottom_margin:20,
  content_style:'body{font-family:"Microsoft JhengHei",sans-serif;font-size:12pt;color:#2b2b2b;line-height:1.7;} h4{border-left:5px solid #d99a4e;padding-left:8px;color:#8a5a1a;} table{border-collapse:collapse;} td,th{border:1px solid #999;padding:3px 6px;}',
  paste_data_images:true, automatic_uploads:true,
  images_upload_handler:(blobInfo)=>new Promise((resolve,reject)=>{
    if(!CUR){ reject('尚未開啟文件'); return; }
    const fd=new FormData();
    fd.append('doc_id',CUR.doc.id);
    fd.append('file',blobInfo.blob(),blobInfo.filename());
    fetch(API+'?action=upload_image',{method:'POST',body:fd,credentials:'same-origin'})
      .then(r=>r.json()).then(j=>{ if(j.location) resolve(j.location); else reject(j.error||'上傳失敗'); })
      .catch(()=>reject('上傳連線失敗'));
  }),
  paste_preprocess:(editor,args)=>{
    // 移除 Word 貼上帶來的本機 file:// 圖片（瀏覽器封鎖、無法載入）
    args.content = args.content.replace(/<img[^>]+src=["']file:[^"']*["'][^>]*>/gi,'');
  },
  setup:(ed)=>{
    ED=ed;
    ed.on('init',()=>{ EDReady=true; ed.mode.set('readonly'); });
    ed.on('input change ExecCommand SetContent',()=>{ if(EDITING){ DIRTY=true; $('#saveHint').text('● 未儲存'); } });
  }
});

// ── 清單 ──
function loadList(keepId){
  $.getJSON(API+'?action=list', r=>{
    if(r.status!=='success'){ $('#odList').html('<div style="padding:14px;color:#c00;">'+(r.message||'載入失敗')+'</div>'); return; }
    window.CAN_EDIT=r.can_edit; window.CAN_PUB=r.can_publish; window.ME=r.me;
    let lv='',h='';
    r.rows.forEach(d=>{
      if(d.doc_level!==lv){ lv=d.doc_level; h+=`<h5>${esc(lv)}</h5>`; }
      let badge;
      if(d.locked_by && +d.locked_by!==+r.me) badge='<span class="od-badge b-lock" title="編輯中">鎖</span>';
      else if(+d.has_content>0 && d.draft_status==='draft') badge='<span class="od-badge b-draft">草稿</span>';
      else if(+d.has_content>0) badge='<span class="od-badge b-pub">已上線</span>';
      else badge='<span class="od-badge b-none">未建</span>';
      h+=`<div class="od-item${keepId==d.id?' active':''}" data-id="${d.id}"><div class="no">${esc(d.doc_no)}</div>${esc(d.doc_name)}${badge}</div>`;
    });
    $('#odList').html(h||'<div style="padding:14px;color:#999;">無程序書/手冊</div>');
  });
}
$(document).on('click','.od-item',function(){
  if(DIRTY && !confirm('尚有未存草稿，切換將遺失變更，仍要切換？')) return;
  if(EDITING && CUR){ releaseLock(); }
  $('.od-item').removeClass('active'); $(this).addClass('active');
  openDoc($(this).data('id'));
});

// ── 開檔 ──
function whenEdReady(cb){ if(EDReady) cb(); else setTimeout(()=>whenEdReady(cb),120); }
function openDoc(docId){
  EDITING=false; DIRTY=false;
  $.getJSON(API+'?action=get&doc_id='+docId, r=>{
    if(r.status!=='success'){ alert(r.message||'載入失敗'); return; }
    CUR=r;
    $('#odIdle').hide(); $('#odEditor').show();
    $('#cvName').text(r.doc.doc_name); $('#cvNo').text(r.doc.doc_no+'　'+r.doc.doc_level+' / '+(r.doc.doc_type||''));
    $('#cvVer').text(r.doc.current_version||'—');
    $('#cvStatus').text(r.current_version?((r.current_version.change_status||'')+' '+(r.current_version.revised_date||'')):'');
    whenEdReady(()=>{ ED.setContent(r.body_html||''); ED.mode.set('readonly'); });
    $('#btnSave,#btnPublish,#btnAutoFmt').hide(); $('#btnEnable').show().prop('disabled',false); $('#saveHint').text('');
    if(!window.CAN_EDIT){ $('#btnEnable').hide(); showRO('你只有檢視權限（唯讀）。'); }
    else if(r.locked_by_other){ $('#btnEnable').prop('disabled',true); showRO('文件正由「'+esc(r.locked_by_name)+'」編輯中，暫無法編輯。'); }
    else $('#roNote').hide();
  });
}
function showRO(msg){ $('#roMsg').html(msg); $('#roNote').show(); }

// ── 啟用編輯（取鎖）──
$('#btnEnable').on('click',function(){
  if(!CUR) return;
  $.post(API+'?action=lock',{doc_id:CUR.doc.id}, r=>{
    if(r.status!=='success'){ alert(r.message||'無法取得編輯權'); if(r.locked) showRO(r.message); return; }
    EDITING=true; $('#roNote').hide();
    $('#btnEnable').hide(); $('#btnSave,#btnAutoFmt').show();
    if(window.CAN_PUB) $('#btnPublish').show();
    whenEdReady(()=>ED.mode.set('design'));
  },'json');
});
function releaseLock(){ if(CUR) $.post(API+'?action=unlock',{doc_id:CUR.doc.id}); EDITING=false; }

// ── 存草稿 ──
$('#btnSave').on('click',function(){
  if(!CUR)return;
  const body=ED.getContent();
  const sections=[{section_key:'body',title:'本文',sort_order:10,content_html:body}];
  const $b=$(this).prop('disabled',true);
  $.post(API+'?action=save_draft',{doc_id:CUR.doc.id,sections:JSON.stringify(sections)}, r=>{
    $b.prop('disabled',false);
    if(r.status!=='success'){ alert(r.message||'存檔失敗'); return; }
    DIRTY=false; $('#saveHint').text('已存 '+r.saved_at); loadList(CUR.doc.id);
  },'json');
});

// ── 自動快速排版：統一字級/顏色、清多餘空行、可辨識段落標題→H4 ──
const AS_SECTIONS=['目的與範圍','目的','適用範圍','範圍','權責','權責與定義','定義','名詞定義','作業內容','作業程序','作業流程','管理內容','相關文件','參考文件','使用表單','使用表單/紀錄','使用表單/記錄','紀錄','記錄','過程輸入','過程輸出','附件','品質政策','組織與權責','品質管理系統'];
$('#btnAutoFmt').on('click',function(){
  if(!ED) return;
  if(!confirm('自動排版會：統一字體大小、清除多餘空行、把可辨識的段落標題轉為標題4。要繼續嗎？（可用 Ctrl+Z 復原）')) return;
  const doc=new DOMParser().parseFromString('<div id="r">'+ED.getContent()+'</div>','text/html');
  const root=doc.getElementById('r');
  // 1) 去除雜亂 inline 樣式（字級/字體/顏色/行高/背景）與 <font> 屬性
  root.querySelectorAll('*').forEach(el=>{
    if(el.style){ ['font-size','font-family','line-height','color','background-color','background'].forEach(p=>el.style.removeProperty(p)); if(el.getAttribute('style')==='') el.removeAttribute('style'); }
    if(el.tagName==='FONT'){ el.removeAttribute('size'); el.removeAttribute('face'); el.removeAttribute('color'); }
  });
  // 2) 段落標題偵測 → h4（只認白名單，避免誤判內文/子項）
  root.querySelectorAll('p,div').forEach(p=>{
    const txt=(p.textContent||'').replace(/ /g,' ').trim();
    if(!txt||txt.length>20) return;
    const bare=txt.replace(/^[\d一二三四五六七八九十]+[\.、\s]*/,'').replace(/[:：\s]+$/,'').trim();
    if(AS_SECTIONS.includes(bare)){ const h=doc.createElement('h4'); h.textContent=txt; p.replaceWith(h); }
  });
  let html=root.innerHTML;
  // 3) 收合連續空段落
  html=html.replace(/(<p>(?:\s|&nbsp;|<br\s*\/?>)*<\/p>\s*){2,}/gi,'<p><br></p>');
  ED.setContent(html); DIRTY=true; $('#saveHint').text('● 已自動排版，未儲存');
});

// ── 預覽 ──
$('#btnPreview').on('click',function(){ if(CUR) window.open(API+'?action=render&doc_id='+CUR.doc.id,'_blank'); });

// ── 發布 ──
$('#btnPublish').on('click',function(){
  if(!CUR)return;
  if(DIRTY){ alert('請先「存草稿」再發布（發布以已存草稿為準）。'); return; }
  $('#pubSuggest').text('計算版本建議中…'); $('#pubModal').modal('show');
  $.getJSON(API+'?action=suggest_version&doc_id='+CUR.doc.id, r=>{
    if(r.status!=='success'){ $('#pubSuggest').text(r.message||'計算失敗'); return; }
    $('#pubSuggest').html('基準版：<b>'+esc(r.base_version||'（首次電子化）')+'</b>　建議版次：<b>'+esc(r.suggest_version)+'</b>　'+(r.has_base?('變更 '+(r.changed.length+r.added.length)+' 節'):'首次建置'));
    $('#pubVer').val(r.suggest_version); $('#pubStatus').val(r.suggest_status);
    $('#pubPages').val(r.suggest_pages); $('#pubSummary').val(r.suggest_summary);
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
$(document).on('focus','#pubModal input[type=text]',function(){ this.select(); });

loadList();
</script>
</body></html>
