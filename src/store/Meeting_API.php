<?php
/**
 * 會議紀錄管理 API（2-GM-05-01／2-GM-05-03）
 * 權限：meeting_lib.php meeting_perms()（roles module='meeting'）；單筆檢視另受 meeting_can_view() 限制（草稿僅本人）。
 * 讀：GET；寫：POST，transaction。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/meeting_lib.php';
include_once $document_root . '/EGsystem/src/common/people_lib.php';

function jout($a){ echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

try {
    $db = (new DBConnection())->getPDO();
    meeting_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：'.$e->getMessage(), 500); }

$u = meeting_current_user($db);
if (!$u) jerr('未登入', 401);
$uid = (int)$u['id'];
$uname = (string)$u['user_cname'];
$perms = meeting_perms($db, $u);
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if (!$perms['canView']) jerr('無會議記錄檢閱權限', 403);

/** 讀出一筆會議記錄表頭，查無則直接 404 */
function meeting_load(PDO $db, int $id): array {
    $st = $db->prepare("SELECT * FROM meeting_record WHERE meeting_id=?");
    $st->execute([$id]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m) jerr('找不到此會議記錄', 404);
    return $m;
}
function meeting_items(PDO $db, int $id): array {
    $st = $db->prepare("SELECT * FROM meeting_item WHERE meeting_id=? ORDER BY kind, sort_order, item_id");
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function meeting_attendees(PDO $db, int $id): array {
    $st = $db->prepare("SELECT a.*, d.sort_order AS dept_sort, p.sort_order AS pos_sort
                        FROM meeting_attendee a
                        LEFT JOIN user_department_position_map m ON m.user_id=a.user_id AND m.is_main=1
                        LEFT JOIN department d ON d.id=m.department_id
                        LEFT JOIN position p ON p.id=m.position_id
                        WHERE a.meeting_id=?
                        ORDER BY COALESCE(d.sort_order,999), COALESCE(p.sort_order,999), a.user_name");
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
/** 此人目前所屬部門 id 清單（含兼職）；item_confirm 用來判斷是否屬於負責部門 */
function meeting_user_depts(PDO $db, int $uid): array {
    $st = $db->prepare("SELECT DISTINCT department_id FROM user_department_position_map WHERE user_id=?");
    $st->execute([$uid]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

switch ($action) {

case 'meta': {
    $depts = $db->query("SELECT id, name, parent_id, level FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    $years = $db->query("SELECT DISTINCT YEAR(meeting_date) FROM meeting_record ORDER BY 1 DESC")->fetchAll(PDO::FETCH_COLUMN);
    $cy = (int)date('Y');
    $years = array_values(array_unique(array_merge([$cy], array_map('intval', $years))));
    rsort($years);
    $gm = eg_org_user($db, 'top_approver');
    $presets = $db->query("SELECT preset_id, subject, location, start_time, end_time FROM meeting_preset ORDER BY sort_order, preset_id")->fetchAll(PDO::FETCH_ASSOC);
    jout(['perms'=>$perms, 'departments'=>$depts, 'years'=>$years,
          'uid'=>$uid, 'uname'=>$uname, 'today'=>date('Y-m-d'), 'cur_year'=>$cy,
          'gm_name'=>$gm ? $gm['user_cname'] : null, 'presets'=>$presets]);
}

/* 常用設定（主題綁地點綁時間）：管理員維護 */
case 'preset_save': {
    if (!$perms['canAdmin']) jerr('僅管理員可維護常用設定', 403);
    $id = (int)($_POST['preset_id'] ?? 0);
    $subject = trim((string)($_POST['subject'] ?? ''));
    if ($subject === '') jerr('請輸入主題');
    $loc = trim((string)($_POST['location'] ?? '')) ?: null;
    $start = trim((string)($_POST['start_time'] ?? '')) ?: null;
    $end = trim((string)($_POST['end_time'] ?? '')) ?: null;
    if ($id > 0) {
        $db->prepare("UPDATE meeting_preset SET subject=?, location=?, start_time=?, end_time=? WHERE preset_id=?")
           ->execute([$subject, $loc, $start, $end, $id]);
    } else {
        $n = (int)$db->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM meeting_preset")->fetchColumn();
        $db->prepare("INSERT INTO meeting_preset (subject, location, start_time, end_time, sort_order, created_by, created_at)
                      VALUES (?,?,?,?,?,?,NOW())")->execute([$subject, $loc, $start, $end, $n, $uid]);
        $id = (int)$db->lastInsertId();
    }
    jout(['preset_id'=>$id]);
}
case 'preset_delete': {
    if (!$perms['canAdmin']) jerr('僅管理員可維護常用設定', 403);
    $db->prepare("DELETE FROM meeting_preset WHERE preset_id=?")->execute([(int)($_POST['preset_id'] ?? 0)]);
    jout([]);
}

/* 依 user_id 清單重新解析目前的部門/職稱（出席人員群組套用用；資料以「現況」為準，不是群組儲存當下的舊快照） */
case 'resolve_people': {
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['user_ids'] ?? '')))));
    if (!$ids) jout(['people'=>[]]);
    $rows = eg_people_list($db, ['user_ids'=>$ids]);
    jout(['people'=>array_map(fn($r) => ['id'=>$r['id'], 'user_cname'=>$r['user_cname'],
        'position_name'=>$r['position_name'] ?? '', 'dept_name'=>$r['dept_name'] ?? ''], $rows)]);
}

/* 會議記錄列表：只回傳目前使用者有權看到的（草稿僅本人／管理員；其餘依 meeting_can_view 逐筆判斷） */
case 'list': {
    $year = (int)($_GET['year'] ?? date('Y'));
    $st = $db->prepare("SELECT * FROM meeting_record WHERE YEAR(meeting_date)=? ORDER BY meeting_date DESC, meeting_id DESC");
    $st->execute([$year]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $m) {
        if (!meeting_can_view($db, $uid, $perms, $m)) continue;
        $ap = meeting_approval_status($db, (int)$m['meeting_id']);
        $m['approval_status'] = $ap['status'];
        $m['is_mine'] = (int)$m['recorder_user_id'] === $uid;
        $out[] = $m;
    }
    jout(['meetings'=>$out]);
}

case 'get_detail': {
    $id = (int)($_GET['meeting_id'] ?? 0);
    $m = meeting_load($db, $id);
    if (!meeting_can_view($db, $uid, $perms, $m)) jerr('無權檢視此會議記錄', 403);
    $ap = meeting_approval_status($db, $id);
    $m['approval_status'] = $ap['status'];
    $m['chair_approval'] = $ap['chair'];
    $m['gm_approval'] = $ap['gm'];
    $m['can_edit'] = ((int)$m['recorder_user_id'] === $uid || $perms['canAdmin']) && in_array($m['status'], ['draft','rejected'], true);
    // 解出「目前實際該簽的人」(含代理)，前端才能正確顯示簽核按鈕給代理人看，不只給原本的主席/總經理
    $m['chair_signer_id'] = $m['chair_user_id'] ? meeting_chair_signer_effective($db, (int)$m['chair_user_id'], (string)$m['chair_name'])['id'] : null;
    $gmSigner = meeting_gm_signer_effective($db);
    $m['gm_signer_id'] = $gmSigner['id'] ?? null;
    jout(['meeting'=>$m, 'items'=>meeting_items($db, $id), 'attendees'=>meeting_attendees($db, $id)]);
}

/* 建立/編輯草稿（id=0＝新建）：只有 draft/rejected 狀態可編（送出後鎖定，需退回才能再改） */
case 'save': {
    if (!$perms['canEdit']) jerr('無編輯權限', 403);
    $id = (int)($_POST['meeting_id'] ?? 0);
    $subject = trim((string)($_POST['subject'] ?? ''));
    $date = trim((string)($_POST['meeting_date'] ?? ''));
    if ($subject === '') jerr('請輸入會議主題');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) jerr('請選擇正確的會議日期');
    $start = trim((string)($_POST['start_time'] ?? '')) ?: null;
    $end = trim((string)($_POST['end_time'] ?? '')) ?: null;
    foreach (['start_time'=>$start, 'end_time'=>$end] as $lbl => $v) {
        if ($v !== null && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) jerr('時間格式不正確（'.$lbl.'）');
    }
    if ($start !== null && $end !== null && $end <= $start) jerr('結束時間不可早於或等於開始時間');
    $loc = trim((string)($_POST['location'] ?? '')) ?: null;

    if ($id > 0) {
        $m = meeting_load($db, $id);
        if ((int)$m['recorder_user_id'] !== $uid && !$perms['canAdmin']) jerr('僅記錄人本人或管理員可編輯', 403);
        if (!in_array($m['status'], ['draft','rejected'], true)) jerr('此會議記錄已送出，無法編輯（如需修改請先請主席/總經理退回）');
    }
    // 記錄一律自動帶入建立者，不接受前端覆寫（避免代填他人姓名）；新建時＝目前登入者，編輯時沿用原記錄人
    $recorderName = $id > 0 ? (string)($m['recorder_name'] ?? $uname) : $uname;

    $attendees = json_decode((string)($_POST['attendees'] ?? '[]'), true);
    if (!is_array($attendees)) $attendees = [];
    $chairUid = (int)($_POST['chair_user_id'] ?? 0);
    $chairName = '';
    foreach ($attendees as $a) if ((int)($a['user_id'] ?? 0) === $chairUid) $chairName = (string)($a['user_name'] ?? '');
    if ($chairUid && !$chairName) jerr('主席必須是出席人員之一');

    $items = json_decode((string)($_POST['items'] ?? '[]'), true);
    if (!is_array($items)) $items = [];

    try {
        $db->beginTransaction();
        if ($id > 0) {
            $db->prepare("UPDATE meeting_record SET subject=?, meeting_date=?, start_time=?, end_time=?, location=?,
                          chair_user_id=?, chair_name=?, recorder_name=?, updated_at=NOW() WHERE meeting_id=?")
               ->execute([$subject, $date, $start, $end, $loc, $chairUid ?: null, $chairName ?: null, $recorderName, $id]);
        } else {
            $db->prepare("INSERT INTO meeting_record (subject, meeting_date, start_time, end_time, location,
                          chair_user_id, chair_name, recorder_user_id, recorder_name, status, created_by, created_by_name)
                          VALUES (?,?,?,?,?,?,?,?,?, 'draft', ?, ?)")
               ->execute([$subject, $date, $start, $end, $loc, $chairUid ?: null, $chairName ?: null, $uid, $recorderName, $uid, $uname]);
            $id = (int)$db->lastInsertId();
        }

        // 出席人員：整批取代（保留已簽到狀態）
        $old = [];
        $oq = $db->prepare("SELECT user_id, signed, signed_at FROM meeting_attendee WHERE meeting_id=?");
        $oq->execute([$id]);
        foreach ($oq->fetchAll(PDO::FETCH_ASSOC) as $o) $old[(int)$o['user_id']] = $o;
        $db->prepare("DELETE FROM meeting_attendee WHERE meeting_id=?")->execute([$id]);
        $insA = $db->prepare("INSERT INTO meeting_attendee (meeting_id,user_id,user_name,dept_name,position_name,is_chair,signed,signed_at)
                              VALUES (?,?,?,?,?,?,?,?)");
        foreach ($attendees as $a) {
            $auid = (int)($a['user_id'] ?? 0);
            if ($auid <= 0) continue;
            $o = $old[$auid] ?? null;
            $insA->execute([$id, $auid, trim((string)($a['user_name'] ?? '')) ?: null,
                trim((string)($a['dept_name'] ?? '')) ?: null, trim((string)($a['position_name'] ?? '')) ?: null,
                $auid === $chairUid ? 1 : 0, $o ? (int)$o['signed'] : 0, $o ? $o['signed_at'] : null]);
        }

        // 會議項目：整批取代（保留已確認簽名狀態，用內容+kind+sort比對太脆弱，改用 item_id 對應；沒有 item_id 的視為新增）
        $oldItems = [];
        $iq = $db->prepare("SELECT item_id, confirm_user_id, confirm_user_name, confirm_at, gm_comment FROM meeting_item WHERE meeting_id=?");
        $iq->execute([$id]);
        foreach ($iq->fetchAll(PDO::FETCH_ASSOC) as $o) $oldItems[(int)$o['item_id']] = $o;
        $db->prepare("DELETE FROM meeting_item WHERE meeting_id=?")->execute([$id]);
        $insI = $db->prepare("INSERT INTO meeting_item (meeting_id, kind, sort_order, content, due_date, owner_depts, owner_dept_names, remark,
                              confirm_user_id, confirm_user_name, confirm_at, gm_comment) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $n = 0;
        foreach ($items as $it) {
            $content = trim((string)($it['content'] ?? ''));
            if ($content === '') continue;
            $kind = ($it['kind'] ?? '') === 'directive' ? 'directive' : 'general';
            $due = trim((string)($it['due_date'] ?? '')) ?: null;
            if ($due && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) $due = null;
            $ownerIds = array_values(array_filter(array_map('intval', (array)($it['owner_depts'] ?? []))));
            $ownerNames = trim((string)($it['owner_dept_names'] ?? '')) ?: null;
            $remark = trim((string)($it['remark'] ?? '')) ?: null;
            $prevId = (int)($it['item_id'] ?? 0);
            $prev = $oldItems[$prevId] ?? null;
            $insI->execute([$id, $kind, $n, $content, $due, $ownerIds ? implode(',', $ownerIds) : null, $ownerNames, $remark,
                $prev['confirm_user_id'] ?? null, $prev['confirm_user_name'] ?? null, $prev['confirm_at'] ?? null, $prev['gm_comment'] ?? null]);
            $n++;
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    jout(['meeting_id'=>$id]);
}

case 'delete': {
    $id = (int)($_POST['meeting_id'] ?? 0);
    $m = meeting_load($db, $id);
    if ((int)$m['recorder_user_id'] !== $uid && !$perms['canAdmin']) jerr('僅記錄人本人或管理員可刪除', 403);
    if ($m['status'] !== 'draft' && !$perms['canAdmin']) jerr('已送出的會議記錄僅管理員可刪除');
    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM meeting_attendee WHERE meeting_id=?")->execute([$id]);
        $db->prepare("DELETE FROM meeting_item WHERE meeting_id=?")->execute([$id]);
        $db->prepare("DELETE FROM meeting_record WHERE meeting_id=?")->execute([$id]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('刪除失敗：'.$e->getMessage(), 500); }
    meeting_close_notice($db, $id);
    jout([]);
}

/* 部門人員（出席人員挑選用；比照鐵則走 eg_people_list，不自己拼人員 SQL） */
case 'people': {
    $deptId = (int)($_GET['dept_id'] ?? 0);
    if ($deptId <= 0) jout(['people'=>[]]);
    $rows = eg_people_list($db, ['dept_ids'=>[$deptId]]);
    jout(['people'=>array_map(fn($r) => ['id'=>$r['id'], 'user_cname'=>$r['user_cname'],
        'position_name'=>$r['position_name'] ?? '', 'dept_name'=>$r['dept_name'] ?? ''], $rows)]);
}

/* 與會者本人密碼簽到（共用裝置輪流簽）：身分＝選人，密碼只驗證是本人，不做密碼反查 */
case 'sign': {
    $id = (int)($_POST['meeting_id'] ?? 0);
    $forUid = (int)($_POST['user_id'] ?? 0);
    $password = (string)($_POST['password'] ?? '');
    $m = meeting_load($db, $id);
    $st = $db->prepare("SELECT att_id FROM meeting_attendee WHERE meeting_id=? AND user_id=?");
    $st->execute([$id, $forUid]);
    $attId = (int)$st->fetchColumn();
    if (!$attId) jerr('此人員不在本次會議出席名單內，請先請記錄人加入名單');
    $v = meeting_verify_own_password($db, $forUid, $password);
    if (!$v['ok']) jerr($v['msg']);
    $db->prepare("UPDATE meeting_attendee SET signed=1, signed_at=NOW() WHERE att_id=?")->execute([$attId]);
    jout(['att_id'=>$attId]);
}

/* 送出：draft/rejected → submitted，建立主席簽核並通知（含代理解析） */
case 'submit': {
    $id = (int)($_POST['meeting_id'] ?? 0);
    $m = meeting_load($db, $id);
    if ((int)$m['recorder_user_id'] !== $uid && !$perms['canAdmin']) jerr('僅記錄人本人或管理員可送出', 403);
    if (!in_array($m['status'], ['draft','rejected'], true)) jerr('此會議記錄已送出過');
    if (!$m['chair_user_id']) jerr('請先指定本次會議主席');
    $ac = $db->prepare("SELECT COUNT(*) FROM meeting_attendee WHERE meeting_id=?"); $ac->execute([$id]);
    if ((int)$ac->fetchColumn() === 0) jerr('請先加入出席人員名單');
    $itc = $db->prepare("SELECT COUNT(*) FROM meeting_item WHERE meeting_id=?"); $itc->execute([$id]);
    if ((int)$itc->fetchColumn() === 0) jerr('請至少建立一項會議要項或上級指示要項');

    $chair = meeting_chair_signer_effective($db, (int)$m['chair_user_id'], (string)$m['chair_name']);
    if (!$chair['id']) jerr('找不到主席簽核人');
    $id2 = eg_approval_submit($db, 'meeting', $id, 'chair', $uid, $uname);
    $ev = meeting_notify($db, $id, $chair['id'],
        '「'.$m['subject'].'」會議記錄待主席確認簽章',
        $uname.' 送出「'.$m['subject'].'」（'.$m['meeting_date'].'）會議記錄，請確認內容並簽章（點入可看完整會議要項，並直接確認或退回）。'
        .($chair['is_delegated'] ? '（原主席今日行程忙碌，已轉由代理人處理）' : ''), $uid);
    if ($ev) eg_approval_set_live_event($db, $id2, $ev);
    $db->prepare("UPDATE meeting_record SET status='submitted', updated_at=NOW() WHERE meeting_id=?")->execute([$id]);
    jout(['status'=>'submitted']);
}

/* 主席／總經理 確認簽章或退回：退回一定要填原因，退回後記錄人可修改後重新送出 */
case 'decide': {
    $id = (int)($_POST['meeting_id'] ?? 0);
    $level = (string)($_POST['level'] ?? '');
    $decision = (string)($_POST['decision'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    if (!in_array($level, ['chair','gm'], true)) jerr('階段參數不正確');
    if (!in_array($decision, ['approved','rejected'], true)) jerr('決定值不正確');
    if ($decision === 'rejected' && $note === '') jerr('退回必須填寫原因');
    $m = meeting_load($db, $id);
    $rec = eg_approval_latest($db, 'meeting', $id, $level);
    if (!$rec || $rec['status'] !== 'pending') jerr('此會議記錄目前沒有待您處理的'.($level==='chair'?'主席':'總經理').'簽核項目');

    // 身分驗證：必須是被解析出的簽核人（含代理）或管理員
    if ($level === 'chair') {
        $signer = meeting_chair_signer_effective($db, (int)$m['chair_user_id'], (string)$m['chair_name']);
    } else {
        $signer = meeting_gm_signer_effective($db);
    }
    if (!$perms['canAdmin'] && (!$signer || (int)$signer['id'] !== $uid)) jerr('您不是本階段的簽核人', 403);

    $r = eg_approval_decide($db, (int)$rec['id'], $uid, $uname, $decision, $note ?: null);
    if (!$r['success']) jerr($r['message']);
    meeting_close_notice($db, $id);
    $submitter = (int)$m['recorder_user_id'];

    if ($decision === 'rejected') {
        $db->prepare("UPDATE meeting_record SET status='rejected', updated_at=NOW() WHERE meeting_id=?")->execute([$id]);
        meeting_notify_result($db, $id, $submitter, '「'.$m['subject'].'」會議記錄被退回',
            $uname.'（'.($level==='chair'?'主席':'總經理').'）退回「'.$m['subject'].'」會議記錄。退回原因：'.$note, $uid);
        jout(['status'=>'rejected']);
    }

    if ($level === 'chair') {
        $gm = meeting_gm_signer_effective($db);
        if (!$gm) jerr('尚未設定「最高核准人員」，請先到「組織角色綁定設定」設定', 400);
        $id2 = eg_approval_submit($db, 'meeting', $id, 'gm', $submitter, (string)$m['recorder_name']);
        $ev = meeting_notify($db, $id, $gm['id'],
            '「'.$m['subject'].'」會議記錄待總經理確認簽章',
            '主席已確認「'.$m['subject'].'」（'.$m['meeting_date'].'）會議記錄，請確認並簽章（可逐筆針對要項回覆意見，或一次填寫整體意見後簽章）。'
            .($gm['is_delegated'] ? '（總經理今日行程忙碌，已轉由代理人處理）' : ''), $uid);
        if ($ev) eg_approval_set_live_event($db, $id2, $ev);
        $db->prepare("UPDATE meeting_record SET status='chair_done', updated_at=NOW() WHERE meeting_id=?")->execute([$id]);
        jout(['status'=>'chair_done']);
    }

    // GM 逐筆意見（可選）：{item_id: comment}
    $itemComments = json_decode((string)($_POST['item_comments'] ?? '{}'), true);
    if (is_array($itemComments)) {
        $upd = $db->prepare("UPDATE meeting_item SET gm_comment=? WHERE item_id=? AND meeting_id=?");
        foreach ($itemComments as $iid => $cmt) {
            $cmt = trim((string)$cmt);
            if ($cmt !== '') $upd->execute([$cmt, (int)$iid, $id]);
        }
    }
    $db->prepare("UPDATE meeting_record SET status='done', updated_at=NOW() WHERE meeting_id=?")->execute([$id]);
    meeting_notify_result($db, $id, $submitter, '「'.$m['subject'].'」會議記錄已完成簽核',
        $uname.'（總經理）已確認「'.$m['subject'].'」會議記錄。'.($note ? '整體意見：'.$note : ''), $uid);
    jout(['status'=>'done']);
}

/* 部門指派項目確認簽名：任一位屬於該（多）負責部門、且是本次出席人員的人簽名即完成（不需密碼，走正常登入身分） */
case 'item_confirm': {
    $itemId = (int)($_POST['item_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM meeting_item WHERE item_id=?");
    $st->execute([$itemId]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item) jerr('找不到此項目');
    if ($item['confirm_user_id']) jerr('此項目已由 '.$item['confirm_user_name'].' 確認簽名過');
    $m = meeting_load($db, (int)$item['meeting_id']);
    $ownerIds = array_values(array_filter(array_map('intval', explode(',', (string)$item['owner_depts']))));
    if (!$ownerIds) jerr('此項目未指派負責部門');
    $myDepts = meeting_user_depts($db, $uid);
    if (!array_intersect($ownerIds, $myDepts) && !$perms['canAdmin']) jerr('您不屬於此項目的負責部門', 403);
    $ac = $db->prepare("SELECT 1 FROM meeting_attendee WHERE meeting_id=? AND user_id=?");
    $ac->execute([(int)$item['meeting_id'], $uid]);
    if (!$ac->fetchColumn() && !$perms['canAdmin']) jerr('您不是本次會議出席人員', 403);
    $db->prepare("UPDATE meeting_item SET confirm_user_id=?, confirm_user_name=?, confirm_at=NOW() WHERE item_id=? AND confirm_user_id IS NULL")
       ->execute([$uid, $uname, $itemId]);
    jout([]);
}

/* 出貨目標達成率：資料新鮮度檢查（GET，供插入前的提示） */
case 'kpi_check': {
    jout(meeting_kpi_freshness($db, date('Y-m-d')));
}

/* 插入本月出貨目標達成率快照：先驗新鮮度，未達標直接擋（提示還差幾天） */
case 'kpi_insert': {
    $id = (int)($_POST['meeting_id'] ?? 0);
    $m = meeting_load($db, $id);
    if ((int)$m['recorder_user_id'] !== $uid && !$perms['canAdmin']) jerr('僅記錄人本人或管理員可插入', 403);
    if (!in_array($m['status'], ['draft','rejected'], true)) jerr('此會議記錄已送出，無法再修改');
    $fresh = meeting_kpi_freshness($db, date('Y-m-d'));
    if (!$fresh['ok']) {
        jerr('出貨資料尚未更新至前一個工作天（需至 '.$fresh['need_asof'].'，目前最新僅到 '.($fresh['latest'] ?: '無資料')
            .'），請確認匯入作業完成後再插入，避免會議引用到不完整的數字。');
    }
    $ymd = explode('-', $m['meeting_date']);
    $snap = meeting_kpi_month_summary($db, (int)$ymd[0], (int)$ymd[1]);
    $snap['generated_at'] = date('Y-m-d H:i');
    $snap['data_asof'] = $fresh['latest'];
    $db->prepare("UPDATE meeting_record SET kpi_snapshot_json=?, kpi_snapshot_asof=?, updated_at=NOW() WHERE meeting_id=?")
       ->execute([json_encode($snap, JSON_UNESCAPED_UNICODE), $fresh['latest'], $id]);
    jout(['snapshot'=>$snap]);
}

case 'kpi_remove': {
    $id = (int)($_POST['meeting_id'] ?? 0);
    $m = meeting_load($db, $id);
    if ((int)$m['recorder_user_id'] !== $uid && !$perms['canAdmin']) jerr('僅記錄人本人或管理員可移除', 403);
    if (!in_array($m['status'], ['draft','rejected'], true)) jerr('此會議記錄已送出，無法再修改');
    $db->prepare("UPDATE meeting_record SET kpi_snapshot_json=NULL, kpi_snapshot_asof=NULL WHERE meeting_id=?")->execute([$id]);
    jout([]);
}

default: jerr('未知的操作：'.$action);
}
