<?php
// =============================================================================
// views/pm/bom_reorder_review.php
// BOM 換製程順序：審核頁（通知點入；桌機與手機皆可用）。
//  - 設計／技術部門任一人可核准或退回（退回必填原因），一人決行後所有人的待審通知自動收回。
//  - 核准時才真的更換順序（邏輯在 src/common/bom_reorder_lib.php）。
//  - 結果通知（BOM_REORDER_RESULT）進來只顯示結果。
// 參數：?req=申請id[&event=通知id]
// =============================================================================
session_start();
if (!isset($_SESSION['id'])) {
    $_SESSION['lastpage'] = '/EGsystem/views/pm/bom_reorder_review.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '');
    header('Location: /EGsystem/index.php');
    exit();
}
include_once '../../src/common/DBConnection.php';
require_once '../../src/common/role_features_helper.php';
require_once '../../src/common/bom_reorder_lib.php';
$db  = (new DBConnection())->getPDO();
$uid = (int)$_SESSION['id'];
bro_ensure_schema($db);
$isAdm = oready_resolve_is_admin($db, $uid, '/EGsystem/views/pm/OreadyReply_ForPm_BaseOfTime.php');
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

$reqId = (int)($_REQUEST['req'] ?? 0);
$q = $db->prepare("SELECT * FROM bom_reorder_req WHERE req_id=?");
$q->execute([$reqId]);
$req = $q->fetch(PDO::FETCH_ASSOC);

if ($req && ($_POST['do'] ?? '') !== '') {   // 核准／退回
    header('Content-Type: application/json; charset=utf-8');
    session_write_close();
    echo json_encode(bro_decide($db, $reqId, $_POST['do'] === 'approve', (string)($_POST['note'] ?? ''), $uid, $isAdm), JSON_UNESCAPED_UNICODE);
    exit;
}
if (!$req) { echo '<meta charset="utf-8"><p style="padding:20px;">找不到這張換順序申請</p>'; exit; }

