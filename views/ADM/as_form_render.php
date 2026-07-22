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

// ── 表頭／表尾一律即時取值（隨本公司設定、文件版次變動，不寫死進 schema）──
// 表頭＝master_data 標記為「本公司」的客戶全名（發票用）
$companyName = '';
try {
    $cs = $db->query("SELECT customer_full, customer FROM customer_list WHERE is_own_company=1 LIMIT 1");
    if ($cr = $cs->fetch(PDO::FETCH_ASSOC)) { $companyName = $cr['customer_full'] ?: ($cr['customer'] ?? ''); }
} catch (Throwable $e) {}
// 表尾＝文件編號＋版次（取自所屬 as_document；版次無則退回模板發布版）
$footDocNo = '';
$footVer   = $tpl['published_version'] ? ('Ver.' . $tpl['published_version']) : '';
if (!empty($tpl['form_doc_id'])) {
    $ds = $db->prepare("SELECT doc_no, current_version FROM as_document WHERE id=?");
    $ds->execute([$tpl['form_doc_id']]);
    if ($dr = $ds->fetch(PDO::FETCH_ASSOC)) {
        $footDocNo = $dr['doc_no'] ?? '';
        if (!empty($dr['current_version'])) $footVer = $dr['current_version'];
    }
}
$renderCtx = json_encode([
    'company' => $companyName,
    'docNo'   => $footDocNo,
    'version' => $footVer,
], JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($tpl['name']); ?> | 線上表單</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/as_form.css?v=<?php echo @filemtime(__DIR__.'/../../resource/css/as_form.css'); ?>" rel="stylesheet">
<style>
  body{background:#efe7da;font-family:"Microsoft JhengHei","微軟正黑體",Arial,sans-serif;color:#3a2a17;}
  .form-toolbar{max-width:820px;margin:14px auto 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
  .form-sheet{max-width:820px;margin:12px auto 40px;background:#fff;padding:26px 30px;box-shadow:0 2px 10px rgba(90,61,30,.18);}
  .poc-note{max-width:820px;margin:0 auto;background:#fcf3e3;border:1px solid #e6cfa4;color:#7a5a2d;padding:8px 12px;border-radius:4px;font-size:12px;}
  @media print{
    body{background:#fff;}
    .form-toolbar,.poc-note{display:none;}
    .form-sheet{box-shadow:none;margin:0;max-width:none;padding:0;}
    .eg-form td{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
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
<script src="../../resource/js/as_form_render.js?v=<?php echo @filemtime(__DIR__.'/../../resource/js/as_form_render.js'); ?>"></script>
<script>
const SCHEMA = <?php echo $schema; ?>;
const RENDER_CTX = <?php echo $renderCtx; ?>;  // 表頭/表尾即時值 {company,docNo,version}

$(function(){
  const $host = $('#formHost');
  $host.html(EGForm.renderForm(SCHEMA, {mode:'fill', ctx:RENDER_CTX}));
  EGForm.bindFormUX($host);
});
</script>
</body>
</html>
