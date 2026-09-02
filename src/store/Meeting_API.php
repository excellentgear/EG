<?php
/**
 * 會議紀錄管理 API（2-GM-05-01／2-GM-05-03）
 * 權限：meeting_lib.php meeting_perms()（roles module='meeting'）；單筆檢視另受 meeting_can_view() 限制（草稿僅本人）。
 * 讀：GET；寫：POST，transaction。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/meeting_lib.php';
include_once $document_root . '/EGsystem/src/common/people_lib.php';
include_once $document_root . '/EGsystem/src/common/person_schedule_lib.php';

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

/**
 * 人員清單 → 前端要的欄位，並附上「當天的行程」（2026-08-26 使用者明確要求）
 * 每個人多帶：sched(行程陣列)、sched_text(名字右方要顯示的字)、blocked(1=不可勾選)、block_reason
 * 阻擋規則收在 person_schedule_lib.php（目前只有請假會擋），這裡不自己判定，避免兩邊走鐘。
 */
function meeting_people_with_schedule(PDO $db, array $rows, string $mDate, string $st, string $et, int $excludeMeetingId = 0): array {
    $sched = ($mDate !== '')
           ? eg_psched_for_users($db, array_column($rows, 'id'), $mDate, $st, $et,
                                 ['exclude_meeting_id'=>$excludeMeetingId])
           : [];
    return array_map(function ($r) use ($sched) {
        $items   = $sched[(int)$r['id']] ?? [];
        $blocked = eg_psched_blocked($items);
        $reason  = '';
        foreach ($items as $it) if (!empty($it['blocks'])) { $reason = $it['label'] . '：' . $it['text']; break; }
        return [
            'id'            => $r['id'],
            'user_cname'    => $r['user_cname'],
            'position_name' => $r['position_name'] ?? '',
            'dept_name'     => $r['dept_name'] ?? '',
            'state'         => (int)($r['state'] ?? 1),
            'sched'         => $items,
            'sched_text'    => implode('、', array_map(fn($x) => $x['text'], $items)),
            'has_overlap'   => (int)(bool)array_filter($items, fn($x) => !empty($x['overlap'])),
            'blocked'       => $blocked ? 1 : 0,
            'block_reason'  => $reason,
        ];
    }, $rows);
}

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
    jout(['perms'=>$perms, 'departments'=>$depts, 'years'=>$years, 'is_superadmin'=>meeting_is_superadmin($db, $uid),
          'uid'=>$uid, 'uname'=>$uname, 'today'=>date('Y-m-d'), 'cur_year'=>$cy,
          'gm_name'=>$gm ? $gm['user_cname'] : null, 'gm_id'=>$gm ? (int)$gm['id'] : null, 'presets'=>$presets,
          'company_name'=>eg_company_full_name($db), 'features'=>MEETING_FEATURES,
          'attach_nas_dir'=>$perms['canAdmin'] ? meeting_setting_get($db, 'meeting_nas_dir', '') : null,
          'auto_submit'=>meeting_auto_submit_enabled($db),
          'as_doc_signsheet'=>($asSign = eg_asdoc_get($db, 'meeting_signsheet')), 'as_doc_signsheet_no'=>eg_asdoc_no($asSign),
          'as_doc_record'=>($asRec = eg_asdoc_get($db, 'meeting_record')), 'as_doc_record_no'=>eg_asdoc_no($asRec),
          'stamp_template'=>$stTpl,
          // 挑出席人員時要提示哪些行程來源（全站共用設定，定義與現值都由共用庫給，前端不寫死＝鐵律4）
          'sched_sources'=>eg_psched_sources(), 'sched_on'=>eg_psched_setting_get($db)]);
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

/* 依 user_id 清單重新解析目前的部門/職稱（出席人員群組套用用；資料以「現況」為準，不是群組儲存當下的舊快照）。
   2026-08-06使用者明確要求：出席人員不應該選得到會議當天時段有請假的人，也不列超級管理員(states排除99)；
   套用群組也是「加入出席人員」的一種途徑，一併過濾。有帶會議日期才做請假過濾，沒帶就不過濾(避免誤擋)。 */
case 'resolve_people': {
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['user_ids'] ?? '')))));
    if (!$ids) jout(['people'=>[]]);
    $mDate = trim((string)($_GET['meeting_date'] ?? ''));
    // prefer_main（2026-09-01 使用者回報）：這支是「群組套用」與「從行事曆帶入」共用的解析器，兩者都**沒有部門情境**
    // ——使用者選的是一群人，不是某個部門的人。不開 prefer_main 的話，共用庫會依職級挑兼任那筆，於是主職「技術部
    // 工程師」的人被帶成「生管組 組長」，跟 calendar.php 上看到的職稱（該頁一律 is_main=1）與群組原先設定的職稱對不
    // 起來。依部門挑選的 'people' action 不可比照辦理——那份名單本來就是在講那個部門。
    $opt = ['user_ids'=>$ids, 'states'=>[1,2,3], 'prefer_main'=>true];
    $rows = ($mDate !== '') ? eg_people_list_asof($db, $opt, $mDate)
                            : eg_people_list($db, $opt);
    jout(['people'=>meeting_people_with_schedule($db, $rows, $mDate,
        (string)($_GET['start_time'] ?? ''), (string)($_GET['end_time'] ?? ''),
        (int)($_GET['meeting_id'] ?? 0))]);
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
        // 「回簽中」/「待送簽核」(2026-08-10使用者明確要求)：draft/rejected 階段再細分子狀態，
        // 避免存檔並通知中、跟全部確認完成待送簽核、跟什麼都還沒做的新草稿，在畫面上長得一模一樣。
        $m['approval_status'] = meeting_display_status($db, $m);
        $m['notifying'] = $m['approval_status'] === 'notifying';
        $m['is_mine'] = (int)$m['recorder_user_id'] === $uid;
        $m['can_print'] = meeting_can_print($uid, $perms, $m);
        $out[] = $m;
    }
    jout(['meetings'=>$out]);
}

