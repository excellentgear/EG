<?php
/**
 * QaAbnormal_API.php — 品質異常處理單（2-QA-01-01）新版表單／清單／設定的資料介面
 * 建立：2026-09-18
 *
 * 分工（刻意不與舊的 store_QA_Abnormal_API.php 混在一起）：
 *   本檔＝表單各段填寫、逐輪徵詢意見、決策、總經理裁示、扣款確認、結案、代碼表設定、清單。
 *   舊檔＝QC 檢驗跳窗開單、附件上傳與轉檔、共同編輯者、通知/追蹤名單。
 *   兩邊共用的規則（編號、代碼表、權限、最終處置、報廢單號、扣款帶入）一律放
 *   src/common/qa_abnormal_lib.php，不在這裡再寫一份。
 *
 * 鐵律8：前端擋過的每一條，這裡一律再擋一次（權限、結案後不可改、選項必須存在、金額必須是數字）。
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../common/_config.php';
require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/qa_abnormal_lib.php';
require_once __DIR__ . '/../common/qa_notify.php';
require_once __DIR__ . '/../common/people_lib.php';
require_once __DIR__ . '/../common/org_role_lib.php';

function jout($ok, $data = []) { echo json_encode(array_merge(['success' => $ok], is_array($data) ? $data : ['message' => $data]), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code = '') { jout(false, ['message' => $msg, 'code' => $code]); }

$uid = (int)($_SESSION['id'] ?? 0);
if ($uid <= 0) { http_response_code(401); jerr('尚未登入或登入已逾時，請重新整理頁面後再試', 'LOGIN'); }

$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
qab_ensure_schema($db);
$perms = qab_perms($db, $uid);
if (!$perms['canView']) { http_response_code(403); jerr('沒有品質異常單的檢視權限'); }

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$isWrite = isset($_POST['action']);
if ($isWrite) {
    $tok = $_POST['csrf'] ?? '';
    if (empty($_SESSION['qab_csrf']) || !hash_equals((string)$_SESSION['qab_csrf'], (string)$tok)) {
        jerr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 'CSRF');
    }
}

/* ── 小工具 ───────────────────────────────────────────── */
$intOrNull = function ($v) { $v = trim((string)$v); return $v === '' ? null : (int)$v; };
$numOrNull = function ($v) { $v = trim((string)$v); return ($v === '' || !is_numeric($v)) ? null : (float)$v; };
$strOrNull = function ($v, $max = 255) { $v = trim((string)$v); return $v === '' ? null : mb_substr($v, 0, $max); };

/** 取單並檢查存在；$needOpen=true 時結案單一律擋下（只有管理員能先取消結案再改） */
$mustOrder = function (int $id, bool $needOpen = true) use ($db, $perms) {
    $o = qab_order($db, $id);
    if (!$o) jerr('找不到這張異常單');
    if ($needOpen && (int)$o['is_closed'] === 1) jerr('這張單已結案，不可再修改；要修改請先由管理員取消結案', 'CLOSED');
    return $o;
};

