<?php
/**
 * 製程中紀錄（唯讀總覽）— 2026-11-17 建立（使用者交辦）
 *
 * 入口：訂單追蹤（NewOrder_Track.php）標題右側「製程中紀錄」按鈕，另開新分頁、一律唯讀。
 * 資料：eng_log（log_type='order_process'，一張訂單一個案件）→ eng_log_item → eng_log_reply。
 * 案件綁定：eng_log_bind 的 order／part／bom 三種（bom 來自 bom_order_process_map），
 *   所以每筆紀錄都能對到訂單編號與 BOM 編號。依「客戶 → 料號」分組顯示。
 *
 * 本頁完全不寫入任何資料，也不動訂單追蹤既有的製程中紀錄跳窗（嚴禁影響現有使用者）。
 * 權限：與跳窗的檢視權相同——持有任一 ot_proc_* 功能碼（新制）；都沒有則沿用舊制門檻 ot_edit；管理員一律可。
 */
ini_set('session.gc_maxlifetime', 43200);
session_set_cookie_params(43200);
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/Sales/Process_Log_View.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/role_features_helper.php';

$db  = (new DBConnection())->getPDO();
$uid = (int)($_SESSION['id'] ?? 0);

$feat    = rf_load_user_features_all($db, $uid);
$isAdmin = in_array('all', $feat, true);
$procNew = false;
foreach (['ot_proc_edit','ot_proc_delete','ot_proc_reply','ot_proc_ask','ot_proc_ps','ot_proc_view'] as $c) {
    if (in_array($c, $feat, true)) { $procNew = true; break; }
}
$canView = $isAdmin || $procNew || in_array('ot_edit', $feat, true);