case 'get_detail': {
    $id = (int)($_GET['meeting_id'] ?? 0);
    $m = meeting_load($db, $id);
    if (!meeting_can_view($db, $uid, $perms, $m)) jerr('無權檢視此會議記錄', 403);
    $ap = meeting_approval_status($db, $id);
    $m['approval_status'] = meeting_display_status($db, $m);
    $m['notifying'] = $m['approval_status'] === 'notifying';
    $m['chair_approval'] = $ap['chair'];
    $m['gm_approval'] = $ap['gm'];
    $m['can_edit'] = ((int)$m['recorder_user_id'] === $uid || $perms['canAdmin']) && in_array($m['status'], ['draft','rejected'], true) && !$m['notifying'];
    $m['can_print'] = meeting_can_print($uid, $perms, $m);
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
    // 每個項目附上通知系統的回覆狀態(含回覆內容/回覆附件)，供畫面顯示回覆者/回覆日期/回覆了什麼
    $items = meeting_items($db, $id);
    $ntStmt = $db->prepare("SELECT lt.target_id AS user_id, u.user_cname AS user_name, lr.id AS resp_id,
                                    lr.read_at, lr.signed_at, lr.reply_content, lr.replied_at
                             FROM live_event le
                             JOIN live_event_target lt ON lt.live_event_id = le.id
                             LEFT JOIN live_event_response lr ON lr.live_event_id = le.id AND lr.user_id = lt.target_id
                             LEFT JOIN `user` u ON u.id = lt.target_id
                             WHERE le.ref_type='MEETING_ITEM_CONFIRM' AND le.ref_id=?
                             ORDER BY lt.id");
    $nfStmt = $db->prepare("SELECT id, file_name FROM live_event_resp_file WHERE response_id=?");
    $confStmt = $db->prepare("SELECT user_id, user_name, dept_name, dept_id, confirmed_at, reply_content FROM meeting_item_confirm WHERE item_id=?");
    foreach ($items as &$it) {
        $ntStmt->execute([(int)$it['item_id']]);
        $ntRows = $ntStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($ntRows as &$nr) {
            $nr['files'] = [];
            if ($nr['resp_id']) {
                $nfStmt->execute([(int)$nr['resp_id']]);
                $nr['files'] = $nfStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($nr['resp_id']);
        }
        unset($nr);
        $it['notify_targets'] = $ntRows;

        // 每個負責部門/指定人員都固定顯示一格「確認簽名/回簽狀態」(2026-08-10使用者實測回報修正)：
        // 舊版只有 meeting_item_required_signers_for 找得到「本次有出席的代表」才會出現簽名槽，
        // 完全沒人出席的部門即使已經有人透過通知回覆確認，畫面上仍然整格空白看不出已完成。
        // 現在改成不論本次是否有人出席，該部門/該指定人員一律各佔一格：有出席→可現場密碼簽名(can_sign_in_person)，
        // 沒出席→只能透過下方通知回覆(靠 dept_id/user_id 比對是否已經有人確認)。
        $req = meeting_item_required_signers_for($db, $id, $it); // 現場代表(僅本次有出席者)，keyed by dept_id 或 user_id
        $ownerUserIds = array_values(array_filter(array_map('intval', explode(',', (string)($it['owner_users'] ?? '')))));
        $ownerDeptIds = $ownerUserIds ? [] : array_values(array_filter(array_map('intval', explode(',', (string)($it['owner_depts'] ?? '')))));
        $slots = [];
        if ($ownerUserIds || $ownerDeptIds) {
            $confStmt->execute([(int)$it['item_id']]);
            $confirmRows = $confStmt->fetchAll(PDO::FETCH_ASSOC);
            $signedByDept = []; $signedByUser = [];
            foreach ($confirmRows as $sr) {
                if ($sr['dept_id'] !== null) { $d = (int)$sr['dept_id']; if (!isset($signedByDept[$d])) $signedByDept[$d] = $sr; }
                $signedByUser[(int)$sr['user_id']] = $sr;
            }
            // 實際簽名者的職稱(2026-08-10使用者要求要跟簽到表格式相同，圖章模板含職稱token時才會用到)：
            // 若簽名者剛好就是 required_signers 算出的那位現場代表，直接沿用(已含職稱)；否則(部門其他出席人員、
            // 或未出席透過通知回覆的人)現場查 user_department_position_map，優先取該負責部門底下的職稱，查不到才退回主要職稱。
            $resolvePosition = function(int $confUid, ?int $deptId) use ($db): string {
                if ($deptId !== null) {
                    $st = $db->prepare("SELECT p.name FROM user_department_position_map m LEFT JOIN position p ON p.id=m.position_id
                                         WHERE m.user_id=? AND m.department_id=? LIMIT 1");
                    $st->execute([$confUid, $deptId]);
                    $name = $st->fetchColumn();
                    if ($name !== false && $name !== null && $name !== '') return (string)$name;
                }
                $st2 = $db->prepare("SELECT p.name FROM user_department_position_map m LEFT JOIN position p ON p.id=m.position_id
                                      WHERE m.user_id=? ORDER BY m.is_main DESC LIMIT 1");
                $st2->execute([$confUid]);
                return (string)($st2->fetchColumn() ?: '');
            };
            if ($ownerUserIds) {
                $in = implode(',', $ownerUserIds);
                $names = $db->query("SELECT id, user_cname FROM `user` WHERE id IN ($in)")->fetchAll(PDO::FETCH_KEY_PAIR);
                foreach ($ownerUserIds as $ou) {
                    $sr = $signedByUser[$ou] ?? null;
                    $reqEntry = $req[$ou] ?? null;
                    $confUid = $sr ? (int)$sr['user_id'] : $ou;
                    $posName = $sr
                        ? (($reqEntry && (int)$reqEntry['user_id'] === $confUid) ? (string)$reqEntry['position_name'] : $resolvePosition($confUid, null))
                        : (string)($reqEntry['position_name'] ?? '');
                    $slots[] = ['dept_id'=>null, 'dept_name'=>'',
                                'user_id'=>$confUid,
                                'user_name'=>$sr ? $sr['user_name'] : ($names[$ou] ?? ''),
                                'position_name'=>$posName,
                                'is_manager'=>true, 'is_main'=>true, 'can_sign_in_person'=>(bool)$reqEntry,
                                'signed'=>(bool)$sr, 'confirmed_at'=>$sr['confirmed_at'] ?? null,
                                'reply_content'=>$sr['reply_content'] ?? null];
                }
            } else {
                $in = implode(',', $ownerDeptIds);
                $deptNames = $db->query("SELECT id, name FROM department WHERE id IN ($in)")->fetchAll(PDO::FETCH_KEY_PAIR);
                foreach ($ownerDeptIds as $d) {
                    $sr = $signedByDept[$d] ?? null;
                    $reqEntry = $req[$d] ?? null;
                    $confUid = $sr ? (int)$sr['user_id'] : (int)($reqEntry['user_id'] ?? 0);
                    $posName = $sr
                        ? (($reqEntry && (int)$reqEntry['user_id'] === $confUid) ? (string)$reqEntry['position_name'] : $resolvePosition($confUid, $d))
                        : (string)($reqEntry['position_name'] ?? '');
                    $slots[] = ['dept_id'=>$d, 'dept_name'=>$deptNames[$d] ?? '',
                                'user_id'=>$confUid,
                                'user_name'=>$sr ? $sr['user_name'] : ($reqEntry['user_name'] ?? ''),
                                'position_name'=>$posName,
                                'is_manager'=>$reqEntry['is_manager'] ?? true, 'is_main'=>$reqEntry['is_main'] ?? true,
                                'can_sign_in_person'=>(bool)$reqEntry,
                                'signed'=>(bool)$sr, 'confirmed_at'=>$sr['confirmed_at'] ?? null,
                                'reply_content'=>$sr['reply_content'] ?? null];
                }
            }
        }
        $it['confirm_slots'] = $slots;
        // 送出前預覽「若現在送出會通知誰」(2026-08-07使用者實測回報：董事長室這種未出席部門，送出前完全看不出
        // 會通知誰，還以為設定沒生效)：只在還沒真的發過通知(notify_targets為空)時才算，已送出過的一律照實際
        // 通知紀錄顯示，不要疊加預覽造成混淆。
        $hasOwner = trim((string)($it['owner_depts'] ?? '')) !== '' || trim((string)($it['owner_users'] ?? '')) !== '';
        if ($hasOwner && !$it['notify_targets']) {
            $pids = meeting_item_pending_notify_targets($db, $id, $it);
            if ($pids) {
                $in = implode(',', $pids);
                $it['notify_preview'] = $db->query("SELECT user_cname FROM `user` WHERE id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $it['notify_preview'] = [];
            }
        }
    }
    unset($it);
    // AS 文件編號一律依「這筆會議記錄自己的會議日期」回推當時生效的版次列印，不是印現在最新版
    // （ai-rules/16 第三之二節；例：A 版 2025.01.01 生效、B 版 2025.12.09 生效，列印 2025.09.08 的會議紀錄要印 A 版）
    $m['as_doc_record_no'] = eg_asdoc_no_asof($db, 'meeting_record', (string)$m['meeting_date']);
    $m['as_doc_signsheet_no'] = eg_asdoc_no_asof($db, 'meeting_signsheet', (string)$m['meeting_date']);
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
        // 「回簽中」鎖定(2026-08-10使用者明確要求)：存檔並通知後，負責人尚未全部回覆確認前不可編輯，
        // 避免對方回覆的是已經被改掉的舊內容；要改內容請先按「撤回」解除鎖定。
        if (meeting_has_active_item_notices($db, $id)) jerr('此會議記錄「存檔並通知」後正在回簽中，無法編輯；如需修改請先按「撤回」解除鎖定');
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

    /* 出席人員後端把關（鐵律8：前端擋一次，後端用同一份規則再擋一次，不留只擋 UI 的漏洞）
       只驗「這次新加進來的人」——已經在這場會議名單上的人不重驗，否則舊紀錄事後被補了一張請假單，
       之後連改個地點都會存不進去。判定一律呼叫共用庫，不在這裡自己寫一套。 */
    $newUids = [];
    foreach ($attendees as $a) if ((int)($a['user_id'] ?? 0) > 0) $newUids[] = (int)$a['user_id'];
    $newUids = array_values(array_unique($newUids));
    if ($id > 0 && $newUids) {
        $q = $db->prepare("SELECT user_id FROM meeting_attendee WHERE meeting_id=?");
        $q->execute([$id]);
        $already = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        $newUids = array_values(array_diff($newUids, $already));
    }
    if ($newUids) {
        // ①會議日期當天不在職（還沒入職／已離職）者不可加入
        $okRows = eg_people_list_asof($db, ['user_ids'=>$newUids, 'states'=>[1,2,3]], $date);
        $okIds  = array_map('intval', array_column($okRows, 'id'));
        $bad    = array_values(array_diff($newUids, $okIds));
        if ($bad) {
            $nm = [];
            foreach ($attendees as $a) if (in_array((int)($a['user_id'] ?? 0), $bad, true)) $nm[] = (string)($a['user_name'] ?? ('#'.$a['user_id']));
            jerr('下列人員在 ' . $date . ' 當天不在職（尚未入職或已離職），不可列入出席人員：' . implode('、', array_unique($nm)));
        }
        // ②與會議時段重疊且屬「不可勾選」的行程（目前＝請假）者不可加入
        $sc = eg_psched_for_users($db, $newUids, $date, (string)$start, (string)$end, ['exclude_meeting_id'=>$id]);
        $blk = [];
        foreach ($okRows as $r) {
            $items2 = $sc[(int)$r['id']] ?? [];
            if (!eg_psched_blocked($items2)) continue;
            foreach ($items2 as $it) if (!empty($it['blocks'])) { $blk[] = $r['user_cname'] . '（' . $it['text'] . '）'; break; }
        }
        if ($blk) jerr('下列人員在會議時段內請假，不可列入出席人員：' . implode('、', $blk));
    }

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

        // 會議項目：整批取代（用 item_id 對應保留 gm_comment；沒有 item_id 的視為新增）。
        // item_id 每次存檔都變(delete+insert新auto_increment)，所以確認簽名(meeting_item_confirm)跟通知(live_event.ref_id)
        // 都要跟著把 ref 改指到新的一列，不然任何一次存檔(即使只是改別的欄位)都會讓還在生效中的通知變成指向不存在的
        // item_id，對方點進去回覆會被 meeting_item_confirm_via_notify 靜默丟棄(查無此項目)而不會有任何錯誤提示。
        // 2026-08-10使用者明確要求：內容或負責部門/指定人員有異動時，該項目「舊的確認簽名視為失效」要清掉重新確認
        // (不影響其他沒改動的項目、也不影響出席簽到)；沒異動的項目才照舊把確認紀錄與通知一起搬到新 item_id。
        $oldItems = [];
        $iq = $db->prepare("SELECT item_id, gm_comment, content, owner_depts, owner_users FROM meeting_item WHERE meeting_id=?");
        $iq->execute([$id]);
        foreach ($iq->fetchAll(PDO::FETCH_ASSOC) as $o) $oldItems[(int)$o['item_id']] = $o;
        $db->prepare("DELETE FROM meeting_item WHERE meeting_id=?")->execute([$id]);
        $insI = $db->prepare("INSERT INTO meeting_item (meeting_id, kind, sort_order, content, due_date, owner_depts, owner_dept_names, owner_users, remark, gm_comment)
                              VALUES (?,?,?,?,?,?,?,?,?,?)");
        $n = 0;
        $keptOldIds = [];
        foreach ($items as $it) {
            $content = trim((string)($it['content'] ?? ''));
            if ($content === '') continue;
            $kind = in_array($it['kind'] ?? '', ['directive','announce'], true) ? $it['kind'] : 'general';
            $due = trim((string)($it['due_date'] ?? '')) ?: null;
            if ($due && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) $due = null;
            // 負責人二擇一(2026-08-05使用者明確要求)：owner_users(指定人員)有值時完全取代 owner_depts(部門自動判定)
            $ownerUserIds = array_values(array_filter(array_map('intval', (array)($it['owner_users'] ?? []))));
            $ownerIds = $ownerUserIds ? [] : array_values(array_filter(array_map('intval', (array)($it['owner_depts'] ?? []))));
            $ownerDeptsStr = $ownerIds ? implode(',', $ownerIds) : null;
            $ownerUsersStr = $ownerUserIds ? implode(',', $ownerUserIds) : null;
            $ownerNames = trim((string)($it['owner_dept_names'] ?? '')) ?: null;
            $remark = trim((string)($it['remark'] ?? '')) ?: null;
            $prevId = (int)($it['item_id'] ?? 0);
            $prev = $oldItems[$prevId] ?? null;
            $insI->execute([$id, $kind, $n, $content, $due, $ownerDeptsStr, $ownerNames, $ownerUsersStr, $remark, $prev['gm_comment'] ?? null]);
            if ($prev) {
                $keptOldIds[] = $prevId;
                $newItemId = (int)$db->lastInsertId();
                $changed = (string)$prev['content'] !== $content
                        || (string)($prev['owner_depts'] ?? '') !== (string)($ownerDeptsStr ?? '')
                        || (string)($prev['owner_users'] ?? '') !== (string)($ownerUsersStr ?? '');
                if ($changed) {
                    $db->prepare("DELETE FROM meeting_item_confirm WHERE item_id=?")->execute([$prevId]);
                    meeting_close_single_item_notice($db, $prevId); // 內容已變，舊通知內容跟著失真，關閉它；下次存檔並通知會用新內容重發
                } else {
                    $db->prepare("UPDATE meeting_item_confirm SET item_id=? WHERE item_id=?")->execute([$newItemId, $prevId]);
                    $db->prepare("UPDATE live_event SET ref_id=? WHERE ref_type='MEETING_ITEM_CONFIRM' AND ref_id=?")->execute([$newItemId, $prevId]);
                }
            }
            $n++;
        }
        $goneIds = array_diff(array_keys($oldItems), $keptOldIds);
        if ($goneIds) {
            $in = implode(',', array_map('intval', $goneIds));
            $db->exec("DELETE FROM meeting_item_confirm WHERE item_id IN ($in)");
            foreach ($goneIds as $gid) meeting_close_single_item_notice($db, (int)$gid); // 項目被刪除，關閉其還在生效中的舊通知
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
    // 存檔本身也可能讓最後一個條件成立（最常見的是這次才指定主席）；交易外才做，避免自動送出的通知
    // 被存檔失敗的 rollback 一起回捲（通知已經推播出去就收不回來了）
    jout(['meeting_id'=>$id, 'auto_submitted'=>meeting_try_auto_submit($db, $id)]);
}

case 'delete': {
    $id = (int)($_POST['meeting_id'] ?? 0);
    $m = meeting_load($db, $id);
    if ((int)$m['recorder_user_id'] !== $uid && !$perms['canAdmin']) jerr('僅記錄人本人或管理員可刪除', 403);
    if ($m['status'] !== 'draft' && !$perms['canAdmin']) jerr('已送出的會議記錄僅管理員可刪除');
    meeting_close_item_notices($db, $id); // 項目資料列即將刪除，先關閉還在生效中的回簽通知(要在刪 meeting_item 之前查)
    // 2026-08-11修正：刪除只清了 meeting_attendee/meeting_item/meeting_record 三張表，
    // meeting_item_confirm(項目確認簽名)、approval_record(主席/總經理簽核紀錄)、meeting_attach(附件) 都沒清，
    // 會留下指向不存在 meeting_id 的孤兒資料列。附件另外要清實體檔案，不能只刪DB列。
    $atRows = $db->prepare("SELECT file_name FROM meeting_attach WHERE meeting_id=?");
    $atRows->execute([$id]);
    $attachFiles = $atRows->fetchAll(PDO::FETCH_COLUMN);
    try {
        $db->beginTransaction();
        $db->exec("DELETE mic FROM meeting_item_confirm mic JOIN meeting_item mi ON mi.item_id=mic.item_id WHERE mi.meeting_id=" . (int)$id);
        $db->prepare("DELETE FROM approval_record WHERE module='meeting' AND entity_id=?")->execute([$id]);
        $db->prepare("DELETE FROM meeting_attach WHERE meeting_id=?")->execute([$id]);
        $db->prepare("DELETE FROM meeting_attendee WHERE meeting_id=?")->execute([$id]);
        $db->prepare("DELETE FROM meeting_item WHERE meeting_id=?")->execute([$id]);
        $db->prepare("DELETE FROM meeting_record WHERE meeting_id=?")->execute([$id]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('刪除失敗：'.$e->getMessage(), 500); }
    meeting_close_notice($db, $id);
    $dir = meeting_attach_dir($db);
    foreach ($attachFiles as $fn) { $fp = $dir . basename((string)$fn); if (is_file($fp)) @unlink($fp); }
    jout([]);
}

/* 部門人員（出席人員挑選用；比照鐵則走 eg_people_list，不自己拼人員 SQL）。
   2026-08-06使用者明確要求：不列超級管理員(states排除99)，且不應選得到會議當天時段有請假的人
   (有帶 meeting_date 才過濾，前端在使用者尚未選日期前不應呼叫)。 */
case 'people': {
    $deptId = (int)($_GET['dept_id'] ?? 0);
    if ($deptId <= 0) jout(['people'=>[]]);
    $mDate = trim((string)($_GET['meeting_date'] ?? ''));
    // 2026-08-26 使用者明確要求：人員選單一律抓「會議日期當日」的在職狀態
    // （該日之後才入職、該日之前已離職者都不可出現；當日還在職、之後才離職的要出現，補舊紀錄才選得到人）
    $opt  = ['dept_ids'=>[$deptId], 'states'=>[1,2,3]];
    $rows = ($mDate !== '') ? eg_people_list_asof($db, $opt, $mDate) : eg_people_list($db, $opt);
    jout(['people'=>meeting_people_with_schedule($db, $rows, $mDate,
        (string)($_GET['start_time'] ?? ''), (string)($_GET['end_time'] ?? ''),
        (int)($_GET['meeting_id'] ?? 0))]);
}

/* 全員人員清單（負責人「指定人員」模式搜尋選擇器用；2026-08-05使用者明確要求）：比照鐵則走 eg_people_list，不自己拼SQL。
   這裡是指派任務負責人用，不是出席人員選取，不做請假時段過濾；但超級管理員一樣不列入選單(states排除99)。 */
case 'people_all': {
    // 2026-09-01 使用者回報：這份清單原本每人只印一個部門，而共用庫挑的是「職級最高」那筆＝常常是兼任的那個，
    // 於是主職「技術部 工程師」的人在清單上只看得到「生管組」，看起來像是系統只認得兼任、原職位不見了。
    // 改成把該員**所有**職務（主職＋兼任）一起帶給前端：主職在前不加註記、兼任的加「（兼任）」，
    // 例：何沐桐 → 「技術部 工程師／生管組 組長（兼任）」。人員清單一律走共用庫，不自己拼 SQL（鐵則見 ai-rules/08 第五節）。
    // 這裡是指派任務負責人，選的是「人」不是「職務」，所以一人仍只有一列（owner_users 存的就是 user_id），
    // 只是把職務全列出來讓人認得出是誰。
    $rows  = eg_people_list($db, ['states'=>[1,2,3], 'prefer_main'=>true]);
    $posts = eg_people_posts($db, ['states'=>[1,2,3], 'user_ids'=>array_column($rows, 'id')]);
    $byUid = [];
    foreach ($posts as $p) $byUid[(int)$p['id']][] = $p;
    $fmt = function (array $ps): array {
        // 主職優先，其次職級高的在前（同一人的多筆職務顯示順序要固定，不能隨 SQL 回傳順序跳動）
        usort($ps, fn($a, $b) => [-(int)$a['is_main'], (int)$a['position_sort'], (int)$a['dept_sort']]
                             <=> [-(int)$b['is_main'], (int)$b['position_sort'], (int)$b['dept_sort']]);
        return array_map(fn($p) => [
            'dept_id'       => $p['dept_id'],
            'dept_name'     => (string)$p['dept_name'],
            'position_name' => (string)$p['position_name'],
            'is_main'       => (int)$p['is_main'],
            // 排序值一起帶出去：前端「先選部門再選人」時要依**該部門那筆職務**排序（鐵則5 依部門/職稱
            // sort_order 而非姓名筆畫）。少了這兩個值，篩生管組時會沿用當事人主職的排序，
            // 於是兼任組長的人被排在組員後面，看起來像清單壞掉。
            'dept_sort'     => (int)$p['dept_sort'],
            'position_sort' => (int)$p['position_sort'],
            'label'         => trim($p['dept_name'] . ' ' . $p['position_name']) . ($p['is_main'] ? '' : '（兼任）'),
        ], $ps);
    };
    $out = [];
    foreach ($rows as $r) {
        $ps = $fmt($byUid[(int)$r['id']] ?? []);
        $out[] = ['id'=>$r['id'], 'user_cname'=>$r['user_cname'],
                  'position_name'=>$r['position_name'] ?? '', 'dept_name'=>$r['dept_name'] ?? '',
                  'posts'=>$ps,
                  // 沒掛任何職務的人（極少數）退回共用庫挑出來的那筆，不要顯示成空白
                  'posts_text'=>$ps ? implode('／', array_column($ps, 'label'))
                                    : trim((string)($r['dept_name'] ?? '') . ' ' . (string)($r['position_name'] ?? ''))];
    }
    jout(['people'=>$out]);
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
    jout(['att_id'=>$attId, 'auto_submitted'=>meeting_try_auto_submit($db, $id)]);
}

/* 送出：draft/rejected → submitted，建立主席簽核並通知（含代理解析） */
case 'submit': {
    $id = (int)($_POST['meeting_id'] ?? 0);
    $m = meeting_load($db, $id);
    if ((int)$m['recorder_user_id'] !== $uid && !$perms['canAdmin']) jerr('僅記錄人本人或管理員可送出', 403);
    // 送出前置檢查（①已指定主席②出席人員全部簽到③負責部門/指定人員全部確認回簽）與實際送出動作，
    // 都收斂在 meeting_lib 的 meeting_submit_blocker()／meeting_submit_to_chair()，
    // 與「回簽完成自動送出」共用同一份規則，兩邊不會走鐘（2026-08-26改）。
    $blk = meeting_submit_blocker($db, $m);
    if ($blk !== '') jerr($blk);
    $err = meeting_submit_to_chair($db, $m, $uid, $uname);
    if ($err !== '') jerr($err);
    jout(['status'=>'submitted']);
}

/* 存檔並通知(2026-08-10新增，使用者明確要求)：出席人員全部簽到後，若負責部門/指定人員尚未確認回簽，
   一律先「存檔並通知」而不是直接送主席簽核——通知該部門本次所有出席人員＋部門主管(或指定人員本人)，
   任一人回覆即完成該項目；全部項目都確認後才能真正 action=submit 送交主席簽核。
   不會重複灌通知：已確認完成的項目跳過；已經有一則還在生效中的通知（尚未關閉）也跳過，避免每點一次就轟炸一次。 */
case 'notify_pending_items': {
    $id = (int)($_POST['meeting_id'] ?? 0);
    $m = meeting_load($db, $id);
    if ((int)$m['recorder_user_id'] !== $uid && !$perms['canAdmin']) jerr('僅記錄人本人或管理員可通知', 403);
    if (!in_array($m['status'], ['draft','rejected'], true)) jerr('此會議記錄已送出，無法再通知');
    $unsigned = $db->prepare("SELECT COUNT(*) FROM meeting_attendee WHERE meeting_id=? AND signed=0");
    $unsigned->execute([$id]);
    if ((int)$unsigned->fetchColumn() > 0) jerr('尚有出席人員未完成現場簽到，請先完成全部出席人員簽到');

    $notified = 0;
    $activeStmt = $db->prepare("SELECT 1 FROM live_event WHERE ref_type='MEETING_ITEM_CONFIRM' AND ref_id=?
                                 AND (enddate IS NULL OR enddate>=CURDATE()) LIMIT 1");
    foreach (meeting_items($db, $id) as $it) {
        if (meeting_item_is_confirmed($db, $it)) continue; // 已全部確認完成，不必再通知
        $activeStmt->execute([(int)$it['item_id']]);
        if ($activeStmt->fetchColumn()) continue; // 已有一則還在等回覆的通知，不重複發

        $targets = meeting_item_pending_notify_targets($db, $id, $it);
        if (!$targets) continue;
        $notified++;
        // 標題/內文一律走共用的 meeting_item_notice_text()，與「回簽中調整負責人」補發的通知用同一份措辭
        $tx = meeting_item_notice_text($db, $m, $it, $targets);
        meeting_notify_item_owners($db, (int)$it['item_id'], $targets, $tx['title'], $tx['content'], $uid);
    }
    jout(['notified_items'=>$notified]);
}

/* 回簽中調整負責人(2026-09-01 使用者明確要求)：「存檔並通知」之後整張記錄鎖定不可編輯，但常有
   「這一項其實不歸他管／還要再找一個部門一起確認」的情況，原本只能整張撤回再重發一次通知，
   其他已經回覆好的人會被連帶重來。這支只動**一個項目**的負責部門或指定人員，其餘內容一個字都不碰：
     ・只能加/減，**不能換模式**（部門↔指定人員互換等於整項重定義，那種請走撤回改草稿）
     ・**已經回簽的人/部門一律不可移除**（移除了他的簽名就會變成孤兒，畫面上的蓋章也會消失）
     ・新加的人會被併進該項目**原本那則**通知的收件人（不另發第二則，見 meeting_item_notice_sync）
     ・移除後若剩下的都已回簽，該項目的通知自動關閉；整張都完成時 meeting_try_auto_submit 會直接送主席
   權限與可編輯階段比照 save（記錄人本人或管理員、draft/rejected），差別只在**不受回簽中鎖定限制**。 */
case 'owner_adjust': {
    if (!$perms['canEdit']) jerr('無編輯權限', 403);
    $id     = (int)($_POST['meeting_id'] ?? 0);
    $itemId = (int)($_POST['item_id'] ?? 0);
    $mode   = (string)($_POST['mode'] ?? '');
    $m = meeting_load($db, $id);
    if ((int)$m['recorder_user_id'] !== $uid && !$perms['canAdmin']) jerr('僅記錄人本人或管理員可調整負責人', 403);
    if (!in_array($m['status'], ['draft','rejected'], true)) jerr('此會議記錄已送出，無法調整負責人（如需修改請先請主席/總經理退回）');
    if (!in_array($mode, ['dept','user'], true)) jerr('模式參數不正確');

    $st = $db->prepare("SELECT item_id, content, owner_depts, owner_users FROM meeting_item WHERE item_id=? AND meeting_id=?");
    $st->execute([$itemId, $id]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item) jerr('找不到此會議項目');

    $curUsers = array_values(array_filter(array_map('intval', explode(',', (string)$item['owner_users']))));
    $curDepts = $curUsers ? [] : array_values(array_filter(array_map('intval', explode(',', (string)$item['owner_depts']))));
    // 「空陣列」與「沒給」在後端是兩回事（ai-rules 記過的漏法）：這裡 ids 允許為空＝把負責人全部清掉，
    // 但只有在沒有任何人回簽過的情況下才可能通過下面的移除檢查。
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))))));
    if (count($ids) > 50) jerr('負責人數量過多（上限 50）');

    // 模式不可切換：項目原本就有負責人時，只能沿用原本的那一種
    if (($curUsers && $mode !== 'user') || ($curDepts && $mode !== 'dept')) {
        jerr('回簽中不可切換「負責部門／指定人員」模式，只能在原本的模式下增減；要改模式請先按「撤回」解除鎖定');
    }

    // 已回簽者不可移除（指定人員比 user_id、部門比 dept_id，與 meeting_item_is_confirmed 同一套判定鍵）
    $cq = $db->prepare("SELECT user_id, user_name, dept_id, dept_name FROM meeting_item_confirm WHERE item_id=?");
    $cq->execute([$itemId]);
    $confirmRows = $cq->fetchAll(PDO::FETCH_ASSOC);
    $removed = array_values(array_diff($mode === 'user' ? $curUsers : $curDepts, $ids));
    $blocked = [];
    foreach ($confirmRows as $cr) {
        if ($mode === 'user') {
            if (in_array((int)$cr['user_id'], $removed, true)) $blocked[] = (string)($cr['user_name'] ?: $cr['user_id']);
        } elseif ($cr['dept_id'] !== null && in_array((int)$cr['dept_id'], $removed, true)) {
            $blocked[] = (string)($cr['dept_name'] ?: $cr['dept_id']);
        }
    }
    if ($blocked) jerr('下列' . ($mode === 'user' ? '人員' : '部門') . '已經回簽完成，不可移除：' . implode('、', array_unique($blocked))
                       . '（已完成的確認簽名會跟著失效，如確實要改請按「撤回」解除鎖定後修改）');

    // 新加入的對象要真的存在且可用（前端只送 id，後端不能照單全收＝鐵律8）
    $added = array_values(array_diff($ids, $mode === 'user' ? $curUsers : $curDepts));
    if ($added) {
        $in = implode(',', array_fill(0, count($added), '?'));
        if ($mode === 'user') {
            // 離職(0)與特殊帳號(90/99)不可指派——指派了也永遠不會有人回簽，整張記錄會卡在回簽中送不出去
            $vq = $db->prepare("SELECT id FROM `user` WHERE id IN ($in) AND state NOT IN (0,90,99)");
        } else {
            $vq = $db->prepare("SELECT id FROM department WHERE id IN ($in)");
        }
        $vq->execute($added);
        $okIds = array_map('intval', $vq->fetchAll(PDO::FETCH_COLUMN));
        $badIds = array_values(array_diff($added, $okIds));
        if ($badIds) jerr('下列' . ($mode === 'user' ? '人員（可能已離職）' : '部門') . '不存在或不可指派：' . implode('、', $badIds));
    }

    try {
        $db->beginTransaction();
        $db->prepare("UPDATE meeting_item SET owner_users=?, owner_depts=?, owner_dept_names=? WHERE item_id=?")
           ->execute([
               $mode === 'user' ? ($ids ? implode(',', $ids) : null) : null,
               $mode === 'dept' ? ($ids ? implode(',', $ids) : null) : null,
               ($mode === 'dept' && $ids)
                   ? implode(',', $db->query("SELECT name FROM department WHERE id IN (" . implode(',', $ids) . ")")->fetchAll(PDO::FETCH_COLUMN))
                   : null,
               $itemId,
           ]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('調整失敗：'.$e->getMessage(), 500); }

    // 通知收件人的增刪與可能的自動送簽核都放在交易外：推播一旦送出就收不回來，不可被 rollback 連帶回捲
    $sync = meeting_item_notice_sync($db, $m, $itemId, $uid);
    $st->execute([$itemId, $id]);                      // 重讀調整後的這一列再判定，不要拿前面的舊值推算
    $after = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    jout(['added'=>count($added), 'removed'=>count($removed),
          'notified'=>count($sync['added']), 'new_notice'=>$sync['new_notice'],
          'item_done'=>$after ? meeting_item_is_confirmed($db, $after) : true,
          'auto_submitted'=>meeting_try_auto_submit($db, $id)]);
}

/* 撤回已送出但「尚未任何人簽核」的會議記錄(2026-08-05 使用者明確要求)：
   限 approval_status==='submitted'(僅主席待簽、連主席都還沒簽)才可撤回；一旦有人簽過(chair_done/done/rejected)一律不可撤回，
   避免破壞已存在的簽核歷程。刪除待簽核的 chair 簽核紀錄、關閉主席簽核通知與本次送出所發的項目確認回簽通知，狀態退回 draft
   供記錄人修改(如負責部門/主席人選)後重新送出。權限比照編輯/刪除：記錄人本人或管理員。 */
/* 撤回(2026-08-10使用者明確要求擴充)：兩種情況都可撤回——①已送出「待主席簽章」且尚未有人簽核 ②「回簽中」
   (存檔並通知後，負責部門/指定人員尚未全部回覆確認)。兩者都只是關閉相關通知，不動已經回覆/簽到的既有紀錄
   (使用者明確要求：撤回本身不清舊簽章，只在後續編輯時若該項目內容/負責人真的有異動才會清該項目的舊確認，見save動作)。 */
case 'withdraw': {
    $id = (int)($_POST['meeting_id'] ?? 0);
    $m = meeting_load($db, $id);
    if ((int)$m['recorder_user_id'] !== $uid && !$perms['canAdmin']) jerr('僅記錄人本人或管理員可撤回', 403);
    $ap = meeting_approval_status($db, $id);
    $notifying = in_array($ap['status'], ['draft','rejected'], true) && meeting_has_active_item_notices($db, $id);
    if ($ap['status'] === 'submitted') {
        if ($ap['chair']) $db->prepare("DELETE FROM approval_record WHERE id=?")->execute([(int)$ap['chair']['id']]);
        meeting_close_notice($db, $id);
        meeting_close_item_notices($db, $id);
        $db->prepare("UPDATE meeting_record SET status='draft', updated_at=NOW() WHERE meeting_id=?")->execute([$id]);
        jout(['status'=>'draft']);
    }
    if ($notifying) {
        meeting_close_item_notices($db, $id);
        $db->prepare("UPDATE meeting_record SET updated_at=NOW() WHERE meeting_id=?")->execute([$id]); // 狀態本來就是draft，只是解除回簽中鎖定
        jout(['status'=>'draft']);
    }
    jerr('僅「待主席簽章」或「回簽中」狀態可撤回，此記錄目前狀態不允許撤回');
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

/* 負責人項目現場確認簽名(2026-08-05改版，使用者明確要求)：部門模式每個負責部門各一位簽名槽(該部門本次有出席的
   主要角色主管優先，沒有才由兼任該部門的主管，再沒有才由職稱排序最高者代簽)；指定人員模式則被指名的人各一格，
   限「本次出席人員」用本人密碼確認(比照簽到表的密碼驗證，共用裝置也知道究竟是誰簽的)；
   只有被算出/指名為該格的那個人才能簽，其餘人不可代簽(避免搶簽同一格)。
   未出席者無法在現場輸入密碼，一律改走通知系統回簽(送出會議記錄時自動發送，見 submit 與 _eventRespond.php 的 MEETING_ITEM_CONFIRM 掛勾)。 */
case 'item_confirm': {
    $itemId = (int)($_POST['item_id'] ?? 0);
    $forUid = (int)($_POST['user_id'] ?? 0);
    $password = (string)($_POST['password'] ?? '');
    $st = $db->prepare("SELECT * FROM meeting_item WHERE item_id=?");
    $st->execute([$itemId]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item) jerr('找不到此項目');
    $req = meeting_item_required_signers_for($db, (int)$item['meeting_id'], $item);
    if (!$req) jerr('此項目未指派負責人，或負責人本次未出席');
    $signer = null;
    foreach ($req as $s) if ($s['user_id'] === $forUid) { $signer = $s; break; }
    if (!$signer) jerr('您不是此項目本次應簽名的人，如需更換簽署人請洽記錄人調整出席名單/負責人設定', 403);
    $already = $db->prepare("SELECT 1 FROM meeting_item_confirm WHERE item_id=? AND user_id=?");
    $already->execute([$itemId, $forUid]);
    if ($already->fetchColumn()) jerr('您已經簽過此項目');
    $v = meeting_verify_own_password($db, $forUid, $password);
    if (!$v['ok']) jerr($v['msg']);
    $db->prepare("INSERT IGNORE INTO meeting_item_confirm (item_id, user_id, user_name, dept_name, dept_id, confirmed_at) VALUES (?,?,?,?,?,NOW())")
       ->execute([$itemId, $forUid, $signer['user_name'], $signer['dept_name'], $signer['dept_id']]);
    // 2026-08-26使用者實測回報：現場簽名完成後也要關掉當初「存檔並通知」發出的那則回簽通知，
    // 否則同部門其他被通知的人會一直收到「需要回簽」，整筆記錄也會永遠停在「回簽中」送不出簽核。
    meeting_close_item_notice_if_done($db, $itemId);
    // 這一格可能就是最後一項回簽，全部到齊直接送主席簽核（使用者要求：不必再手動按一次）
    jout(['auto_submitted'=>meeting_try_auto_submit($db, (int)$item['meeting_id'])]);
}

/* 出貨目標達成率：資料新鮮度檢查（GET，供插入前的提示） */
case 'kpi_check': {
    jout(meeting_kpi_freshness($db, date('Y-m-d')));
}

/* 插入本月出貨目標達成率快照：先驗新鮮度，未達標直接擋（提示還差幾天） */
/* 插入出貨目標達成率(2026-08-06使用者明確要求擴充)：draft/rejected 一律可插；已完成核准(done)後也可插，但：
   一般使用者插入後視同內容變動，清掉舊的主席/總經理簽核紀錄退回草稿，需重新送出取得簽章；
   超級管理員插入後維持done狀態不動簽核(視同「已核准」直接生效，不必重跑簽核流程)。
   簽核中(submitted/chair_done)一律不可插，避免跟正在進行的簽核動作衝突。 */
case 'kpi_insert': {
    if (empty($perms['canKpiInsert'])) jerr('您沒有插入出貨目標達成率的權限，請洽管理員於「角色設定」開放', 403);
    $id = (int)($_POST['meeting_id'] ?? 0);
    $m = meeting_load($db, $id);
    if ((int)$m['recorder_user_id'] !== $uid && !$perms['canAdmin']) jerr('僅記錄人本人或管理員可插入', 403);
    $ap = meeting_approval_status($db, $id);
    $isDone = $ap['status'] === 'done';
    if (!in_array($ap['status'], ['draft','rejected'], true) && !$isDone) {
        jerr('此會議記錄簽核進行中，無法插入；僅草稿/退回，或已完成核准後可插入');
    }
    $isSuperadmin = meeting_is_superadmin($db, $uid);
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
    $resetNote = null;
    $newStatus = $ap['status'];
    if ($isDone && !$isSuperadmin) {
        if ($ap['chair']) $db->prepare("DELETE FROM approval_record WHERE id=?")->execute([(int)$ap['chair']['id']]);
        if ($ap['gm']) $db->prepare("DELETE FROM approval_record WHERE id=?")->execute([(int)$ap['gm']['id']]);
        meeting_close_notice($db, $id);
        $db->prepare("UPDATE meeting_record SET status='draft', updated_at=NOW() WHERE meeting_id=?")->execute([$id]);
        $resetNote = '此會議記錄先前已完成簽核，插入新數據後已重置為草稿狀態，請重新送出以取得主席／總經理簽章。';
        $newStatus = 'draft';
    }
    if ($isDone) {
        try {
            $db->prepare("INSERT INTO page_change_log (page_name, summary, detail, changed_at, created_by)
                          VALUES ('views/ADM/meeting_record.php', ?, ?, NOW(), ?)")
               ->execute([$isSuperadmin ? '超級管理員於結案後插入出貨目標達成率(維持已核准)' : '已核准會議記錄插入出貨目標達成率(重置為草稿重新送審)',
                          "meeting_id={$id}", $uname]);
        } catch (Throwable $e) {}
    }
    jout(['snapshot'=>$snap, 'status'=>$newStatus, 'reset_note'=>$resetNote]);
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
/* 回簽全部完成時要不要自動送出主席簽核（2026-08-26使用者拍板：可開關、預設開啟） */
case 'auto_submit_save': {
    if (!$perms['canAdmin']) jerr('無設定權限（限模組管理員）', 403);
    meeting_setting_save($db, 'meeting_auto_submit', !empty($_POST['enabled']) && $_POST['enabled'] !== '0' ? '1' : '0');
    jout(['auto_submit'=>meeting_auto_submit_enabled($db)]);
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
/* 挑人時要提示哪些「當天行程」來源（2026-08-26 使用者明確要求做成設定、不可寫死）。
   這是全站共用設定（system_settings.person_schedule_sources），日後教育訓練／公出單挑人也吃同一份，
   設定入口暫掛在本模組的模組設定；讀取不卡管理員（一般人挑人也要看得到提示），只有寫入限管理員。 */
case 'sched_setting_get': {
    jout(['sources'=>eg_psched_sources(), 'on'=>eg_psched_setting_get($db)]);
}
case 'sched_setting_save': {
    if (!$perms['canAdmin']) jerr('無設定權限（限模組管理員）', 403);
    $on = json_decode((string)($_POST['on'] ?? '{}'), true);
    if (!is_array($on)) jerr('設定格式不正確');
    jout(['on'=>eg_psched_setting_save($db, $on)]);
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

/* ===== 超級管理員：補齊/修改簽章日期(2026-08-05使用者明確要求) =====
   scope: attendee/item/chair/gm/all；target_id 個別指定(att_id 或 item_id)，留空=該範圍全部(批次)。
   使用者已確認：尚未簽核的部分(未簽到/未確認/主席總經理未核准)一併視同補簽，不是只改已簽過的日期；
   主席/總經理若該階段從未送出過(approval_record查無紀錄)也一併自動送審＋自動核准(見 $ensureAndApprove，
   總經理階段會先遞迴確保主席已核准)，不會卡在「查無紀錄不處理」。 */
case 'admin_backfill': {
    if (!meeting_is_superadmin($db, $uid)) jerr('僅超級管理員可使用此功能', 403);
    $v = meeting_verify_superadmin_password($db, (string)($_POST['password'] ?? ''));
    if (!$v['ok']) jerr($v['msg']);
    $id = (int)($_POST['meeting_id'] ?? 0);
    $m = meeting_load($db, $id);
    $date = trim((string)($_POST['date'] ?? '')) ?: (string)$m['meeting_date'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) jerr('日期格式不正確');
    $scope = (string)($_POST['scope'] ?? 'all');
    $targetId = (int)($_POST['target_id'] ?? 0);
    if (!in_array($scope, ['attendee','item','chair','gm','all'], true)) jerr('範圍參數不正確');

    try {
        $db->beginTransaction();

        if ($scope === 'attendee' || $scope === 'all') {
            $sql = "UPDATE meeting_attendee SET signed=1, signed_at=CONCAT(?,' ',TIME(COALESCE(signed_at,NOW()))) WHERE meeting_id=?";
            $params = [$date, $id];
            if ($scope === 'attendee' && $targetId) { $sql .= " AND att_id=?"; $params[] = $targetId; }
            $db->prepare($sql)->execute($params);
        }

        if ($scope === 'item' || $scope === 'all') {
            if ($scope === 'item' && $targetId) {
                $iq = $db->prepare("SELECT * FROM meeting_item WHERE meeting_id=? AND item_id=?");
                $iq->execute([$id, $targetId]);
            } else {
                $iq = $db->prepare("SELECT * FROM meeting_item WHERE meeting_id=?");
                $iq->execute([$id]);
            }
            // 每格簽名槽(部門模式代表制或指定人員模式，見 meeting_item_required_signers_for)全部視同已簽並套用$date
            $upsertC = $db->prepare("INSERT INTO meeting_item_confirm (item_id, user_id, user_name, dept_name, dept_id, confirmed_at)
                                      VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE confirmed_at=VALUES(confirmed_at)");
            $dt = $date . ' ' . date('H:i:s');
            foreach ($iq->fetchAll(PDO::FETCH_ASSOC) as $it) {
                foreach (meeting_item_required_signers_for($db, $id, $it) as $signer) {
                    $upsertC->execute([(int)$it['item_id'], $signer['user_id'], $signer['user_name'], $signer['dept_name'], $signer['dept_id'], $dt]);
                }
                meeting_close_item_notice_if_done($db, (int)$it['item_id']); // 補齊後同樣要關掉還開著的回簽通知
            }
        }

        // 主席／總經理若該階段從未送出過，也一併自動送審＋自動核准(2026-08-05使用者明確要求「一鍵補齊全部」要含未送審的)；
        // 總經理一定要主席先核准過才能送出，所以 gm 階段查無紀錄時會先遞迴補上主席那關(同一個 $date)。
        $ensureAndApprove = function(string $lvl) use ($db, $id, $m, $date, &$ensureAndApprove) {
            $rec = eg_approval_latest($db, 'meeting', $id, $lvl);
            if (!$rec) {
                if ($lvl === 'chair') {
                    if (!$m['chair_user_id']) jerr('尚未指定主席，無法補簽主席簽核');
                    eg_approval_submit($db, 'meeting', $id, 'chair', (int)$m['recorder_user_id'], (string)$m['recorder_name']);
                } else {
                    $ensureAndApprove('chair');
                    if (!meeting_gm_signer_effective($db)) jerr('尚未設定「最高核准人員」，請先到組織角色綁定設定設定');
                    eg_approval_submit($db, 'meeting', $id, 'gm', (int)$m['recorder_user_id'], (string)$m['recorder_name']);
                }
                $rec = eg_approval_latest($db, 'meeting', $id, $lvl);
            }
            if ($rec['status'] === 'pending') {
                if ($lvl === 'chair') {
                    $signer = meeting_chair_signer_effective($db, (int)$m['chair_user_id'], (string)$m['chair_name']);
                    $r = eg_approval_decide($db, (int)$rec['id'], (int)$signer['id'], (string)$signer['name'], 'approved', null);
                    if ($r['success']) $db->prepare("UPDATE meeting_record SET status='chair_done', updated_at=NOW() WHERE meeting_id=?")->execute([$id]);
                } else {
                    $gm = meeting_gm_signer_effective($db);
                    if (!$gm) jerr('尚未設定「最高核准人員」，無法補簽總經理簽核');
                    $r = eg_approval_decide($db, (int)$rec['id'], (int)$gm['id'], (string)$gm['name'], 'approved', null);
                    if ($r['success']) $db->prepare("UPDATE meeting_record SET status='done', updated_at=NOW() WHERE meeting_id=?")->execute([$id]);
                }
                $rec = eg_approval_latest($db, 'meeting', $id, $lvl);
            }
            if ($rec && $rec['status'] === 'approved') {
                $db->prepare("UPDATE approval_record SET decided_at=CONCAT(?,' ',TIME(COALESCE(decided_at,NOW()))) WHERE id=?")
                   ->execute([$date, (int)$rec['id']]);
            }
        };
        foreach (['chair','gm'] as $lvl) {
            if ($scope !== $lvl && $scope !== 'all') continue;
            $ensureAndApprove($lvl);
        }

        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('補登失敗：'.$e->getMessage(), 500); }

    try {
        $db->prepare("INSERT INTO page_change_log (page_name, summary, detail, changed_at, created_by)
                      VALUES ('views/ADM/meeting_record.php', '超級管理員補齊簽章日期', ?, NOW(), ?)")
           ->execute(["meeting_id={$id}, scope={$scope}, target_id={$targetId}, date={$date}", $uname]);
    } catch (Throwable $e) {}

    $ap = meeting_approval_status($db, $id);
    jout(['status'=>$ap['status']]);
}

default: jerr('未知的操作：'.$action);
}