$brief = json_decode((string)$req['brief_json'], true) ?: [];
$info  = $brief['info'] ?? [];
$canDecide = $req['status'] === 'pending' && bro_can_decide($db, $req, $uid, $isAdm);
$des = bro_designers($db, (int)($info['designer_id'] ?? 0));
$bom = (string)$req['bom'];
$groups = bro_groups($db, $bom);
$reqName = bro_user_name($db, $req['requested_by']);
$decName = bro_user_name($db, $req['decided_by']);
$stTxt = ['pending' => '等待設計部門審核', 'approved' => '已核准（順序已更換）', 'rejected' => '已退回'][$req['status']] ?? $req['status'];
$partLink = !empty($info['part_pk']) ? 'part_viewer.php?pk=' . (int)$info['part_pk'] : 'part_viewer.php?d_id=' . rawurlencode((string)($info['part_no'] ?? ''));
?>
<!DOCTYPE html>
<html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>換製程順序審核</title>
<style>
body{margin:0;background:#FDF7EC;color:#4a3320;font-family:"Microsoft JhengHei",sans-serif;font-size:14px;}
.wrap{max-width:760px;margin:0 auto;padding:16px;}
h2{margin:0 0 10px;font-size:18px;}
.card{background:#fff;border:1px solid #E4D3BC;border-radius:6px;padding:12px 14px;margin-bottom:12px;}
.kv{margin:4px 0;line-height:1.6;} .kv b{display:inline-block;min-width:84px;color:#8a6a45;}
a{color:#B8431F;}
table{border-collapse:collapse;width:100%;font-size:13px;} th,td{border-bottom:1px solid #eee;padding:5px 6px;text-align:left;}
tr.old td{background:#FBE3DC;text-decoration:line-through;text-decoration-color:#B8431F;}
tr.new td{background:#E3EDD2;font-weight:bold;} .newb{display:inline-block;background:#F6C94A;color:#5a3d00;font-size:11px;font-weight:bold;padding:0 6px;border-radius:8px;}
.st{display:inline-block;padding:2px 8px;border-radius:10px;font-weight:bold;background:#F0A24B;color:#5a2d0c;}
.st.approved{background:#8a6a45;color:#fff;} .st.rejected{background:#DD5138;color:#fff;}
textarea{width:100%;box-sizing:border-box;min-height:60px;padding:6px;border:1px solid #d9c2a3;border-radius:4px;font-size:14px;}
button{padding:8px 18px;border:0;border-radius:4px;font-size:14px;font-weight:bold;cursor:pointer;margin-right:8px;}
.ok{background:#8a6a45;color:#fff;} .no{background:#DD5138;color:#fff;} button:disabled{opacity:.5;}
</style></head><body><div class="wrap">
<h2>換製程順序審核　<span class="st <?= $h($req['status']) ?>"><?= $h($stTxt) ?></span></h2>
<div class="card">
  <div class="kv"><b>BOM</b><a href="OreadyReply_ForPm_BaseOfTime.php?bom_only=<?= $h(rawurlencode($bom)) ?>" target="_blank" rel="noopener" title="只顯示這一筆 BOM 的 BOM 總覽"><?= $h($bom) ?></a></div>
  <div class="kv"><b>料號</b><a href="<?= $h($partLink) ?>" target="_blank" rel="noopener" title="開啟料號圖面"><?= $h($info['part_no'] ?? '') ?></a></div>
  <div class="kv"><b>數量</b><?= $h(rtrim(rtrim(number_format((float)($info['qty'] ?? 0), 3, '.', ''), '0'), '.')) ?></div>
  <?php if (!empty($info['order_oo'])): ?><div class="kv"><b>訂單</b><?= $h($info['order_oo']) ?></div><?php endif; ?>
  <div class="kv"><b>申請內容</b><?= $h(preg_replace('/（SN 變更：.*）$/u', '', (string)($brief['desc'] ?? ''))) ?></div>
  <div class="kv"><b>申請人</b><?= $h($reqName) ?>　<?= $h(substr((string)$req['requested_at'], 0, 16)) ?></div>
  <?php if (trim((string)$req['reason']) !== ''): ?><div class="kv"><b>備註</b><?= $h($req['reason']) ?></div><?php endif; ?>
  <div class="kv"><b>負責設計</b><?= $h($des['primary'] ? bro_user_name($db, $des['primary']) : '（訂單未指定）') ?>　<span style="font-size:12px;color:#8a6a45;">設計／技術部門任一人皆可審核</span></div>
  <?php if ($req['status'] !== 'pending'): ?>
  <div class="kv"><b>審核</b><?= $h($decName) ?>　<?= $h(substr((string)$req['decided_at'], 0, 16)) ?></div>
  <?php if (trim((string)$req['decide_note']) !== ''): ?><div class="kv"><b>審核備註</b><?= $h($req['decide_note']) ?></div><?php endif; endif; ?>
</div>
<div class="card"><b>目前製程順序與狀態</b>
  <table><tr><th>SN</th><th>製程</th><th>狀態</th><th>QC</th></tr>
  <?php
  // 申請中：舊位置（淺紅底＋刪除線，文字照常可讀）與新位置（暖綠底＋NEW）同時列出
  $rows = [];
  $mvSn = (int)$req['moved_sn'];
  $showNew = false;
  if ($req['status'] === 'pending') {
      $plan = bro_plan($groups, $mvSn, (int)$req['target_sn'], (string)$req['pos'], $req['new_sn'] !== null, $req['new_sn'] !== null ? (int)$req['new_sn'] : null);
      if ($plan['ok']) {
          $showNew = true;
          $ord = $plan['order']; $ix = array_search($mvSn, $ord, true);
          $pred = $ix > 0 ? $ord[$ix - 1] : null;
          $newSn = $plan['map'][$mvSn] ?? $mvSn;
      }
  }
  $mvG = null; foreach ($groups as $g) if ((int)$g['sn'] === $mvSn) $mvG = $g;
  if ($showNew && $pred === null) $rows[] = ['g' => $mvG, 'kind' => 'new', 'sn' => $newSn];
  foreach ($groups as $g) {
      $rows[] = ['g' => $g, 'kind' => ($showNew && (int)$g['sn'] === $mvSn) ? 'old' : '', 'sn' => $g['sn']];
      if ($showNew && $pred !== null && (int)$g['sn'] === (int)$pred) $rows[] = ['g' => $mvG, 'kind' => 'new', 'sn' => $newSn];
  }
  foreach ($rows as $r): $g = $r['g']; ?>
    <tr class="<?= $r['kind'] ?>"><td><?= ($r['kind'] === 'new' && $req['new_sn'] === null) ? '<span style="font-size:11px;color:#8a6a45;font-weight:normal;" title="未開放自行輸入 SN，核准後系統依新順序自動重新編號">自動</span>' : (int)$r['sn'] ?></td><td><span class="nm"><?= $h($g['name'] !== '' ? $g['name'] : $g['process_no']) ?></span><?= $r['kind'] === 'new' ? ' <span class="newb">NEW</span>' : '' ?><?= $r['kind'] === 'old' ? ' <span style="font-size:11px;color:#B8431F;">（原位置）</span>' : '' ?></td>
    <td><?= $h(bro_state_text($g['state'])) ?></td><td><?= $g['qc_completed'] ? '已完工' : ($g['QC_check'] ? '已檢驗未完工' : '—') ?></td></tr>
  <?php endforeach; ?></table>
  <?php if ($showNew && $req['new_sn'] === null): ?><div style="font-size:12px;color:#8a6a45;margin-top:6px;">未開放自行輸入 SN：畫面上的 SN 欄維持原有號碼組；核准後系統會依新順序自動重新編號（資料庫內 SN 隨之調整）。</div><?php endif; ?>
</div>
<?php if ($canDecide): ?>
<div class="card">
  <div class="kv"><b>審核備註</b>（核准選填；<span style="color:#B8431F;">退回必填</span>）</div>
  <textarea id="note" placeholder="備註…"></textarea>
  <p style="margin:10px 0 0;"><button class="ok" id="bOk">核准（更換順序）</button><button class="no" id="bNo">退回</button></p>
  <div id="msg" style="margin-top:8px;color:#B8431F;"></div>
</div>
<script>
function go(d){
  var note=document.getElementById('note').value.trim();
  if(d==='reject'&&!note){document.getElementById('msg').textContent='退回必須填寫原因';return;}
  if(d==='approve'&&!confirm('核准後會立刻更換製程順序（SN 與相關移轉憑單一併更新），確定嗎？'))return;
  document.getElementById('bOk').disabled=document.getElementById('bNo').disabled=true;
  var fd=new FormData();fd.append('do',d);fd.append('note',note);
  fetch('bom_reorder_review.php?req=<?= $reqId ?>',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(r){
    if(!r.success){document.getElementById('msg').textContent=r.message||'失敗';document.getElementById('bOk').disabled=document.getElementById('bNo').disabled=false;return;}
    alert(r.message);location.reload();
  }).catch(function(){document.getElementById('msg').textContent='與伺服器通訊失敗';document.getElementById('bOk').disabled=document.getElementById('bNo').disabled=false;});
}
document.getElementById('bOk').onclick=function(){go('approve');};
document.getElementById('bNo').onclick=function(){go('reject');};
</script>
<?php elseif ($req['status'] === 'pending'): ?>
<div class="card" style="color:#8a6a45;">您不是這張申請的審核人員（僅負責設計與設計／技術部門人員可審核）。</div>
<?php endif; ?>
</div></body></html>