function plvEsc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ── 資料（POST action=list，只讀）────────────────────────────────────── */
if (($_POST['action'] ?? '') === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$canView) { echo json_encode(['success' => false, 'message' => '沒有檢視製程中紀錄的權限']); exit; }
    try {
        $kw = trim((string)($_POST['kw'] ?? ''));
        $cust = trim((string)($_POST['customer'] ?? ''));
        $part = trim((string)($_POST['part'] ?? ''));
        $onlyOpen = !empty($_POST['only_open']);

        $where = ["el.log_type='order_process'",
                  "EXISTS (SELECT 1 FROM eng_log_item i0 WHERE i0.log_id=el.id AND i0.voided_at IS NULL)"];
        $par = [];
        if ($cust !== '') { $where[] = "ot.Client_name LIKE ?"; $par[] = '%' . $cust . '%'; }
        if ($part !== '') { $where[] = "ot.d_id LIKE ?"; $par[] = '%' . $part . '%'; }
        if ($onlyOpen) $where[] = "EXISTS (SELECT 1 FROM eng_log_item i1 WHERE i1.log_id=el.id AND i1.voided_at IS NULL AND i1.is_note=0 AND i1.status NOT IN ('resolved','dropped'))";
        foreach (preg_split('/\s+/u', $kw, -1, PREG_SPLIT_NO_EMPTY) as $w) {
            $where[] = "(ot.Client_name LIKE ? OR ot.d_id LIKE ? OR ot.Order_oo LIKE ?
                         OR EXISTS (SELECT 1 FROM eng_log_bind bb WHERE bb.log_id=el.id AND bb.bind_type='bom' AND bb.bind_id LIKE ?)
                         OR EXISTS (SELECT 1 FROM eng_log_item ik WHERE ik.log_id=el.id AND ik.voided_at IS NULL AND ik.question LIKE ?)
                         OR EXISTS (SELECT 1 FROM eng_log_item ik2 JOIN eng_log_reply rk ON rk.item_id=ik2.id
                                    WHERE ik2.log_id=el.id AND ik2.voided_at IS NULL AND rk.content LIKE ?))";
            $lk = '%' . $w . '%';
            array_push($par, $lk, $lk, $lk, $lk, $lk, $lk);
        }
        $sql = "SELECT el.id AS log_id, CAST(b.bind_id AS UNSIGNED) AS order_id,
                       ot.Order_oo, ot.d_id AS part_no, ot.Client_name,
                       (SELECT MAX(COALESCE(i2.asked_at, i2.created_at)) FROM eng_log_item i2 WHERE i2.log_id=el.id) AS last_at
                FROM eng_log el
                JOIN eng_log_bind b ON b.log_id=el.id AND b.bind_type='order'
                LEFT JOIN order_track ot ON ot.Order_id = CAST(b.bind_id AS UNSIGNED)
                WHERE " . implode(' AND ', $where) . "
                ORDER BY ot.Client_name, ot.d_id, last_at DESC
                LIMIT 300";
        $st = $db->prepare($sql);
        $st->execute($par);
        $logs = $st->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        $logIds = array_map(fn($r) => (int)$r['log_id'], $logs);
        $boms = []; $items = []; $replies = []; $names = [];
        if ($logIds) {
            $in = implode(',', $logIds);
            foreach ($db->query("SELECT log_id, bind_id FROM eng_log_bind WHERE bind_type='bom' AND log_id IN ({$in}) ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC) as $r)
                $boms[(int)$r['log_id']][] = (string)$r['bind_id'];
            foreach ($db->query("SELECT id, log_id, question, target_label, is_note, asked_at, status, conclusion, created_by, created_at
                                 FROM eng_log_item WHERE log_id IN ({$in}) AND voided_at IS NULL ORDER BY seq, id")->fetchAll(PDO::FETCH_ASSOC) as $r)
                $items[(int)$r['log_id']][] = $r;
            $iids = [];
            foreach ($items as $arr) foreach ($arr as $r) $iids[] = (int)$r['id'];
            if ($iids) {
                $ii = implode(',', $iids);
                foreach ($db->query("SELECT item_id, replied_on, reply_by, channel, content, created_by FROM eng_log_reply WHERE item_id IN ({$ii}) ORDER BY replied_on, id")->fetchAll(PDO::FETCH_ASSOC) as $r)
                    $replies[(int)$r['item_id']][] = $r;
            }
            $uids = [];
            foreach ($items as $arr) foreach ($arr as $r) if (!empty($r['created_by'])) $uids[(int)$r['created_by']] = 1;
            foreach ($replies as $arr) foreach ($arr as $r) if (!empty($r['created_by'])) $uids[(int)$r['created_by']] = 1;
            if ($uids) {
                foreach ($db->query("SELECT id, user_cname FROM `user` WHERE id IN (" . implode(',', array_keys($uids)) . ")")->fetchAll(PDO::FETCH_ASSOC) as $u)
                    $names[(int)$u['id']] = (string)$u['user_cname'];
            }
        }
        foreach ($logs as $l) {
            $lid = (int)$l['log_id'];
            $its = [];
            foreach ($items[$lid] ?? [] as $it) {
                $rp = [];
                foreach ($replies[(int)$it['id']] ?? [] as $r)
                    $rp[] = ['on' => $r['replied_on'], 'by' => $r['reply_by'], 'ch' => $r['channel'], 'content' => $r['content'],
                             'who' => $names[(int)$r['created_by']] ?? ''];
                $its[] = ['question' => $it['question'], 'target' => $it['target_label'], 'is_note' => (int)$it['is_note'],
                          'at' => $it['asked_at'] ?: $it['created_at'], 'status' => $it['status'], 'conclusion' => $it['conclusion'],
                          'who' => $names[(int)$it['created_by']] ?? '', 'replies' => $rp];
            }
            $out[] = ['order_id' => (int)$l['order_id'], 'order_no' => $l['Order_oo'], 'part' => $l['part_no'],
                      'customer' => $l['Client_name'], 'boms' => $boms[$lid] ?? [], 'items' => $its];
        }
        echo json_encode(['success' => true, 'logs' => $out, 'truncated' => count($logs) >= 300], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => '查詢失敗：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$initCust = trim((string)($_GET['customer'] ?? ''));
$initPart = trim((string)($_GET['part'] ?? ''));
$initKw   = trim((string)($_GET['kw'] ?? ''));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>製程中紀錄</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
#sidebar-menu { visibility: hidden; }
:root{ --ink:#4A3524; --sand:#F7E0BD; --amber:#F0A24B; --amber-d:#C77C1A; --coral:#DD5138; --line:#E4D3BC; --muted:#a08a6f; }
body { background:#F6F1EA; }
.right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
.page-title h3 { color:var(--ink); margin:0; font-size:22px; }
.role-tag { font-size:12px; background:#EFE3CF; color:#6B4423; border-radius:10px; padding:2px 10px; font-weight:normal; }
.warm-panel { background:#fff; border:1px solid var(--line); border-radius:8px; padding:12px 14px; margin-bottom:12px; }
.plv-bar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.plv-bar input[type=text] { height:30px; padding:0 8px; border:1px solid var(--line); border-radius:4px; width:170px; }
.plv-note { background:#faf6f0; border:1px solid var(--line); border-left:4px solid var(--amber); border-radius:6px;
            padding:8px 12px; font-size:12px; color:#6B4423; line-height:1.8; margin-bottom:12px; }
.plv-cust { background:#8a5a2b; color:#fff; border-radius:6px 6px 0 0; padding:6px 12px; font-weight:bold; margin-top:14px; }
.plv-part { background:var(--sand); color:var(--ink); padding:5px 12px; font-weight:bold; border:1px solid var(--line); border-top:0; }
.plv-ord { background:#fff; border:1px solid var(--line); border-top:0; padding:8px 12px; }
.plv-ord-h { font-size:12px; color:var(--muted); margin-bottom:6px; }
.plv-ord-h b { color:var(--ink); }
.plv-bom { display:inline-block; background:#EFE3CF; color:#6B4423; border-radius:10px; padding:0 8px; margin-right:4px; font-size:12px; }
.plv-item { border-left:3px solid var(--amber); padding:4px 10px; margin:6px 0; background:#FCF7F0; }
.plv-item.done { border-left-color:#b9a587; opacity:.85; }
.plv-q { color:var(--ink); white-space:pre-wrap; }
.plv-meta { font-size:12px; color:var(--muted); }
.plv-st { font-size:11px; border-radius:9px; padding:0 8px; line-height:18px; display:inline-block; }
.plv-st.open { background:var(--amber); color:var(--ink); }
.plv-st.done { background:#d9cbb4; color:#6B4423; }
.plv-st.note { background:#EFE3CF; color:#6B4423; }
.plv-rp { margin:3px 0 0 14px; padding-left:8px; border-left:2px dotted var(--line); font-size:13px; white-space:pre-wrap; }
.plv-empty { text-align:center; color:var(--muted); padding:30px; }
</style>
</head>
<body class="nav-sm">
<?php include '../partPage/sideAndTopBarMenu.html'; ?>
<div class="right_col" role="main">
  <div class="page-title">
    <h3>製程中紀錄 <span class="role-tag">唯讀檢視</span></h3>
  </div>
  <div class="clearfix"></div>
<?php if (!$canView): ?>
  <div class="warm-panel">您沒有檢視製程中紀錄的權限，請洽管理員於訂單追蹤的「角色設定」開通。</div>
<?php else: ?>
  <div class="plv-note">
    這裡只能<b>查看</b>，不能新增、編輯或回覆。每筆紀錄依「客戶 → 料號」分組；紀錄綁定的是<b>訂單</b>，
    並一併列出該訂單對應的 <b>BOM 編號</b>（BOM 還沒開出來的訂單會顯示「尚無 BOM」）。要新增或回覆請回訂單追蹤。
  </div>
  <div class="warm-panel">
    <div class="plv-bar">
      <input type="text" id="fCust" placeholder="客戶" value="<?= plvEsc($initCust) ?>">
      <input type="text" id="fPart" placeholder="料號" value="<?= plvEsc($initPart) ?>">
      <input type="text" id="fKw" placeholder="全文搜尋（訂單／BOM／問題／回覆）" style="width:260px;" value="<?= plvEsc($initKw) ?>">
      <label style="margin:0;font-weight:normal;"><input type="checkbox" id="fOpen"> 只看尚有未處理</label>
      <button class="btn btn-sm btn-warning" id="btnGo"><i class="fa fa-search"></i> 查詢</button>
      <span id="plvCnt" class="plv-meta"></span>
    </div>
  </div>
  <div id="plvBody"><div class="plv-empty">載入中…</div></div>
<?php endif; ?>
</div>
<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function(){ $('#sidebar-menu').css('visibility','visible'); });
<?php if ($canView): ?>
(function(){
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
  function dd(s){ return s ? (window.egFmtDate ? egFmtDate(String(s).substr(0,10)) : String(s).substr(0,10)) : ''; }
  var ST = {open:'待處理', replied:'已回覆', resolved:'已處理', dropped:'不處理', waiting:'等待回覆'};
  function load(){
    $('#plvBody').html('<div class="plv-empty">載入中…</div>');
    $.post('', {action:'list', customer:$('#fCust').val(), part:$('#fPart').val(), kw:$('#fKw').val(), only_open:$('#fOpen').is(':checked')?1:0}, function(res){
      if (!res || !res.success) { $('#plvBody').html('<div class="plv-empty">'+esc(res&&res.message||'載入失敗')+'</div>'); return; }
      render(res);
    }, 'json').fail(function(){ $('#plvBody').html('<div class="plv-empty">載入失敗，請重新整理</div>'); });
  }
  function render(res){
    var logs = res.logs || [], h = '', lastC = null, lastP = null;
    $('#plvCnt').text('共 ' + logs.length + ' 張訂單有製程中紀錄' + (res.truncated ? '（僅顯示前 300 張，請縮小條件）' : ''));
    if (!logs.length) { $('#plvBody').html('<div class="plv-empty">沒有符合條件的製程中紀錄</div>'); return; }
    logs.forEach(function(l){
      var c = l.customer || '（未建客戶）', p = l.part || '（無料號）';
      if (c !== lastC) { h += '<div class="plv-cust"><i class="fa fa-user"></i> ' + esc(c) + '</div>'; lastC = c; lastP = null; }
      if (p !== lastP) { h += '<div class="plv-part">' + esc(p) + '</div>'; lastP = p; }
      h += '<div class="plv-ord"><div class="plv-ord-h">訂單 <b>' + esc(l.order_no || ('#'+l.order_id)) + '</b>　BOM：';
      h += (l.boms && l.boms.length) ? l.boms.map(function(b){return '<span class="plv-bom">'+esc(b)+'</span>';}).join('') : '<span style="color:var(--coral)">尚無 BOM</span>';
      h += '</div>';
      (l.items||[]).forEach(function(it){
        var isDone = (it.status==='resolved'||it.status==='dropped');
        var tag = it.is_note ? '<span class="plv-st note">備註</span>' : '<span class="plv-st '+(isDone?'done':'open')+'">'+esc(ST[it.status]||it.status)+'</span>';
        h += '<div class="plv-item'+(isDone?' done':'')+'"><div>'+tag+' <span class="plv-meta">'+esc(dd(it.at))+' '+esc(it.who)+(it.target?'　對象：'+esc(it.target):'')+'</span></div>';
        h += '<div class="plv-q">'+esc(it.question)+'</div>';
        (it.replies||[]).forEach(function(r){
          h += '<div class="plv-rp"><span class="plv-meta">'+esc(dd(r.on))+' '+esc(r.by||r.who||'')+(r.ch?'（'+esc(r.ch)+'）':'')+'</span><br>'+esc(r.content)+'</div>';
        });
        if (it.conclusion) h += '<div class="plv-meta">結論：'+esc(it.conclusion)+'</div>';
        h += '</div>';
      });
      h += '</div>';
    });
    $('#plvBody').html(h);
  }
  $('#btnGo').on('click', load);
  $('#fCust,#fPart,#fKw').on('keydown', function(e){ if (e.which===13) { e.preventDefault(); load(); } });
  $('#fOpen').on('change', load);
  load();
})();
<?php endif; ?>
</script>
</body>
</html>
