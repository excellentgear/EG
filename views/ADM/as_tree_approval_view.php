<?php
/**
 * AS 文件結構總覽（文件管制總覽表）— 審核檢視頁（通知點進來的落地頁）
 * 依 ai-rules/17-審核通知標準：通知要看得到完整內容、有核准/退回按鈕、退回必填原因。
 * 進入方式：live_event ref_type='AS_TREE_APPROVAL'/'AS_TREE_RESULT' → ?event=live_event.id（ref_id=approval_record.id）
 * 內容＝送審當下的快照（各階最新修改日期）＋現行文件清單；核准後列印才會顯示簽章。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/as_tree_approval_view.php" . (isset($_GET['event']) ? "?event=".(int)$_GET['event'] : '');
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/approval_lib.php';
include_once '../../src/common/org_role_lib.php';

$db  = (new DBConnection())->getPDO();
$uid = (int)($_SESSION['id'] ?? 0);

$apId = (int)($_GET['approval_id'] ?? 0);
if (!$apId && isset($_GET['event'])) {
    $st = $db->prepare("SELECT ref_id FROM live_event WHERE id=? AND ref_type LIKE 'AS_TREE%'");
    $st->execute([(int)$_GET['event']]);
    $apId = (int)$st->fetchColumn();
}
$rec = null;
if ($apId) {
    $st = $db->prepare("SELECT * FROM approval_record WHERE id=? AND module='as_doc_tree'");
    $st->execute([$apId]);
    $rec = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$getSet = function(string $k) use ($db): string {
    $s = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key=?");
    $s->execute([$k]); $v = $s->fetchColumn();
    return ($v === false || $v === null) ? '' : trim((string)$v);
};
$pending  = json_decode($getSet('as_doc_tree_pending') ?: 'null', true);
$approved = json_decode($getSet('as_doc_tree_approved') ?: 'null', true);
$snap = ($pending && (int)($pending['approval_id'] ?? 0) === $apId) ? $pending
      : (($approved && (int)($approved['approval_id'] ?? 0) === $apId) ? $approved : null);

$isPending = $rec && $rec['status'] === 'pending';
$canSign   = $isPending && $snap && ((int)($snap['approver_id'] ?? 0) === $uid || $uid === 1);
$company   = eg_company_full_name($db);

// 綁定的 AS 文件（表頭＝表單名稱、頁尾右下＝編號，ai-rules/16）
$asDoc = null;
$asId = (int)$getSet('as_doc_tree_print_as_doc_id');
if ($asId > 0) {
    $s = $db->prepare("SELECT doc_no, doc_name FROM as_document WHERE id=? AND is_deleted=0");
    $s->execute([$asId]); $asDoc = $s->fetch(PDO::FETCH_ASSOC) ?: null;
}
$title = $asDoc ? $asDoc['doc_name'] : 'AS 文件結構總覽';

// 內容：各階文件（只列送審快照涵蓋的階層，順序照快照）
$docs = $db->query("SELECT d.doc_no, d.doc_name, d.doc_level, d.current_version, dep.name AS dept_name, v.revised_date
                    FROM as_document d
                    LEFT JOIN department dep ON dep.id = d.department_id
                    LEFT JOIN as_document_version v ON v.id = d.current_version_id
                    WHERE d.is_deleted = 0 ORDER BY d.doc_no")->fetchAll(PDO::FETCH_ASSOC);
$levels = $snap ? array_column($snap['pages'] ?? [], 'level') : ['一階','二階','三階','四階'];
$dateOf = [];
foreach (($snap['pages'] ?? []) as $p) $dateOf[$p['level']] = $p['date'];
function tv_esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AS 文件結構總覽－審核</title>
<link href="../../resource/css/bootstrap.min.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.min.css" rel="stylesheet">
<style>
body{font-family:"Microsoft JhengHei",sans-serif;background:#faf6ef;color:#3b2f21;margin:0;padding:12px;}
.wrap{max-width:960px;margin:0 auto;background:#fff;border:1px solid #e6d9c3;border-radius:6px;padding:16px;}
.p-comp{font-size:20px;font-weight:bold;text-align:center;}
.p-title{font-size:16px;font-weight:bold;text-align:center;letter-spacing:4px;margin-bottom:8px;}
.p-boxes{font-size:13px;margin:10px 0 4px;}
.p-box{margin-right:16px;}
table.p-tb{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:4px;}
table.p-tb th,table.p-tb td{border:1px solid #999;padding:3px 5px;text-align:center;}
table.p-tb thead th{background:#f3ead6;}
table.p-tb td.tl{text-align:left;}
.p-sign{display:flex;font-size:13px;margin-bottom:14px;}
.p-sign div{flex:1;}
.p-sign .s-r{text-align:right;}
.bar{background:#fdf3e3;border:1px solid #e7cfa5;border-radius:4px;padding:8px 10px;margin-bottom:12px;font-size:13px;}
.b-ok{background:#F0A24B;border:0;color:#fff;padding:6px 18px;border-radius:4px;font-weight:bold;}
.b-no{background:#DD5138;border:0;color:#fff;padding:6px 18px;border-radius:4px;font-weight:bold;}
.mask{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9;align-items:center;justify-content:center;}
.mask.on{display:flex;}
.mbox{background:#fff;border-radius:6px;padding:14px;width:92%;max-width:420px;}
.err{color:#DD5138;font-size:12px;}
</style>
</head>
<body>
<div class="wrap">
<?php if (!$rec || !$snap): ?>
  <div class="bar">找不到這筆送審內容（可能已被處理或已重新送審）。</div>
<?php else: ?>
  <div class="bar">
    狀態：<b><?= $rec['status']==='pending' ? '待核准' : ($rec['status']==='approved' ? '已核准' : '已退回') ?></b>
    ｜送審：<?= tv_esc($snap['submitted_by_name'] ?? '') ?>（<?= tv_esc($snap['submitted_at'] ?? '') ?>）
    ｜核准人員：<?= tv_esc($snap['approver_name'] ?? '') ?>
    ｜修改簽章人員：<?= tv_esc($snap['editor_name'] ?? '') ?>
    <?php if (!empty($rec['note'])): ?><br>審核意見／退回原因：<b><?= tv_esc($rec['note']) ?></b><?php endif; ?>
    <?php if ($asDoc): ?><br>AS 文件編號：<?= tv_esc($asDoc['doc_no']) ?>　<?= tv_esc($asDoc['doc_name']) ?><?php endif; ?>
  </div>

  <div class="p-comp"><?= tv_esc($company) ?></div>
  <div class="p-title"><?= tv_esc($title) ?></div>

  <?php foreach ($levels as $lv):
        $rows = array_values(array_filter($docs, fn($d)=>($d['doc_level'] ?? '')===$lv));
        if (!$rows) continue; ?>
    <div class="p-boxes">
      <?php foreach ($levels as $l): ?><span class="p-box"><?= $l===$lv?'☑':'□' ?><?= tv_esc($l) ?>文件</span><?php endforeach; ?>
    </div>
    <table class="p-tb">
      <thead><tr><th style="width:6%;">項次</th><th style="width:16%;">文件編號</th><th>文件名稱</th>
        <th style="width:13%;">制訂單位</th><th style="width:8%;">版本</th><th style="width:13%;">發行日期</th><th style="width:12%;">備註</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $i=>$d): ?>
        <tr><td><?= $i+1 ?></td><td><?= tv_esc($d['doc_no']) ?></td><td class="tl"><?= tv_esc($d['doc_name']) ?></td>
        <td><?= tv_esc($d['dept_name']) ?></td><td><?= tv_esc($d['current_version']) ?></td>
        <td><?= tv_esc($d['revised_date']) ?></td><td></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="p-sign">
      <div>核准：<?= tv_esc($snap['approver_name'] ?? '') ?>（<?= tv_esc($dateOf[$lv] ?? '') ?>）</div>
      <div class="s-r">修改：<?= tv_esc($snap['editor_name'] ?? '') ?>（<?= tv_esc($dateOf[$lv] ?? '') ?>）</div>
    </div>
  <?php endforeach; ?>

  <?php if ($canSign): ?>
    <div style="text-align:center;margin-top:14px;">
      <button class="b-ok" id="btnAppr"><i class="fa fa-check"></i> 核准</button>
      <button class="b-no" id="btnRej"><i class="fa fa-undo"></i> 退回</button>
    </div>
  <?php elseif ($isPending): ?>
    <div class="bar" style="margin-top:12px;">本頁僅供檢視——只有指定的核准人員（<?= tv_esc($snap['approver_name'] ?? '') ?>）可以決行。</div>
  <?php endif; ?>
<?php endif; ?>
</div>

<div class="mask" id="rejMask"><div class="mbox">
  <div style="font-weight:bold;margin-bottom:6px;">退回文件結構總覽</div>
  <div style="font-size:12px;margin-bottom:6px;">退回<b>必須填寫原因</b>，原因會通知送審者並顯示在本頁。</div>
  <textarea id="rejNote" class="form-control" rows="3" placeholder="請說明退回原因"></textarea>
  <div class="err" id="rejErr"></div>
  <div style="text-align:right;margin-top:8px;">
    <button class="btn btn-default btn-sm" onclick="document.getElementById('rejMask').classList.remove('on')">取消</button>
    <button class="b-no" id="btnRejOk">確定退回</button>
  </div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script>
const API = '../../src/store/AS_Document_API.php';
const AP_ID = <?= (int)$apId ?>;
function decide(d, note){
    $.post(API, {action:'tree_approval_decide', approval_id:AP_ID, decision:d, note:note||''}, function(res){
        if (res.status !== 'success'){ alert(res.message||'處理失敗'); return; }
        alert(res.message);
        location.reload();
    }, 'json');
}
$('#btnAppr').on('click', function(){ if (confirm('確定核准這份文件結構總覽？核准後列印會顯示簽章。')) decide('approved',''); });
$('#btnRej').on('click', function(){ $('#rejErr').text(''); $('#rejNote').val(''); document.getElementById('rejMask').classList.add('on'); });
$('#btnRejOk').on('click', function(){
    const n = $.trim($('#rejNote').val());
    if (!n){ $('#rejErr').text('請填寫退回原因（必填）'); $('#rejNote').focus(); return; }
    decide('rejected', n);
});
</script>
</body>
</html>