/** 寫一筆編輯記錄（沿用舊表，讓兩套介面的歷程看得到同一份） */
$log = function (int $orderId, string $field, $old, $new, string $reason = '') use ($db, $uid) {
    try {
        $db->prepare("INSERT INTO qa_abnormal_edit_log (abnormal_order_id, edited_by, field_name, old_value, new_value, edit_reason, edited_at)
                      VALUES (?,?,?,?,?,?,NOW())")
           ->execute([$orderId, $uid, $field, is_array($old) ? json_encode($old, JSON_UNESCAPED_UNICODE) : (string)$old,
                      is_array($new) ? json_encode($new, JSON_UNESCAPED_UNICODE) : (string)$new, $reason]);
    } catch (Throwable $e) { /* 記錄失敗不影響主要動作 */ }
};

try {
switch ($action) {

/* ═══════════ 清單 ═══════════ */
case 'list': {
    $rows = qab_list($db, [
        'year'   => $_GET['year'] ?? '',
        'month'  => $_GET['month'] ?? '',
        'closed' => $_GET['closed'] ?? '',
        'source' => $_GET['source'] ?? '',
        'kw'     => trim((string)($_GET['kw'] ?? '')),
    ]);
    jout(true, ['rows' => $rows, 'perms' => $perms]);
}

/* ═══════════ 開單 ═══════════ */
case 'create': {
    if (!$perms['canCreate']) jerr('沒有開立品質異常單的權限');
    $kind = ($_POST['kind'] ?? '') === 'ir' ? 'ir' : 'bom';
    $fillDate = trim((string)($_POST['fill_date'] ?? '')) ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fillDate)) jerr('填寫日期格式不正確');

    $irId = null; $irNo = null; $bomNo = $strOrNull($_POST['bom_no'] ?? '', 30);
    $client = $strOrNull($_POST['client_name'] ?? '', 60);
    $partNo = $strOrNull($_POST['part_no'] ?? '', 60);
    $batch  = $intOrNull($_POST['batch_qty'] ?? '');

    if ($kind === 'ir') {
        $irId = (int)($_POST['ir_id'] ?? 0);
        if ($irId <= 0) jerr('客退來源請先選擇客退單（IR）');
        $st = $db->prepare("SELECT IR_id, IR_no, Client_name, d_id, Qty FROM ir_track WHERE IR_id=?");
        $st->execute([$irId]);
        $ir = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ir) jerr('找不到這張客退單');
        $irNo = (string)$ir['IR_no'];
        if ($client === null) $client = $ir['Client_name'] !== '' ? mb_substr((string)$ir['Client_name'], 0, 60) : null;
        if ($partNo === null) $partNo = $ir['d_id'] !== '' ? mb_substr((string)$ir['d_id'], 0, 60) : null;
        if ($batch === null)  $batch  = $ir['Qty'] !== null ? (int)$ir['Qty'] : null;
    } else {
        if ($bomNo === null) jerr('製程來源請先選擇製令編號');
        $st = $db->prepare("SELECT bom, d_id, Client_Name, sqty FROM bom WHERE bom=?");
        $st->execute([$bomNo]);
        $b = $st->fetch(PDO::FETCH_ASSOC);
        if (!$b) jerr('找不到這張製令');
        if ($client === null) $client = $b['Client_Name'] !== '' ? mb_substr((string)$b['Client_Name'], 0, 60) : null;
        if ($partNo === null) $partNo = $b['d_id'] !== '' ? mb_substr((string)$b['d_id'], 0, 60) : null;
        if ($batch === null)  $batch  = $b['sqty'] !== null ? (int)$b['sqty'] : null;
    }
    // 客退來源也可以另外綁製令（使用者要求：亦可不選）
    if ($kind === 'ir' && $bomNo !== null) {
        $chk = $db->prepare("SELECT 1 FROM bom WHERE bom=?");
        $chk->execute([$bomNo]);
        if (!$chk->fetchColumn()) jerr('要綁定的製令編號不存在');
    }

    $db->beginTransaction();
    try {
        $no = qab_next_order_no($db, $fillDate);
        $db->prepare("INSERT INTO qa_abnormal_order
            (abnormal_order_no, source_type, source_id, occurrence_date, fill_date, found_unit,
             ir_id, ir_no, bom_no, client_name, part_no, batch_qty, insp_qty, ng_qty,
             abnormal_phenomenon, created_by, created_at, surcharge_rate)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)")
           ->execute([$no, ($kind === 'ir' ? 'IR' : 'BOM'), (int)($irId ?: 0), $fillDate, $fillDate,
                      ($kind === 'ir' ? '客退' : '廠內'), $irId, $irNo, $bomNo, $client, $partNo, $batch,
                      $intOrNull($_POST['insp_qty'] ?? ''), $intOrNull($_POST['ng_qty'] ?? ''),
                      $strOrNull($_POST['abnormal_phenomenon'] ?? '', 2000), $uid, qab_default_rate($db)]);
        $id = (int)$db->lastInsertId();
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    $log($id, 'create', '', $no);
    jout(true, ['id' => $id, 'no' => $no]);
}

/* ═══════════ 讀一張單（表單頁與列印共用） ═══════════ */
case 'get': {
    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    $o = qab_order($db, $id);
    if (!$o) jerr('找不到這張異常單');
    jout(true, [
        'order'     => $o,
        'perms'     => $perms,
        'can_edit'  => qab_can_edit_form($db, $perms, $o),
        'causes'    => qab_cause_tree($db, true),
        'disp_opts' => qab_options($db, 'disp'),
        'gm_opts'   => qab_options($db, 'gm'),
        'deciders'  => qab_decider_cfgs($db, 'decider'),
        'tops'      => qab_decider_cfgs($db, 'top'),
        'rate_default' => qab_default_rate($db),
        // 「這一輪是不是我可以回覆」前端要用：本人掛在哪些部門
        'my_dept_ids'  => qab_user_dept_ids($db, $uid),
    ]);
}

/* ═══════════ 填寫區（表頭、現象、原因分類、量測值） ═══════════ */
case 'save_head': {
    $o = $mustOrder((int)($_POST['id'] ?? 0));
    if (!qab_can_edit_form($db, $perms, $o)) jerr('沒有修改這張異常單的權限');
    $id = (int)$o['id'];

    $fill = trim((string)($_POST['fill_date'] ?? ''));
    if ($fill !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fill)) jerr('填寫日期格式不正確');
    $occ = trim((string)($_POST['occurrence_date'] ?? ''));
    if ($occ !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $occ)) jerr('異常發生日期格式不正確');

    // 責任單位：製程＋廠商；廠商若為「廠內加工廠商」才可再選部門與人員（非必填）
    $procNo = $intOrNull($_POST['resp_process_no'] ?? '');
    if ($procNo !== null) {
        $c = $db->prepare("SELECT 1 FROM process_no WHERE ProcessNo=?"); $c->execute([$procNo]);
        if (!$c->fetchColumn()) jerr('選擇的製程不存在');
    }
    $vendorId = $strOrNull($_POST['responsible_vendor_id'] ?? '', 11);
    $isInternal = 0; $vendorName = '';
    if ($vendorId !== null) {
        $c = $db->prepare("SELECT maker_id, internal FROM maker_list WHERE maker_id_no=?"); $c->execute([$vendorId]);
        $v = $c->fetch(PDO::FETCH_ASSOC);
        if (!$v) jerr('選擇的廠商不存在');
        $isInternal = (int)($v['internal'] ?? 0) === 1 ? 1 : 0;
        $vendorName = (string)$v['maker_id'];
    }
    // 責任單位顯示字串：製程＋廠商（清單、不合格品記錄表都讀這一欄）
    $respUnit = trim(implode(' / ', array_filter([
        $procNo !== null ? (string)$db->query("SELECT ProcessName FROM process_no WHERE ProcessNo=" . (int)$procNo)->fetchColumn() : '',
        $vendorName,
    ])));

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE qa_abnormal_order SET
                        fill_date=?, occurrence_date=?, client_name=?, part_no=?, ir_no=?, bom_no=?,
                        batch_qty=?, insp_qty=?, ng_qty=?, sqty=?, abnormal_phenomenon=?, defect_detail=?, qa_ps=?,
                        resp_process_no=?, responsible_vendor_id=?, resp_is_internal=?, responsible_unit=?,
                        decider_cfg_id=?, decider_user_id=?, updated_by=?, updated_at=NOW()
                      WHERE id=?")
           ->execute([
               $fill ?: $o['fill_date'], $occ ?: $o['occurrence_date'],
               $strOrNull($_POST['client_name'] ?? '', 60), $strOrNull($_POST['part_no'] ?? '', 60),
               $strOrNull($_POST['ir_no'] ?? '', 30), $strOrNull($_POST['bom_no'] ?? '', 30),
               $intOrNull($_POST['batch_qty'] ?? ''), $intOrNull($_POST['insp_qty'] ?? ''),
               $intOrNull($_POST['ng_qty'] ?? ''), $intOrNull($_POST['ng_qty'] ?? ''),
               $strOrNull($_POST['abnormal_phenomenon'] ?? '', 2000),
               $strOrNull($_POST['defect_detail'] ?? '', 2000),
               $strOrNull($_POST['qa_ps'] ?? '', 2000),
               $procNo, $vendorId, $isInternal, ($respUnit !== '' ? $respUnit : null),
               $intOrNull($_POST['decider_cfg_id'] ?? ''), $intOrNull($_POST['decider_user_id'] ?? ''),
               $uid, $id,
           ]);

        // 責任單位－廠內部門／人員（只有廠內加工廠商才留；換成外包廠商時一律清掉，免得留著對不上的人）
        $db->prepare("DELETE FROM qa_abnormal_resp WHERE order_id=?")->execute([$id]);
        if ($isInternal) {
            $people = json_decode((string)($_POST['resp_people'] ?? '[]'), true) ?: [];
            $ins = $db->prepare("INSERT IGNORE INTO qa_abnormal_resp (order_id,dept_id,user_id) VALUES (?,?,?)");
            foreach ($people as $p) {
                $d = (int)($p['dept_id'] ?? 0);
                $u = (int)($p['user_id'] ?? 0);
                if ($d <= 0) continue;
                $ins->execute([$id, $d, $u ?: null]);
            }
        }

        // 量測尺寸與實測值（紙本三列 × 12 值）
        if (isset($_POST['measures'])) {
            $ms = json_decode((string)$_POST['measures'], true) ?: [];
            $db->prepare("DELETE FROM qa_abnormal_measure WHERE order_id=?")->execute([$id]);
            $ins = $db->prepare("INSERT INTO qa_abnormal_measure (order_id,seq,dim_name,vals) VALUES (?,?,?,?)");
            foreach (array_slice($ms, 0, 3) as $i => $m) {
                $dim = trim((string)($m['dim_name'] ?? ''));
                $vals = array_slice(array_map(function ($v) { return mb_substr(trim((string)$v), 0, 20); }, (array)($m['vals'] ?? [])), 0, 12);
                if ($dim === '' && !array_filter($vals, function ($v) { return $v !== ''; })) continue;
                $ins->execute([$id, $i + 1, mb_substr($dim, 0, 60), json_encode($vals, JSON_UNESCAPED_UNICODE)]);
            }
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    $log($id, 'head', '', '已更新填寫內容', trim((string)($_POST['reason'] ?? '')));
    jout(true, ['order' => qab_order($db, $id)]);
}

