<?php
// AS 線上表單 — 填寫/簽核頁
// ?template_id=N 新填一張；?instance_id=N 開既有紀錄（可續填草稿/檢視/簽核）。
// 簽名格：已簽區顯示 EGStamp 圖章；具資格者看到「核准/駁回」按鈕。
session_start();
if (!isset($_SESSION['userName'])) { header("Location:../../index.php"); exit; }
include_once '../../src/common/_config.php';
include ("../../src/common/DBConnection.php");
$db = (new DBConnection())->getPDO();
$template_id = isset($_GET['template_id']) ? (int)$_GET['template_id'] : 0;
$instance_id = isset($_GET['instance_id']) ? (int)$_GET['instance_id'] : 0;
// 本公司名（EGStamp 印章需要）
$ownCompany = '';
try {
    if ($cr = $db->query("SELECT customer_full, customer FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC)) {
        $ownCompany = $cr['customer_full'] ?: ($cr['customer'] ?? '');
    }
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>表單填寫 | 線上表單</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<link href="../../resource/css/as_form.css?v=<?php echo @filemtime(__DIR__.'/../../resource/css/as_form.css'); ?>" rel="stylesheet">
<style>
  html,body{overflow-x:hidden;}
  .right_col{background:#efe7da;font-family:"Microsoft JhengHei","微軟正黑體",Arial,sans-serif;color:#3a2a17;min-height:100vh;overflow-x:hidden;}
  .form-toolbar,.form-sheet,.sign-panel{width:auto;max-width:820px;}
  .form-toolbar{max-width:820px;margin:14px auto 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
  .form-sheet{max-width:820px;margin:12px auto 16px;background:#fff;padding:26px 30px;box-shadow:0 2px 10px rgba(90,61,30,.18);}
  .status-chip{display:inline-block;padding:2px 10px;border-radius:10px;font-size:12px;font-weight:bold;}
  .st-draft{background:#f7e0bd;color:#5a3d1e;} .st-in_review{background:#f0a24b;color:#4a2c0a;}
  .st-approved{background:#e8dcc3;color:#4a3a20;} .st-rejected{background:#dd5138;color:#fff;}
  .sign-panel{max-width:820px;margin:0 auto 40px;background:#fff;border:1px solid #d8c19a;border-radius:6px;padding:12px 16px;box-shadow:0 2px 8px rgba(90,61,30,.12);}
  .sign-panel h4{margin:0 0 8px;font-size:14px;color:#7a4e17;border-bottom:2px solid #f0a24b;padding-bottom:5px;}
  .flow-step{display:inline-block;padding:3px 10px;border-radius:4px;font-size:12px;margin:2px 4px 2px 0;border:1px solid #e0cba0;}
  .fs-approved{background:#f5ead3;color:#6b4e2a;} .fs-pending{background:#fff;color:#a06a28;border-style:dashed;}
  .fs-rejected{background:#dd5138;color:#fff;}
  @media print{
    body{background:#fff;}
    .form-toolbar,.sign-panel,.left_col,.top_nav,footer{display:none !important;}
    .right_col{margin:0 !important;padding:0 !important;background:#fff;min-height:0;}
    .container.body,.main_container{margin:0;padding:0;background:#fff;}
    .form-sheet{box-shadow:none;margin:0;max-width:none;padding:0;}
    .eg-form td{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  }
</style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html' ?>
<div class="right_col" role="main">
  <div class="form-toolbar">
    <a class="btn btn-default btn-sm" href="as_form_list.php" title="回表單清單"><i class="fa fa-list"></i> 返回清單</a>
    <span id="formTitle" style="font-weight:bold;color:#7a4e17;"></span>
    <span id="statusChip"></span>
    <span style="margin-left:auto;display:flex;gap:6px;">
      <button class="btn btn-default btn-sm" id="btnSaveDraft" style="display:none;"><i class="fa fa-save"></i> 存草稿</button>
      <button class="btn btn-success btn-sm" id="btnSubmit" data-submit style="display:none;"><i class="fa fa-paper-plane"></i> 送出簽核</button>
      <button class="btn btn-warning btn-sm" onclick="window.print()"><i class="fa fa-print"></i> 列印</button>
    </span>
  </div>
  <div class="form-sheet"><div id="formHost"></div></div>

  <div class="sign-panel" id="signPanel" style="display:none;">
    <h4><i class="fa fa-pencil-square-o"></i> 簽核</h4>
    <div id="flowSteps" style="margin-bottom:8px;"></div>
    <div id="myActions"></div>
  </div>

</div><!-- /right_col -->
</div><!-- /main_container -->
</div><!-- /container body -->
<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/as_form_render.js?v=<?php echo @filemtime(__DIR__.'/../../resource/js/as_form_render.js'); ?>"></script>
<script src="../../resource/js/eg_stamp.js?v=<?php echo @filemtime(__DIR__.'/../../resource/js/eg_stamp.js'); ?>"></script>
<script>
window.__ownCompany = <?php echo json_encode($ownCompany, JSON_UNESCAPED_UNICODE); ?>;
const API = '../../src/store/AS_Form_API.php';
const TEMPLATE_ID = <?php echo (int)$template_id; ?>;
let INSTANCE_ID = <?php echo (int)$instance_id; ?>;
let schema=null, ctx={}, canEdit=false;

function collectData(){
  const data={};
  $('#formHost').find('input[data-key],select[data-key],textarea[data-key]').each(function(){
    const k=$(this).data('key');
    if(this.type==='checkbox'){
      if(this.getAttribute('value')!==null){
        // 勾選群組成員：收集成陣列
        if(!Array.isArray(data[k])) data[k]=[];
        if(this.checked) data[k].push(this.value);
      } else {
        data[k]=this.checked?1:'';   // 單一勾選（未設選項）
      }
    } else {
      data[k]=this.value;
    }
  });
  return data;
}

function statusChip(st){
  const label={draft:'草稿',in_review:'簽核中',approved:'已完成',rejected:'已駁回',void:'作廢'}[st]||st;
  return `<span class="status-chip st-${st}">${label}</span>`;
}

function render(mode, data, editDepts){
  $('#formHost').html(EGForm.renderForm(schema,{mode,data,ctx,editDepts:editDepts||[]}));
  if(mode==='fill') EGForm.bindFormUX($('#formHost'));   // 內含首次重算
  else EGForm.updateComputed($('#formHost'));            // 檢視模式也要畫圖表/公式

  // 已簽區換成圖章（渲染器輸出 .sig-slot[data-sig=區key]，含會簽區塊內簽章）
  Object.keys(data||{}).forEach(k=>{
    if(k.indexOf('__sig_')!==0) return;
    const sec=k.substring(6), v=data[k];
    $('#formHost .sig-slot').filter(function(){ return $(this).data('sig')===sec; })
      .html(EGStamp.stamp(v.name, v.at||'', false));
  });
}

// ── 新填模式（支援 ?prefill=JSON 預填，例：發布改版建議自動帶入目標文件）──
function loadTemplate(){
  let prefill={};
  try{ prefill=JSON.parse(new URLSearchParams(location.search).get('prefill')||'{}')||{}; }catch(e){}
  $.getJSON(API+'?action=load&template_id='+TEMPLATE_ID, r=>{
    if(!r.ok){ alert(r.error||'載入失敗'); return; }
    schema=r.schema; ctx=r.ctx;
    $('#formTitle').text(r.template.name);
    $('#statusChip').html(statusChip('draft'));
    canEdit=true;
    $('#btnSaveDraft,#btnSubmit').show();   // 先顯示按鈕，渲染出錯也不影響操作
    try{ render('fill',prefill); }
    catch(e){ console.error(e); alert('表單渲染發生錯誤：'+e.message+'\n請回設計器檢查此表單設定'); }
  }).fail(x=>alert('載入失敗：'+x.status));
}

// ── 既有紀錄模式 ──
function loadInstance(){
  $.getJSON(API+'?action=instance_load&instance_id='+INSTANCE_ID, r=>{
    if(!r.ok){ alert(r.error||'載入失敗'); return; }
    schema=r.schema; ctx=r.ctx; canEdit=r.can_edit;
    $('#formTitle').text(r.instance.name+(r.instance.title?('｜'+r.instance.title):''));
    $('#statusChip').html(statusChip(r.instance.status));
    // 會簽：我可簽的區若帶部門(@deptid)，該部門的區塊欄位開放我填寫
    const editDepts=(r.my_sections||[]).map(m=>String(m.section_key||'')).filter(k=>k.indexOf('@')>=0).map(k=>k.split('@')[1]);
    $('#btnSaveDraft,#btnSubmit').toggle(canEdit);   // 先切換按鈕，渲染出錯也不影響操作
    renderSignPanel(r);
    try{ render(canEdit?'fill':'view', r.data, editDepts); }
    catch(e){ console.error(e); alert('表單渲染發生錯誤：'+e.message+'\n請回設計器檢查此表單設定'); }
  }).fail(x=>alert('載入失敗：'+x.status));
}

function renderSignPanel(r){
  if(!r.approvals || !r.approvals.length){ $('#signPanel').hide(); return; }
  $('#signPanel').show();
  const steps=r.approvals.map(a=>{
    const cls={approved:'fs-approved',pending:'fs-pending',rejected:'fs-rejected'}[a.status]||'fs-pending';
    const who=a.status==='approved'?`：${a.approver_name} ${a.decided_at?a.decided_at.substring(0,10):''}`
             :a.status==='rejected'?`：${a.approver_name} 駁回${a.note?('（'+a.note+'）'):''}`:'（待簽）';
    return `<span class="flow-step ${cls}">step${a.step_no}｜${a.section_label||a.section_key}${who}</span>`;
  }).join('<i class="fa fa-angle-right" style="color:#c9a877;"></i> ');
  $('#flowSteps').html(steps);
  // 我可簽的區
  const acts=(r.my_sections||[]).map(m=>{
    const isCs=String(m.section_key||'').indexOf('@')>=0;
    return `<div style="margin-top:6px;padding:8px;background:#fdf6ea;border:1px solid #e6cfa4;border-radius:4px;">
       <strong>${m.section_label||m.section_key}</strong>
       ${isCs?'<span class="text-muted" style="font-size:11px;margin-left:6px;">請先在上方表單填寫貴部門的會簽欄位（同意/不同意、意見），再按核准送出</span>':''}
       <input type="text" class="form-control input-sm dec-note" data-aid="${m.approval_id}" placeholder="意見（駁回必填）" style="width:280px;display:inline-block;margin:0 6px;">
       <button class="btn btn-success btn-sm dec-btn" data-aid="${m.approval_id}" data-seckey="${m.section_key}" data-d="approved"><i class="fa fa-check"></i> 核准</button>
       <button class="btn btn-danger btn-sm dec-btn" data-aid="${m.approval_id}" data-seckey="${m.section_key}" data-d="rejected"><i class="fa fa-times"></i> 駁回</button>
     </div>`;}).join('');
  $('#myActions').html(acts||'<span class="text-muted" style="font-size:12px;">目前無您需要簽核的區塊。</span>');
}

$('#signPanel').on('click','.dec-btn',function(){
  const aid=$(this).data('aid'), d=$(this).data('d');
  const note=$('.dec-note[data-aid="'+aid+'"]').val().trim();
  if(d==='rejected' && !note){ alert('駁回必須填寫原因'); return; }
  if(!confirm(d==='approved'?'確定核准此區？':'確定駁回？駁回後整張表單退回填表人。')) return;
  const payload={approval_id:aid, decision:d, note:note};
  // 會簽區：一併送出我在表上填寫的區塊欄位（同意/不同意、意見）
  const sk=String($(this).data('seckey')||'');
  if(sk.indexOf('@')>=0){
    const dept=sk.split('@')[1], sd={};
    $('#formHost [data-cs-dept="'+dept+'"]').each(function(){
      const k=$(this).data('key'); if(k) sd[k]=this.value;
    });
    payload.section_data=JSON.stringify(sd);
  }
  $.post(API+'?action=decide', payload, r=>{
    if(!r.ok){ alert(r.error||'簽核失敗'); return; }
    loadInstance();
  },'json').fail(x=>alert('簽核失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)));
});

// ── 存草稿 / 送出 ──
function saveDraft(cb){
  const payload={template_id:TEMPLATE_ID, instance_id:INSTANCE_ID||0, data_json:JSON.stringify(collectData())};
  $.post(API+'?action=instance_save', payload, r=>{
    if(!r.ok){ alert(r.error||'儲存失敗'); return; }
    if(!INSTANCE_ID){ INSTANCE_ID=r.instance_id;
      history.replaceState(null,'','as_form_fill.php?instance_id='+INSTANCE_ID); }
    if(cb) cb(); else alert('已存草稿');
  },'json').fail(x=>alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)));
}
$('#btnSaveDraft').on('click',()=>saveDraft());
$('#btnSubmit').on('click',function(){
  if(!confirm('送出後將進入簽核流程，期間不可修改。確定送出？')) return;
  saveDraft(()=>{
    $.post(API+'?action=instance_submit',{instance_id:INSTANCE_ID}, r=>{
      if(!r.ok){ alert(r.error||'送出失敗'); return; }
      alert('已送出，進入簽核流程'); loadInstance();
    },'json').fail(x=>alert('送出失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)));
  });
});

if(INSTANCE_ID) loadInstance(); else if(TEMPLATE_ID) loadTemplate();
else $('#formHost').text('請由表單清單進入（缺 template_id / instance_id）');
</script>
</body>
</html>
