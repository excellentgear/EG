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
    // 出席簽到／項目確認簽名要套用哪個圖章模板(圖章管理→線上圖章設計)；未設定則維持預設的回墨印SVG
    $stTplId = (int)meeting_setting_get($db, 'meeting_stamp_tpl_id', '0');
    $stTpl = null;
    if ($stTplId) {
        $stt = $db->prepare("SELECT id, tpl_name, schema_json FROM stamp_template WHERE id=? AND is_active=1");
        $stt->execute([$stTplId]);
        if ($r = $stt->fetch(PDO::FETCH_ASSOC)) {
            $stTpl = ['id'=>(int)$r['id'], 'tpl_name'=>$r['tpl_name'], 'schema'=>json_decode((string)$r['schema_json'], true)];
        }
    }
    jout(['perms'=>$perms, 'departments'=>$depts, 'years'=>$years,
          'uid'=>$uid, 'uname'=>$uname, 'today'=>date('Y-m-d'), 'cur_year'=>$cy,
          'gm_name'=>$gm ? $gm['user_cname'] : null, 'gm_id'=>$gm ? (int)$gm['id'] : null, 'presets'=>$presets,
          'company_name'=>eg_company_full_name($db), 'features'=>MEETING_FEATURES,
          'attach_nas_dir'=>$perms['canAdmin'] ? meeting_setting_get($db, 'meeting_nas_dir', '') : null,
          'as_doc_signsheet'=>($asSign = eg_asdoc_get($db, 'meeting_signsheet')), 'as_doc_signsheet_no'=>eg_asdoc_no($asSign),
          'as_doc_record'=>($asRec = eg_asdoc_get($db, 'meeting_record')), 'as_doc_record_no'=>eg_asdoc_no($asRec),
          'stamp_template'=>$stTpl]);
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
    $at = $db->prepare("SELECT attach_id, original_name, attach_type, file_name, created_by_name, created_at
                        FROM meeting_attach WHERE meeting_id=? AND status='active' ORDER BY attach_id");
    $at->execute([$id]);
    $dir = meeting_attach_dir($db);
    $attaches = [];
    foreach ($at->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $a['exists'] = is_file($dir . basename((string)$a['file_name']));
        unset($a['file_name']);
        $attaches[] = $a;
    }
    // 每個項目附上「未出席部門成員」的回簽狀態(通知系統)，供畫面顯示回簽者/回簽日期
    $items = meeting_items($db, $id);
    $ntStmt = $db->prepare("SELECT lt.target_id AS user_id, u.user_cname AS user_name, lr.read_at, lr.signed_at
                             FROM live_event le
                             JOIN live_event_target lt ON lt.live_event_id = le.id
                             LEFT JOIN live_event_response lr ON lr.live_event_id = le.id AND lr.user_id = lt.target_id
                             LEFT JOIN `user` u ON u.id = lt.target_id
                             WHERE le.ref_type='MEETING_ITEM_CONFIRM' AND le.ref_id=?
                             ORDER BY lt.id");
    foreach ($items as &$it) {
        $ntStmt->execute([(int)$it['item_id']]);
        $it['notify_targets'] = $ntStmt->fetchAll(PDO::FETCH_ASSOC);
        // 本次出席人員中，現況部門屬於此項目負責部門者：可在現場用本人密碼確認(item_confirm)
        $ownerIds = array_values(array_filter(array_map('intval', explode(',', (string)$it['owner_depts']))));
        if ($ownerIds) {
            $inDept = implode(',', $ownerIds);
            $eaStmt = $db->prepare("SELECT DISTINCT a.user_id, a.user_name FROM meeting_attendee a
                                     WHERE a.meeting_id=? AND EXISTS(
                                         SELECT 1 FROM user_department_position_map m WHERE m.user_id=a.user_id AND m.department_id IN ($inDept)
                                     )");
            $eaStmt->execute([$id]);
            $it['eligible_attendees'] = $eaStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $it['eligible_attendees'] = [];
        }
    }
    unset($it);
    jout(['meeting'=>$m, 'items'=>$items, 'attendees'=>meeting_attendees($db, $id), 'attaches'=>$attaches]);
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
            $kind = in_array($it['kind'] ?? '', ['directive','announce'], true) ? $it['kind'] : 'general';
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
        // 暫存附件轉正（與主單同一筆交易內；限本人上傳的 temp）
        $tempIds = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['temp_attach_ids'] ?? '')))));
        if ($tempIds) {
            $in = implode(',', $tempIds);
            $db->prepare("UPDATE meeting_attach SET meeting_id=?, status='active', expire_at=NULL
                          WHERE attach_id IN ({$in}) AND created_by=? AND status='temp'")->execute([$id, $uid]);
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

    // 送出前置檢查(2026-08-05 使用者明確要求)：①出席人員全部簽到 ②有指派負責部門且該部門在本次出席人員內者，該項目須已現場確認簽名。
    // 未出席的負責部門成員不受此檢查限制，維持送出後才發通知回簽的既有設計，避免卡死無法送出。
    $unsigned = $db->prepare("SELECT COUNT(*) FROM meeting_attendee WHERE meeting_id=? AND signed=0");
    $unsigned->execute([$id]);
    if ((int)$unsigned->fetchColumn() > 0) jerr('尚有出席人員未完成現場簽到，請先完成全部出席人員簽到再送出');
    foreach (meeting_items($db, $id) as $it) {
        if ($it['confirm_user_id']) continue;
        $ownerIds = array_values(array_filter(array_map('intval', explode(',', (string)$it['owner_depts']))));
        if (!$ownerIds) continue;
        $inDept = implode(',', $ownerIds);
        $elig = $db->prepare("SELECT COUNT(*) FROM meeting_attendee a WHERE a.meeting_id=? AND EXISTS(
                                   SELECT 1 FROM user_department_position_map m WHERE m.user_id=a.user_id AND m.department_id IN ($inDept)
                               )");
        $elig->execute([$id]);
        if ((int)$elig->fetchColumn() > 0) {
            jerr('項目「'.mb_substr((string)$it['content'], 0, 20).'…」的負責部門有出席人員尚未現場確認簽名，請先完成確認再送出');
        }
    }

    $chair = meeting_chair_signer_effective($db, (int)$m['chair_user_id'], (string)$m['chair_name']);
    if (!$chair['id']) jerr('找不到主席簽核人');
    $id2 = eg_approval_submit($db, 'meeting', $id, 'chair', $uid, $uname);
    $ev = meeting_notify($db, $id, $chair['id'],
        '「'.$m['subject'].'」會議記錄待主席確認簽章',
        $uname.' 送出「'.$m['subject'].'」（'.$m['meeting_date'].'）會議記錄，請確認內容並簽章（點入可看完整會議要項，並直接確認或退回）。'
        .($chair['is_delegated'] ? '（原主席今日行程忙碌，已轉由代理人處理）' : ''), $uid);
    if ($ev) eg_approval_set_live_event($db, $id2, $ev);

    // 逐項通知：負責部門中「本次未出席」的成員走通知系統回簽；有出席的成員已可在會議記錄畫面現場用密碼確認，不重複通知
    foreach (meeting_items($db, $id) as $it) {
        $ownerIds = array_values(array_filter(array_map('intval', explode(',', (string)$it['owner_depts']))));
        if (!$ownerIds) continue;
        $targets = meeting_dept_nonattendee_targets($db, $id, $ownerIds);
        if (!$targets) continue;
        $inDept = implode(',', $ownerIds);
        $deptNames = $db->query("SELECT name FROM department WHERE id IN ($inDept)")->fetchAll(PDO::FETCH_COLUMN);
        meeting_notify_item_owners($db, (int)$it['item_id'], $targets,
            '「'.$m['subject'].'」會議記錄項目待確認：'.mb_substr((string)$it['content'], 0, 30),
            '「'.$m['subject'].'」（'.$m['meeting_date'].'）會議記錄的以下負責部門項目請確認並回簽：'."\n".$it['content']
            .($it['due_date'] ? ("\n應完成日期：".$it['due_date']) : '')
            ."\n負責部門：".implode('、', $deptNames)
            .(count($targets) > 1 ? "\n（任一人回簽即完成，不需每人都簽）" : ''),
            $uid);
    }

    $db->prepare("UPDATE meeting_record SET status='submitted', updated_at=NOW() WHERE meeting_id=?")->execute([$id]);
    jout(['status'=>'submitted']);
}

/* 撤回已送出但「尚未任何人簽核」的會議記錄(2026-08-05 使用者明確要求)：
   限 approval_status==='submitted'(僅主席待簽、連主席都還沒簽)才可撤回；一旦有人簽過(chair_done/done/rejected)一律不可撤回，
   避免破壞已存在的簽核歷程。刪除待簽核的 chair 簽核紀錄、關閉主席簽核通知與本次送出所發的項目確認回簽通知，狀態退回 draft
   供記錄人修改(如負責部門/主席人選)後重新送出。權限比照編輯/刪除：記錄人本人或管理員。 */
case 'withdraw': {
    $id = (int)($_POST['meeting_id'] ?? 0);
    $m = meeting_load($db, $id);
    if ((int)$m['recorder_user_id'] !== $uid && !$perms['canAdmin']) jerr('僅記錄人本人或管理員可撤回', 403);
    $ap = meeting_approval_status($db, $id);
    if ($ap['status'] !== 'submitted') jerr('僅「待主席簽章」且尚未有人簽核時可撤回，此記錄目前狀態不允許撤回');
    if ($ap['chair']) $db->prepare("DELETE FROM approval_record WHERE id=?")->execute([(int)$ap['chair']['id']]);
    meeting_close_notice($db, $id);
    meeting_close_item_notices($db, $id);
    $db->prepare("UPDATE meeting_record SET status='draft', updated_at=NOW() WHERE meeting_id=?")->execute([$id]);
    jout(['status'=>'draft']);
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

/* 部門指派項目現場確認簽名：限「本次出席人員」用本人密碼確認(比照簽到表的密碼驗證，共用裝置也知道究竟是誰簽的)。
   未出席的部門成員無法在現場輸入密碼，一律改走通知系統回簽(送出會議記錄時自動發送，見 submit 與 _eventRespond.php 的 MEETING_ITEM_CONFIRM 掛勾)。 */
case 'item_confirm': {
    $itemId = (int)($_POST['item_id'] ?? 0);
    $forUid = (int)($_POST['user_id'] ?? 0);
    $password = (string)($_POST['password'] ?? '');
    $st = $db->prepare("SELECT * FROM meeting_item WHERE item_id=?");
    $st->execute([$itemId]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item) jerr('找不到此項目');
    if ($item['confirm_user_id']) jerr('此項目已由 '.$item['confirm_user_name'].' 確認簽名過');
    $ownerIds = array_values(array_filter(array_map('intval', explode(',', (string)$item['owner_depts']))));
    if (!$ownerIds) jerr('此項目未指派負責部門');
    $ac = $db->prepare("SELECT user_name FROM meeting_attendee WHERE meeting_id=? AND user_id=?");
    $ac->execute([(int)$item['meeting_id'], $forUid]);
    $attName = $ac->fetchColumn();
    if (!$attName) jerr('此人不是本次會議出席人員，無法現場確認；請改由通知系統回簽', 403);
    $myDepts = meeting_user_depts($db, $forUid);
    if (!array_intersect($ownerIds, $myDepts)) jerr('此人不屬於此項目的負責部門', 403);
    $v = meeting_verify_own_password($db, $forUid, $password);
    if (!$v['ok']) jerr($v['msg']);
    $db->prepare("UPDATE meeting_item SET confirm_user_id=?, confirm_user_name=?, confirm_at=NOW() WHERE item_id=? AND confirm_user_id IS NULL")
       ->execute([$forUid, $attName, $itemId]);
    jout([]);
}

/* 出貨目標達成率：資料新鮮度檢查（GET，供插入前的提示） */
case 'kpi_check': {
    jout(meeting_kpi_freshness($db, date('Y-m-d')));
}

/* 插入本月出貨目標達成率快照：先驗新鮮度，未達標直接擋（提示還差幾天） */
case 'kpi_insert': {
    if (empty($perms['canKpiInsert'])) jerr('您沒有插入出貨目標達成率的權限，請洽管理員於「角色設定」開放', 403);
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
    $snap = meeting_kpi_snapshot($db, (int)$ymd[0], (int)$ymd[1]);
    $snap['generated_at'] = date('Y-m-d H:i');
    $snap['data_asof'] = $fresh['latest'];
    $snap['ship_latest'] = $fresh['latest'];
    try { $snap['return_latest'] = $db->query("SELECT MAX(IR_date) FROM ir_track")->fetchColumn() ?: null; } catch (Throwable $e) { $snap['return_latest'] = null; }
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

/* ===== 附件（手動輸入類型/說明，無固定分類；草稿階段 meeting_id=0 暫存，存檔時轉正） ===== */
case 'attach_upload': {
    if (!$perms['canEdit']) jerr('無編輯權限', 403);
    $mid = (int)($_POST['meeting_id'] ?? 0);
    if ($mid > 0) {
        $st = $db->prepare("SELECT 1 FROM meeting_record WHERE meeting_id=?");
        $st->execute([$mid]);
        if (!$st->fetchColumn()) jerr('找不到會議記錄');
    }
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) jerr('請選擇檔案');
    if ((int)$_FILES['file']['size'] > 20*1024*1024) jerr('單檔上限 20MB');
    $orig = basename((string)$_FILES['file']['name']);
    $ext = pathinfo($orig, PATHINFO_EXTENSION);
    $fname = date('Ymd_His_') . bin2hex(random_bytes(4)) . ($ext !== '' ? '.' . preg_replace('/[^A-Za-z0-9]/', '', $ext) : '');
    $dir = meeting_attach_dir($db);
    if (!eg_attach_ensure_dir($dir)) jerr('無法建立附件目錄，請確認「附件路徑設定」（含網路磁碟權限）：'.$dir, 500);
    if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dir . $fname)) jerr('存檔失敗（請確認附件路徑設定與權限）', 500);
    $attachType = trim((string)($_POST['attach_type'] ?? '')) ?: null;
    if ($mid > 0) {
        $db->prepare("INSERT INTO meeting_attach (meeting_id, file_name, original_name, attach_type, status, created_by, created_by_name)
                      VALUES (?,?,?,?,'active',?,?)")->execute([$mid, $fname, $orig, $attachType, $uid, $uname]);
    } else {
        $db->prepare("INSERT INTO meeting_attach (meeting_id, file_name, original_name, attach_type, status, expire_at, created_by, created_by_name)
                      VALUES (0,?,?,?,'temp', DATE_ADD(NOW(), INTERVAL 2 DAY),?,?)")->execute([$fname, $orig, $attachType, $uid, $uname]);
    }
    jout(['attach_id'=>(int)$db->lastInsertId()]);
}
case 'attach_delete': {
    if (!$perms['canEdit']) jerr('無編輯權限', 403);
    $aid = (int)($_POST['attach_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM meeting_attach WHERE attach_id=?");
    $st->execute([$aid]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) jerr('找不到附件');
    $fp = meeting_attach_dir($db) . basename((string)$a['file_name']);
    if (is_file($fp)) @unlink($fp);
    $db->prepare("DELETE FROM meeting_attach WHERE attach_id=?")->execute([$aid]);
    jout([]);
}
case 'attach_list': {
    $mid = (int)($_GET['meeting_id'] ?? 0);
    if ($mid <= 0) jout(['attaches'=>[]]);
    $st = $db->prepare("SELECT attach_id, original_name, attach_type, file_name, created_by_name, created_at
                        FROM meeting_attach WHERE meeting_id=? AND status='active' ORDER BY attach_id");
    $st->execute([$mid]);
    $out = [];
    $dir = meeting_attach_dir($db);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $a['exists'] = is_file($dir . basename((string)$a['file_name']));
        unset($a['file_name']);
        $out[] = $a;
    }
    jout(['attaches'=>$out]);
}
case 'download_attach': {
    $aid = (int)($_GET['attach_id'] ?? 0);
    $st = $db->prepare("SELECT file_name, original_name FROM meeting_attach WHERE attach_id=?");
    $st->execute([$aid]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) jerr('找不到附件', 404);
    $fp = meeting_attach_dir($db) . basename((string)$a['file_name']);
    if (!is_file($fp)) jerr('檔案不存在（可能已被移動或附件路徑設定已變更）：'.$a['file_name'], 404);
    $ext = strtolower(pathinfo($a['file_name'], PATHINFO_EXTENSION));
    $mime = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
             'gif'=>'image/gif','webp'=>'image/webp','bmp'=>'image/bmp','txt'=>'text/plain; charset=utf-8'][$ext] ?? 'application/octet-stream';
    $inline = (bool)preg_match('#^(image/|application/pdf|text/)#', $mime);
    header_remove('Content-Type');
    header('Content-Type: '.$mime);
    header('Content-Length: '.filesize($fp));
    header('Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename*=UTF-8\'\''.rawurlencode((string)($a['original_name'] ?: $a['file_name'])));
    readfile($fp);
    exit;
}

/* ===== 附件儲存路徑 / 簽到表 AS 文件綁定設定（限管理員） ===== */
case 'attach_setting_save': {
    if (!$perms['canAdmin']) jerr('無設定權限（限模組管理員）', 403);
    meeting_setting_save($db, 'meeting_nas_dir', trim((string)($_POST['nas_dir'] ?? '')));
    jout(['attach_nas_dir'=>meeting_setting_get($db, 'meeting_nas_dir', '')]);
}
case 'as_doc_signsheet_save': {
    if (!$perms['canAdmin']) jerr('無設定權限（限模組管理員）', 403);
    eg_asdoc_save($db, 'meeting_signsheet', (int)($_POST['doc_id'] ?? 0), $uname);
    jout(['as_doc_signsheet'=>eg_asdoc_get($db, 'meeting_signsheet')]);
}
case 'as_doc_record_save': {
    if (!$perms['canAdmin']) jerr('無設定權限（限模組管理員）', 403);
    eg_asdoc_save($db, 'meeting_record', (int)($_POST['doc_id'] ?? 0), $uname);
    jout(['as_doc_record'=>eg_asdoc_get($db, 'meeting_record')]);
}
/* 出席簽到／項目確認簽名要套用哪個圖章模板：清單只給啟用中的模板讓管理員挑（模板本身在圖章管理頁「線上圖章設計」維護，這裡不重刻） */
case 'stamp_tpl_options': {
    if (!$perms['canAdmin']) jerr('無設定權限（限模組管理員）', 403);
    jout(['templates'=>$db->query("SELECT p.id, p.tpl_name, t.type_name
                                    FROM stamp_template p LEFT JOIN stamp_type t ON t.id=p.type_id
                                    WHERE p.is_active=1 ORDER BY p.tpl_name")->fetchAll(PDO::FETCH_ASSOC)]);
}
case 'stamp_tpl_save': {
    if (!$perms['canAdmin']) jerr('無設定權限（限模組管理員）', 403);
    $tid = (int)($_POST['template_id'] ?? 0);
    meeting_setting_save($db, 'meeting_stamp_tpl_id', (string)$tid);
    jout([]);
}
/* 出貨目標達成率(週報)基礎設定：週目標金額/帳款起始日，與 AS9100 KPI 設定頁(KpiAs_Setting_API.php)共用同一組 kpi_lib.php 函式，
   兩邊都留入口方便維護，不因 Shipping_Analysis_new.php 存廢而找不到地方改。 */
case 'kpi_target_get': {
    if (!$perms['canAdmin']) jerr('無設定權限（限模組管理員）', 403);
    $y = (int)($_GET['year'] ?? date('Y'));
    $m = (int)($_GET['month'] ?? date('n'));
    jout(kpi_target_get($db, $y, $m));
}
case 'kpi_target_save': {
    if (!$perms['canAdmin']) jerr('無設定權限（限模組管理員）', 403);
    $y = (int)($_POST['year'] ?? 0);
    $m = (int)($_POST['month'] ?? 0);
    if ($y < 2000 || $m < 1 || $m > 12) jerr('年月參數錯誤');
    kpi_target_save($db, $y, $m, (float)($_POST['target_amount'] ?? 0), (int)($_POST['start_day'] ?? 1));
    jout([]);
}
case 'asdoc_list': {
    if (!$perms['canAdmin']) jerr('無設定權限（限模組管理員）', 403);
    jout(['docs'=>eg_asdoc_list($db)]);
}

/* 合併列印「製表人」候選：出席者中屬於業務部門(含子部門)且職稱有設職級者，取職級最基層的那一位(多人時列出讓使用者選) */
case 'preparer_candidates': {
    $mid = (int)($_GET['meeting_id'] ?? 0);
    $m = meeting_load($db, $mid);
    if (!meeting_can_view($db, $uid, $perms, $m)) jerr('無權檢視此會議記錄', 403);
    jout(['candidates'=>meeting_preparer_candidates($db, $mid)]);
}

/* 新增會議紀錄時可從行事曆挑選「當天」的會議事件自動帶入日期/時間/主題/出席人員(使用者明確要求限當天,不含未來)；
   行事曆會議類別 category_id=2(event_category)；出席人員來自 evenement_actor，禁止自己寫死。 */
case 'calendar_meetings': {
    $st = $db->query("SELECT id, title, start, end FROM evenement WHERE category_id=2 AND DATE(start)=CURDATE() ORDER BY start LIMIT 50");
    $events = $st->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_column($events, 'id');
    $actorsByEvent = [];
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $ast = $db->query("SELECT ea.event_id, u.id AS user_id, u.user_cname AS user_name,
                                   d.name AS dept_name, p.name AS position_name
                            FROM evenement_actor ea
                            JOIN `user` u ON u.id=ea.user_id
                            LEFT JOIN user_department_position_map m ON m.user_id=u.id AND m.is_main=1
                            LEFT JOIN department d ON d.id=m.department_id
                            LEFT JOIN position p ON p.id=m.position_id
                            WHERE ea.event_id IN ($in)");
        foreach ($ast->fetchAll(PDO::FETCH_ASSOC) as $a) $actorsByEvent[(int)$a['event_id']][] = $a;
    }
    foreach ($events as &$e) { $e['actors'] = $actorsByEvent[(int)$e['id']] ?? []; }
    unset($e);
    jout(['events'=>$events]);
}

default: jerr('未知的操作：'.$action);
}