/* ═══════════ 異常原因分類（結案前可改；填表人或有權限者） ═══════════ */
case 'save_cause': {
    $o = $mustOrder((int)($_POST['id'] ?? 0));
    if (!qab_can_edit_form($db, $perms, $o) && !$perms['canDecide']) jerr('沒有修改異常原因分類的權限');
    $id = (int)$o['id'];
    $ids = array_values(array_unique(array_map('intval', json_decode((string)($_POST['cause_ids'] ?? '[]'), true) ?: [])));
    $map = qab_cause_map($db);
    foreach ($ids as $c) if (!isset($map[$c])) jerr('選到的原因分類不存在（可能剛被管理員刪除），請重新整理頁面');
    $old = $o['cause_ids'];
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM qa_abnormal_cause WHERE order_id=?")->execute([$id]);
        $ins = $db->prepare("INSERT IGNORE INTO qa_abnormal_cause (order_id,cat_id) VALUES (?,?)");
        foreach ($ids as $c) $ins->execute([$id, $c]);
        $db->prepare("UPDATE qa_abnormal_order SET updated_by=?, updated_at=NOW() WHERE id=?")->execute([$uid, $id]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    $paths = [];
    foreach ($ids as $c) $paths[] = $map[$c]['path'];
    $log($id, 'cause', implode('；', array_map(function ($c) use ($map) { return $map[$c]['path'] ?? $c; }, $old)), implode('；', $paths));
    jout(true, ['order' => qab_order($db, $id)]);
}

/* ═══════════ 相關單位意見：逐輪徵詢 ═══════════ */
case 'round_add': {
    $o = $mustOrder((int)($_POST['id'] ?? 0));
    if (!qab_can_edit_form($db, $perms, $o) && !$perms['canDecide']) jerr('沒有送出徵詢的權限');
    $id = (int)$o['id'];
    foreach ($o['rounds'] as $r) if (($r['status'] ?? '') !== 'Returned') jerr('上一個單位還沒回覆，收到回覆之後才決定下一個要送誰（可先取消未回覆的那一輪）');

    $deptId = (int)($_POST['dept_id'] ?? 0);
    $userId = (int)($_POST['user_id'] ?? 0);
    $posId  = (int)($_POST['position_id'] ?? 0);
    if ($deptId <= 0) jerr('請選擇要徵詢的部門');
    $c = $db->prepare("SELECT department_name FROM department WHERE id=?"); $c->execute([$deptId]);
    $deptName = (string)$c->fetchColumn();
    if ($deptName === '') jerr('選擇的部門不存在');

    // 指定人員時必須真的在這個部門（前端已擋，後端同規則再擋一次）
    if ($userId > 0) {
        $c = $db->prepare("SELECT 1 FROM user_department_position_map WHERE user_id=? AND department_id=? LIMIT 1");
        $c->execute([$userId, $deptId]);
        if (!$c->fetchColumn()) jerr('指定的人員不屬於這個部門');
    }
    // 指定職稱時：該部門裡有沒有這個職稱的人
    $posName = '';
    if ($userId <= 0 && $posId > 0) {
        $c = $db->prepare("SELECT position_name FROM position WHERE id=?"); $c->execute([$posId]);
        $posName = (string)$c->fetchColumn();
        if ($posName === '') jerr('選擇的職稱不存在');
        $c = $db->prepare("SELECT COUNT(*) FROM user_department_position_map m JOIN `user` u ON u.id=m.user_id
                           WHERE m.department_id=? AND m.position_id=? AND u.state=1");
        $c->execute([$deptId, $posId]);
        if ((int)$c->fetchColumn() === 0) jerr('這個部門目前沒有在職的「' . $posName . '」，請改指定人員');
    }

    $round = 1;
    foreach ($o['rounds'] as $r) $round = max($round, (int)$r['round_no'] + 1);
    $deadline = trim((string)($_POST['deadline'] ?? '')) ?: null;
    if ($deadline !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) $deadline = null;

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO qa_abnormal_order_flow
                        (abnormal_order_id, dept_id, user_id, position_id, include_mode, status, round_no, asked_by, asked_at, sort_order)
                      VALUES (?,?,?,?,0,'Pending',?,?,NOW(),?)")
           ->execute([$id, $deptId, $userId ?: null, $posId ?: null, $round, $uid, $round]);
        $flowId = (int)$db->lastInsertId();

        // 通知：指定人員就發給本人，否則發給整個部門（職稱只是說明給誰回，部門內都看得到）
        $targets = $userId > 0 ? [['type' => 'user', 'id' => $userId, 'mode' => 'reply']]
                               : [['type' => 'dept', 'id' => $deptId, 'mode' => 'reply']];
        $who = $userId > 0 ? ('指定人員') : ($posName !== '' ? ($deptName . ' ' . $posName) : $deptName);
        $title = '【品質異常單 ' . $o['abnormal_order_no'] . '】請回覆相關單位意見';
        $body  = "異常單號：{$o['abnormal_order_no']}\n"
               . "客戶／料號：" . (($o['client_name'] ?: '—') . ' / ' . ($o['part_no'] ?: '—')) . "\n"
               . "製令／客退單：" . (($o['bom_no'] ?: '—') . ' / ' . ($o['ir_no'] ?: '—')) . "\n"
               . "異常現象：" . mb_substr((string)$o['abnormal_phenomenon'], 0, 300) . "\n"
               . "徵詢對象：{$who}\n"
               . ($_POST['ask_note'] ?? '' ? ("徵詢說明：" . mb_substr(trim((string)$_POST['ask_note']), 0, 300) . "\n") : '');
        $eventId = eg_qa_insert_event($db, $id, $title, $body, $targets, $deadline, $uid,
            ['url' => '/EGsystem/views/QA/qa_abnormal_form.php?id=' . $id]);
        if ($eventId) $db->prepare("UPDATE qa_abnormal_order_flow SET event_id=? WHERE flow_id=?")->execute([$eventId, $flowId]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    $log($id, 'round_add', '', $deptName . ($posName ? (' ' . $posName) : ''));
    jout(true, ['order' => qab_order($db, $id)]);
}

case 'round_reply': {
    $o = $mustOrder((int)($_POST['id'] ?? 0));
    $flowId = (int)($_POST['flow_id'] ?? 0);
    $row = null;
    foreach ($o['rounds'] as $r) if ((int)$r['flow_id'] === $flowId) $row = $r;
    if (!$row) jerr('找不到這一輪徵詢');
    if (($row['status'] ?? '') === 'Returned') jerr('這一輪已經回覆過了，請重新整理頁面');
    // 可回覆者：被指定的本人／該部門的人／管理員
    $can = $perms['canAdmin'];
    if (!$can && (int)$row['user_id'] > 0) $can = ((int)$row['user_id'] === $uid);
    if (!$can && (int)$row['user_id'] === 0) $can = in_array((int)$row['dept_id'], qab_user_dept_ids($db, $uid), true);
    if (!$can) jerr('這一輪不是指定由您回覆');
    $content = trim((string)($_POST['reply_content'] ?? ''));
    if ($content === '') jerr('請填寫回覆內容');

    $db->prepare("UPDATE qa_abnormal_order_flow SET status='Returned', reply_content=?, replied_by=?, return_date=NOW(), receive_date=COALESCE(receive_date,NOW()) WHERE flow_id=?")
       ->execute([mb_substr($content, 0, 2000), $uid, $flowId]);
    try { eg_qa_notify_flow_return($db, (int)$o['id'], (string)($row['department_name'] ?? ''), $uid, $content); } catch (Throwable $e) {}
    $log((int)$o['id'], 'round_reply', '', mb_substr($content, 0, 200));
    jout(true, ['order' => qab_order($db, (int)$o['id'])]);
}

case 'round_cancel': {
    $o = $mustOrder((int)($_POST['id'] ?? 0));
    if (!qab_can_edit_form($db, $perms, $o) && !$perms['canDecide']) jerr('沒有取消徵詢的權限');
    $flowId = (int)($_POST['flow_id'] ?? 0);
    $row = null;
    foreach ($o['rounds'] as $r) if ((int)$r['flow_id'] === $flowId) $row = $r;
    if (!$row) jerr('找不到這一輪徵詢');
    if (($row['status'] ?? '') === 'Returned') jerr('已經回覆的那一輪不可取消（那是紀錄）');
    $db->prepare("DELETE FROM qa_abnormal_order_flow WHERE flow_id=?")->execute([$flowId]);
    $log((int)$o['id'], 'round_cancel', (string)($row['department_name'] ?? ''), '');
    jout(true, ['order' => qab_order($db, (int)$o['id'])]);
}

/* ═══════════ 決策：異常處置方式（業務／品管主管） ═══════════ */
case 'save_disposition': {
    $o = $mustOrder((int)($_POST['id'] ?? 0));
    if (!$perms['canDecide']) jerr('您不在可決策的名單內（由管理員在「決策者設定」指定部門與職稱）');
    $id = (int)$o['id'];
    $ids = array_values(array_unique(array_map('intval', json_decode((string)($_POST['opt_ids'] ?? '[]'), true) ?: [])));
    $optMap = qab_option_map($db);
    foreach ($ids as $i) {
        if (!isset($optMap[$i]) || $optMap[$i]['kind'] !== 'disp') jerr('選到的處置方式不存在，請重新整理頁面');
    }
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM qa_abnormal_opt WHERE order_id=? AND kind='disp'")->execute([$id]);
        $ins = $db->prepare("INSERT IGNORE INTO qa_abnormal_opt (order_id,kind,opt_id) VALUES (?, 'disp', ?)");
        foreach ($ids as $i) $ins->execute([$id, $i]);
        $db->prepare("UPDATE qa_abnormal_order SET disposition_note=?, disp_decided_by=?, disp_decided_at=NOW(), updated_by=?, updated_at=NOW() WHERE id=?")
           ->execute([$strOrNull($_POST['disposition_note'] ?? '', 2000), $uid, $uid, $id]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    // 勾了「轉總經理裁示」就通知最終決策者
    $escalate = false;
    foreach ($ids as $i) if ($optMap[$i]['is_escalate']) $escalate = true;
    if ($escalate) {
        try {
            $targets = [];
            $top = eg_org_user($db, 'top_approver');
            if ($top) $targets[] = ['type' => 'user', 'id' => (int)$top['id'], 'mode' => 'read'];
            foreach (qab_decider_cfgs($db, 'top') as $cfg) foreach (qab_decider_people($db, $cfg) as $p) $targets[] = ['type' => 'user', 'id' => (int)$p['id'], 'mode' => 'read'];
            if ($targets) {
                eg_qa_insert_event($db, $id, '【品質異常單 ' . $o['abnormal_order_no'] . '】待總經理裁示',
                    "異常單號：{$o['abnormal_order_no']}\n主管處置：" . implode('、', array_map(function ($i) use ($optMap) { return $optMap[$i]['name']; }, $ids)) . "\n請進入異常單做最終裁示。",
                    $targets, null, $uid, ['url' => '/EGsystem/views/QA/qa_abnormal_form.php?id=' . $id]);
            }
        } catch (Throwable $e) {}
    }
    $log($id, 'disposition', implode('、', $o['disp_names']), implode('、', array_map(function ($i) use ($optMap) { return $optMap[$i]['name']; }, $ids)));
    jout(true, ['order' => qab_order($db, $id)]);
}

/* ═══════════ 總經理裁示 ═══════════ */
case 'save_gm': {
    $o = $mustOrder((int)($_POST['id'] ?? 0));
    if (!$perms['canGm']) jerr('您不是最終決策者（由管理員在「決策者設定」指定，或設定組織角色的最高核准人員）');
    $id = (int)$o['id'];
    $ids = array_values(array_unique(array_map('intval', json_decode((string)($_POST['opt_ids'] ?? '[]'), true) ?: [])));
    $optMap = qab_option_map($db);
    foreach ($ids as $i) if (!isset($optMap[$i]) || $optMap[$i]['kind'] !== 'gm') jerr('選到的裁示選項不存在，請重新整理頁面');
    $deduct = !empty($_POST['gm_deduct']) ? 1 : 0;
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM qa_abnormal_opt WHERE order_id=? AND kind='gm'")->execute([$id]);
        $ins = $db->prepare("INSERT IGNORE INTO qa_abnormal_opt (order_id,kind,opt_id) VALUES (?, 'gm', ?)");
        foreach ($ids as $i) $ins->execute([$id, $i]);
        $db->prepare("UPDATE qa_abnormal_order SET gm_note=?, gm_deduct=?, capa_order_no=?, gm_decided_by=?, gm_decided_at=NOW(), updated_by=?, updated_at=NOW() WHERE id=?")
           ->execute([$strOrNull($_POST['gm_note'] ?? '', 2000), $deduct, $strOrNull($_POST['capa_order_no'] ?? '', 20), $uid, $uid, $id]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    $log($id, 'gm', implode('、', $o['gm_names']), implode('、', array_map(function ($i) use ($optMap) { return $optMap[$i]['name']; }, $ids)) . ($deduct ? '（扣款）' : ''));
    jout(true, ['order' => qab_order($db, $id)]);
}

/* ═══════════ 扣款確認 ═══════════ */
case 'deduct_preview': {   // 點開看到「會自動帶入哪些金額」，不寫入
    $bom = trim((string)($_GET['bom_no'] ?? ''));
    jout(true, ['rows' => qab_deduct_autofill($db, $bom)]);
}

case 'deduct_autofill': {  // 套用自動帶入（只重建 process 列，手動加的「其他」列不動）
    $o = $mustOrder((int)($_POST['id'] ?? 0));
    if (!$perms['canDeductFill']) jerr('沒有填寫扣款金額的權限（紙本：扣款確認表金額由生管填寫）');
    $id = (int)$o['id'];
    $bom = trim((string)($_POST['bom_no'] ?? $o['bom_no'] ?? ''));
    if ($bom === '') jerr('這張單沒有綁定製令，無法自動帶入製程金額');
    $rows = qab_deduct_autofill($db, $bom);
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM qa_abnormal_deduct WHERE order_id=? AND kind='process'")->execute([$id]);
        $ins = $db->prepare("INSERT INTO qa_abnormal_deduct
            (order_id,kind,transfer_id,bom_sn,process_name,vendor_name,transfer_no,qty,amount_auto,amount,included,sort_order,created_by)
            VALUES (?,'process',?,?,?,?,?,?,?,?,1,?,?)");
        foreach ($rows as $i => $r) {
            $ins->execute([$id, $r['transfer_id'], $r['bom_sn'], $r['process_name'], $r['vendor_name'],
                           $r['transfer_no'], $r['qty'], $r['amount'], $r['amount'], $i, $uid]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    $log($id, 'deduct_autofill', '', '帶入 ' . count($rows) . ' 站製程金額');
    jout(true, ['order' => qab_order($db, $id), 'count' => count($rows)]);
}

case 'deduct_save': {
    $o = $mustOrder((int)($_POST['id'] ?? 0));
    if (!$perms['canDeductFill']) jerr('沒有填寫扣款金額的權限（紙本：扣款確認表金額由生管填寫）');
    $id = (int)$o['id'];
    $rows = json_decode((string)($_POST['rows'] ?? '[]'), true) ?: [];
    $rate = $numOrNull($_POST['surcharge_rate'] ?? '');
    if ($rate !== null && ($rate <= 0 || $rate > 10)) jerr('加成請填大於 0 的倍數（例 1.1 表示 ×110%）');

    $db->beginTransaction();
    try {
        // 既有列以 id 更新；沒有 id 的一律當「其他」新增（製程列只能由自動帶入產生，避免有人手打一列假製程）
        $keep = [];
        $upd = $db->prepare("UPDATE qa_abnormal_deduct SET amount=?, note=?, included=?, qty=?, process_name=?, sort_order=? WHERE id=? AND order_id=?");
        $ins = $db->prepare("INSERT INTO qa_abnormal_deduct (order_id,kind,process_name,qty,amount,amount_auto,note,included,sort_order,created_by)
                             VALUES (?,'other',?,?,?,NULL,?,?,?,?)");
        foreach ($rows as $i => $r) {
            $rid = (int)($r['id'] ?? 0);
            $amt = $numOrNull($r['amount'] ?? '');
            $qty = $numOrNull($r['qty'] ?? '');
            $note = $strOrNull($r['note'] ?? '', 255);
            $inc = !empty($r['included']) ? 1 : 0;
            $nm  = $strOrNull($r['process_name'] ?? '', 60);
            if ($rid > 0) {
                $upd->execute([$amt, $note, $inc, $qty, $nm, $i, $rid, $id]);
                $keep[] = $rid;
            } else {
                if ($amt === null && $note === null && $nm === null) continue;
                $ins->execute([$id, $nm, $qty, $amt, $note, $inc, $i, $uid]);
                $keep[] = (int)$db->lastInsertId();
            }
        }
        // 畫面上被刪掉的「其他」列要真的刪除（製程列不在此列，由自動帶入負責）
        $cur = $db->prepare("SELECT id FROM qa_abnormal_deduct WHERE order_id=? AND kind='other'");
        $cur->execute([$id]);
        foreach ($cur->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            if (!in_array((int)$cid, $keep, true)) $db->prepare("DELETE FROM qa_abnormal_deduct WHERE id=?")->execute([(int)$cid]);
        }
        $db->prepare("UPDATE qa_abnormal_order SET surcharge_rate=?, deduct_qty=?, deduct_unit_amt=?, deduct_exec=?, deduct_notify_no=?, updated_by=?, updated_at=NOW() WHERE id=?")
           ->execute([$rate ?? $o['surcharge_rate'], $numOrNull($_POST['deduct_qty'] ?? ''), $numOrNull($_POST['deduct_unit_amt'] ?? ''),
                      $strOrNull($_POST['deduct_exec'] ?? '', 60), $strOrNull($_POST['deduct_notify_no'] ?? '', 120), $uid, $id]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    $log($id, 'deduct', '', '更新扣款明細');
    jout(true, ['order' => qab_order($db, $id)]);
}

case 'deduct_sign': {      // 生管／品管簽章
    $o = $mustOrder((int)($_POST['id'] ?? 0));
    $who = ($_POST['who'] ?? '') === 'qc' ? 'qc' : 'pm';
    if ($who === 'pm' && !$perms['canDeductFill']) jerr('沒有生管簽章的權限');
    if ($who === 'qc' && !$perms['canQcSign'])   jerr('沒有品管簽章的權限');
    $col = $who === 'qc' ? 'deduct_qc' : 'deduct_pm';
    $clear = !empty($_POST['clear']);
    $db->prepare("UPDATE qa_abnormal_order SET {$col}_by=?, {$col}_at=" . ($clear ? 'NULL' : 'NOW()') . " WHERE id=?")
       ->execute([$clear ? null : $uid, (int)$o['id']]);
    $log((int)$o['id'], $col, '', $clear ? '取消簽章' : '簽章');
    jout(true, ['order' => qab_order($db, (int)$o['id'])]);
}

case 'deduct_approve': {   // 核准（管理課 會計／主管）
    $o = $mustOrder((int)($_POST['id'] ?? 0));
    if (!$perms['canDeductApprove']) jerr('沒有核准扣款金額的權限（紙本：核准扣款金額由管理課 會計／主管填寫）');
    $clear = !empty($_POST['clear']);
    $db->prepare("UPDATE qa_abnormal_order SET deduct_appr_by=?, deduct_appr_at=" . ($clear ? 'NULL' : 'NOW()') . ", deduct_unit_amt=COALESCE(?,deduct_unit_amt), deduct_qty=COALESCE(?,deduct_qty) WHERE id=?")
       ->execute([$clear ? null : $uid, $numOrNull($_POST['deduct_unit_amt'] ?? ''), $numOrNull($_POST['deduct_qty'] ?? ''), (int)$o['id']]);
    $log((int)$o['id'], 'deduct_approve', '', $clear ? '取消核准' : '核准');
    jout(true, ['order' => qab_order($db, (int)$o['id'])]);
}

/* ═══════════ 承辦簽章 ═══════════ */
case 'owner_sign': {
    $o = $mustOrder((int)($_POST['id'] ?? 0));
    if (!qab_can_edit_form($db, $perms, $o)) jerr('沒有承辦簽章的權限');
    $clear = !empty($_POST['clear']);
    $db->prepare("UPDATE qa_abnormal_order SET owner_sign_by=?, owner_sign_at=" . ($clear ? 'NULL' : 'NOW()') . " WHERE id=?")
       ->execute([$clear ? null : $uid, (int)$o['id']]);
    jout(true, ['order' => qab_order($db, (int)$o['id'])]);
}

/* ═══════════ 結案／取消結案 ═══════════ */
case 'close': {
    $o = $mustOrder((int)($_POST['id'] ?? 0));
    if (!$perms['canDecide'] && !$perms['canAdmin'] && (int)$o['created_by'] !== $uid) jerr('沒有結案的權限');
    $id = (int)$o['id'];
    // 結案前一定要把該勾的勾好（使用者要求：結案前需要勾選好）
    if (!$o['cause_ids']) jerr('結案前請先勾選「異常原因分類」');
    if (!$o['disp_ids'] && !$o['gm_ids']) jerr('結案前請先完成「異常處置方式」或「總經理裁示」');
    if (!empty($o['need_gm'])) jerr('處置方式勾了「轉總經理裁示」，要等最終裁示完成才能結案');
    foreach ($o['rounds'] as $r) if (($r['status'] ?? '') !== 'Returned') jerr('還有單位尚未回覆，請等回覆或先取消該輪徵詢');

    $db->beginTransaction();
    try {
        $scrapNo = (string)($o['scrap_no'] ?? '');
        // 最終決策含報廢 → 結案當下配發報廢單號並寫入 DB（後續別的單據要靠它追蹤）
        if ($o['final']['is_scrap'] && $scrapNo === '') {
            $scrapNo = qab_scrap_alloc($db, (string)($o['fill_date'] ?: date('Y-m-d')));
            $db->prepare("UPDATE qa_abnormal_order SET scrap_no=?, scrap_no_at=NOW() WHERE id=?")->execute([$scrapNo, $id]);
        }
        $db->prepare("UPDATE qa_abnormal_order SET is_closed=1, closed_at=NOW(), closed_by=?, updated_by=?, updated_at=NOW() WHERE id=?")
           ->execute([$uid, $uid, $id]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    $n = qab_order($db, $id);
    $log($id, 'close', '', '結案' . ($n['scrap_no'] ? ('（報廢單號 ' . $n['scrap_no'] . '）') : ''));
    jout(true, ['order' => $n, 'scrap_no' => (string)$n['scrap_no']]);
}

case 'reopen': {
    if (!$perms['canAdmin']) jerr('只有管理員可以取消結案');
    $id = (int)($_POST['id'] ?? 0);
    $o = qab_order($db, $id);
    if (!$o) jerr('找不到這張異常單');
    // 報廢單號刻意不收回：號碼已經配出去、別的單據可能引用了
    $db->prepare("UPDATE qa_abnormal_order SET is_closed=0, closed_at=NULL, closed_by=NULL, updated_by=?, updated_at=NOW() WHERE id=?")
       ->execute([$uid, $id]);
    $log($id, 'reopen', '', '取消結案（報廢單號保留）');
    jout(true, ['order' => qab_order($db, $id)]);
}

/* ═══════════ 搜尋工具 ═══════════ */
case 'search_process': {
    $kw = trim((string)($_GET['kw'] ?? ''));
    $st = $db->prepare("SELECT ProcessNo, ProcessName FROM process_no
                        WHERE (? = '' OR ProcessName LIKE ? OR CAST(ProcessNo AS CHAR) LIKE ?)
                        ORDER BY ProcessNo LIMIT 50");
    $st->execute([$kw, "%$kw%", "%$kw%"]);
    jout(true, ['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

case 'search_vendor': {
    $kw = trim((string)($_GET['kw'] ?? ''));
    $st = $db->prepare("SELECT maker_id_no, maker_id, maker_id_all, internal FROM maker_list
                        WHERE (? = '' OR maker_id LIKE ? OR maker_id_no LIKE ? OR maker_id_all LIKE ?)
                        ORDER BY internal DESC, maker_id_no LIMIT 50");
    $st->execute([$kw, "%$kw%", "%$kw%", "%$kw%"]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['internal'] = (int)($r['internal'] ?? 0);
    jout(true, ['rows' => $rows]);
}

case 'search_ir': {
    $kw = trim((string)($_GET['kw'] ?? ''));
    $st = $db->prepare("SELECT IR_id, IR_no, Client_name, d_id, Qty, IR_date FROM ir_track
                        WHERE (? = '' OR IR_no LIKE ? OR Client_name LIKE ? OR d_id LIKE ?)
                        ORDER BY IR_date DESC, IR_id DESC LIMIT 50");
    $st->execute([$kw, "%$kw%", "%$kw%", "%$kw%"]);
    jout(true, ['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

case 'search_bom': {
    $kw = trim((string)($_GET['kw'] ?? ''));
    $st = $db->prepare("SELECT bom, d_id, Client_Name, sqty, specification FROM bom
                        WHERE (? = '' OR bom LIKE ? OR d_id LIKE ? OR Client_Name LIKE ?)
                        ORDER BY Created_At DESC LIMIT 50");
    $st->execute([$kw, "%$kw%", "%$kw%", "%$kw%"]);
    jout(true, ['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

case 'depts': {
    $rows = $db->query("SELECT id, department_name, parent_id FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    jout(true, ['rows' => $rows]);
}

case 'dept_people': {
    $deptId = (int)($_GET['dept_id'] ?? 0);
    if ($deptId <= 0) jout(true, ['rows' => []]);
    $rows = eg_people_list($db, ['dept_ids' => [$deptId], 'all_posts' => true]);
    $out = [];
    foreach ($rows as $r) {
        if ((int)$r['dept_id'] !== $deptId) continue;
        $out[] = ['id' => (int)$r['id'], 'name' => $r['user_cname'], 'position_id' => $r['position_id'],
                  'position_name' => $r['position_name'], 'dept_id' => (int)$r['dept_id'], 'dept_name' => $r['dept_name'],
                  'leave_note' => $r['leave_note']];
    }
    jout(true, ['rows' => $out]);
}

case 'dept_positions': {
    $deptId = (int)($_GET['dept_id'] ?? 0);
    $st = $db->prepare("SELECT DISTINCT p.id, p.position_name, p.sort_order
                        FROM user_department_position_map m
                        JOIN position p ON p.id = m.position_id
                        JOIN `user` u ON u.id = m.user_id AND u.state=1
                        WHERE m.department_id=? ORDER BY p.sort_order, p.id");
    $st->execute([$deptId]);
    jout(true, ['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ═══════════ 管理員設定 ═══════════ */
case 'settings_get': {
    jout(true, [
        'causes'    => qab_cause_tree($db, false),
        'disp_opts' => qab_options($db, 'disp', false),
        'gm_opts'   => qab_options($db, 'gm', false),
        'deciders'  => qab_decider_cfgs($db, 'decider', false),
        'tops'      => qab_decider_cfgs($db, 'top', false),
        'rate'      => qab_default_rate($db),
        'can_admin' => $perms['canAdmin'],
    ]);
}

case 'cause_save': {
    if (!$perms['canAdmin']) jerr('只有管理員可以維護異常原因分類');
    $catId  = (int)($_POST['cat_id'] ?? 0);
    $name   = trim((string)($_POST['name'] ?? ''));
    $parent = (int)($_POST['parent_id'] ?? 0);
    $sort   = (int)($_POST['sort_order'] ?? 0);
    $active = isset($_POST['is_active']) ? (int)!empty($_POST['is_active']) : 1;
    if ($name === '') jerr('請填寫分類名稱');
    $lv = 1;
    if ($parent > 0) {
        $st = $db->prepare("SELECT lv FROM qa_cause_cat WHERE cat_id=?"); $st->execute([$parent]);
        $plv = $st->fetchColumn();
        if ($plv === false) jerr('上層分類不存在');
        $lv = (int)$plv + 1;
        if ($lv > 3) jerr('最多三層（例：人 → 方法 → 程式）');
    }
    if ($catId > 0) {
        // 不可把自己搬到自己底下（會做出一個永遠展不開的圈）
        if ($parent === $catId) jerr('上層分類不可以是自己');
        $db->prepare("UPDATE qa_cause_cat SET parent_id=?, lv=?, name=?, sort_order=?, is_active=?, updated_at=NOW() WHERE cat_id=?")
           ->execute([$parent ?: null, $lv, mb_substr($name, 0, 60), $sort, $active, $catId]);
        // 子孫的層級要跟著調整，否則會出現 lv=4
        $fix = function ($pid, $plv) use (&$fix, $db) {
            $st = $db->prepare("SELECT cat_id FROM qa_cause_cat WHERE parent_id=?"); $st->execute([$pid]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
                $db->prepare("UPDATE qa_cause_cat SET lv=? WHERE cat_id=?")->execute([$plv + 1, (int)$cid]);
                $fix((int)$cid, $plv + 1);
            }
        };
        $fix($catId, $lv);
    } else {
        $db->prepare("INSERT INTO qa_cause_cat (parent_id,lv,name,sort_order,is_active) VALUES (?,?,?,?,?)")
           ->execute([$parent ?: null, $lv, mb_substr($name, 0, 60), $sort, $active]);
        $catId = (int)$db->lastInsertId();
    }
    jout(true, ['cat_id' => $catId, 'causes' => qab_cause_tree($db, false)]);
}

case 'cause_del': {
    if (!$perms['canAdmin']) jerr('只有管理員可以維護異常原因分類');
    $catId = (int)($_POST['cat_id'] ?? 0);
    $st = $db->prepare("SELECT COUNT(*) FROM qa_cause_cat WHERE parent_id=?"); $st->execute([$catId]);
    if ((int)$st->fetchColumn() > 0) jerr('這個分類底下還有下層分類，請先處理下層');
    // 已經被異常單選過的一律不給刪（刪掉那些單的原因分類會變成空白，而且看不出原因）
    $st = $db->prepare("SELECT COUNT(*) FROM qa_abnormal_cause WHERE cat_id=?"); $st->execute([$catId]);
    $used = (int)$st->fetchColumn();
    if ($used > 0) jerr("已有 {$used} 張異常單選用這個分類，不可刪除；請改成「停用」（既有單仍看得到，新單不會再出現）");
    $db->prepare("DELETE FROM qa_cause_cat WHERE cat_id=?")->execute([$catId]);
    jout(true, ['causes' => qab_cause_tree($db, false)]);
}

case 'opt_save': {
    if (!$perms['canAdmin']) jerr('只有管理員可以維護處置方式與裁示選項');
    $kind = ($_POST['kind'] ?? '') === 'gm' ? 'gm' : 'disp';
    $optId = (int)($_POST['opt_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') jerr('請填寫選項名稱');
    $p = [mb_substr($name, 0, 40), (int)!empty($_POST['is_scrap']), (int)!empty($_POST['is_escalate']),
          (int)!empty($_POST['need_capa']), (int)($_POST['sort_order'] ?? 0),
          isset($_POST['is_active']) ? (int)!empty($_POST['is_active']) : 1];
    if ($optId > 0) {
        $db->prepare("UPDATE qa_option SET name=?, is_scrap=?, is_escalate=?, need_capa=?, sort_order=?, is_active=? WHERE opt_id=? AND kind=?")
           ->execute(array_merge($p, [$optId, $kind]));
    } else {
        $db->prepare("INSERT INTO qa_option (name,is_scrap,is_escalate,need_capa,sort_order,is_active,kind) VALUES (?,?,?,?,?,?,?)")
           ->execute(array_merge($p, [$kind]));
        $optId = (int)$db->lastInsertId();
    }
    jout(true, ['opt_id' => $optId, 'disp_opts' => qab_options($db, 'disp', false), 'gm_opts' => qab_options($db, 'gm', false)]);
}

case 'opt_del': {
    if (!$perms['canAdmin']) jerr('只有管理員可以維護處置方式與裁示選項');
    $optId = (int)($_POST['opt_id'] ?? 0);
    $st = $db->prepare("SELECT COUNT(*) FROM qa_abnormal_opt WHERE opt_id=?"); $st->execute([$optId]);
    $used = (int)$st->fetchColumn();
    if ($used > 0) jerr("已有 {$used} 張異常單勾選這個選項，不可刪除；請改成「停用」");
    $db->prepare("DELETE FROM qa_option WHERE opt_id=?")->execute([$optId]);
    jout(true, ['disp_opts' => qab_options($db, 'disp', false), 'gm_opts' => qab_options($db, 'gm', false)]);
}

case 'decider_save': {
    if (!$perms['canAdmin']) jerr('只有管理員可以設定決策者範圍');
    $kind = ($_POST['kind'] ?? '') === 'top' ? 'top' : 'decider';
    $cfgId = (int)($_POST['cfg_id'] ?? 0);
    $deptId = (int)($_POST['dept_id'] ?? 0);
    if ($deptId <= 0) jerr('請選擇部門');
    $c = $db->prepare("SELECT 1 FROM department WHERE id=?"); $c->execute([$deptId]);
    if (!$c->fetchColumn()) jerr('部門不存在');
    $posId = (int)($_POST['position_id'] ?? 0);
    if ($posId > 0) {
        $c = $db->prepare("SELECT 1 FROM position WHERE id=?"); $c->execute([$posId]);
        if (!$c->fetchColumn()) jerr('職稱不存在');
    }
    $p = [$kind, ($strOrNull($_POST['label'] ?? '', 40)), $deptId, $posId ?: null,
          (int)!empty($_POST['include_sub']), (int)($_POST['sort_order'] ?? 0),
          isset($_POST['is_active']) ? (int)!empty($_POST['is_active']) : 1];
    if ($cfgId > 0) {
        $db->prepare("UPDATE qa_decider_cfg SET kind=?, label=?, dept_id=?, position_id=?, include_sub=?, sort_order=?, is_active=? WHERE cfg_id=?")
           ->execute(array_merge($p, [$cfgId]));
    } else {
        $db->prepare("INSERT INTO qa_decider_cfg (kind,label,dept_id,position_id,include_sub,sort_order,is_active) VALUES (?,?,?,?,?,?,?)")
           ->execute($p);
        $cfgId = (int)$db->lastInsertId();
    }
    jout(true, ['cfg_id' => $cfgId, 'deciders' => qab_decider_cfgs($db, 'decider', false), 'tops' => qab_decider_cfgs($db, 'top', false)]);
}

case 'decider_del': {
    if (!$perms['canAdmin']) jerr('只有管理員可以設定決策者範圍');
    $db->prepare("DELETE FROM qa_decider_cfg WHERE cfg_id=?")->execute([(int)($_POST['cfg_id'] ?? 0)]);
    jout(true, ['deciders' => qab_decider_cfgs($db, 'decider', false), 'tops' => qab_decider_cfgs($db, 'top', false)]);
}

case 'decider_people': {   // 設定畫面上即時顯示「這一列目前涵蓋誰」
    $cfgId = (int)($_GET['cfg_id'] ?? 0);
    $all = array_merge(qab_decider_cfgs($db, 'decider', false), qab_decider_cfgs($db, 'top', false));
    foreach ($all as $c) if ((int)$c['cfg_id'] === $cfgId) {
        $ppl = qab_decider_people($db, $c);
        $out = [];
        foreach ($ppl as $p) $out[] = ['id' => (int)$p['id'], 'name' => $p['user_cname'],
                                       'dept_name' => $p['dept_name'], 'position_name' => $p['position_name']];
        jout(true, ['rows' => $out]);
    }
    jout(true, ['rows' => []]);
}

case 'positions': {
    $rows = $db->query("SELECT id, position_name FROM position ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    jout(true, ['rows' => $rows]);
}

case 'asdoc_list': {          // AS 文件綁定：清單與目前綁定（走共用 asdoc_lib，ai-rules/16 第一之三節）
    require_once __DIR__ . '/../common/asdoc_lib.php';
    $cur = eg_asdoc_get($db, QAB_ASDOC_MODULE);
    jout(true, ['docs' => eg_asdoc_list($db), 'current' => $cur ? (int)$cur['id'] : 0]);
}

case 'asdoc_save': {
    if (!$perms['canAdmin']) jerr('只有管理員可以修改 AS 文件綁定');
    require_once __DIR__ . '/../common/asdoc_lib.php';
    eg_asdoc_save($db, QAB_ASDOC_MODULE, (int)($_POST['doc_id'] ?? 0), $perms['name']);
    $cur = eg_asdoc_get($db, QAB_ASDOC_MODULE);
    jout(true, ['doc' => $cur, 'doc_no' => $cur ? eg_asdoc_no($cur) : '']);
}

case 'setting_save': {
    if (!$perms['canAdmin']) jerr('只有管理員可以修改設定');
    $rate = $numOrNull($_POST['surcharge_rate'] ?? '');
    if ($rate === null || $rate <= 0 || $rate > 10) jerr('加成預設值請填大於 0 的倍數（例 1.1 表示 ×110%）');
    qab_setting_set($db, 'surcharge_rate', $rate);
    jout(true, ['rate' => $rate]);
}

default:
    jerr('無效的操作：' . htmlspecialchars((string)$action));
}
} catch (Throwable $e) {
    error_log('[QaAbnormal_API] ' . $e->getMessage());
    jerr('系統錯誤：' . $e->getMessage());
}
