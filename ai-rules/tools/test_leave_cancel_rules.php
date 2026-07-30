<?php
/**
 * 撤回／銷假的日期規則驗證（2026-07-30 使用者定案）：
 *   未來的假         → 可直接撤回
 *   請假期間內(含當日) → 需主管簽核（轉 cancel_pending，核准後才真撤除；駁回則回復原狀態）
 *   請假已結束        → 不開放自行撤回（只能找管理員），避免已休假卻無請假紀錄
 * 紀律：測試單以 __test__ 命名不發推播；清理只刪本腳本建立的列。
 */
mb_internal_encoding('UTF-8');
if (PHP_SAPI !== 'cli') exit('CLI only');
require_once 'C:/MAMP/htdocs/EGsystem/src/common/leave_lib.php';
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4",
              "EG-TS2024", "excell30367593", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = 0; $fail = 0; $created = [];
function ok($c, $n, $note = '') { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $n\n"; } else { $fail++; echo "  [FAIL] $n $note\n"; } }

$UID = 107092601;   // 邱冠宏（生產1廠組員，主管=黃文德）
$TYPE = 6;          // 公假 agent=0 need_approval=1

/** 直接以 SQL 造出指定日期的單（繞過補請假限制，測日期規則本身） */
function mkReq(PDO $db, int $uid, int $tid, string $s, string $e, string $status): int {
    $db->prepare("INSERT INTO leave_request (employee_id, leave_type_id, start_datetime, end_datetime,
                    reason, status, total_hours, total_days, attach_status, submit_time, last_update)
                  VALUES (?,?,?,?,'__test__撤回規則',?,8,1,'not_required',NOW(),NOW())")
       ->execute([$uid, $tid, $s, $e, $status]);
    return (int)$db->lastInsertId();
}
function cleanup(PDO $db, array $ids) {
    foreach ($ids as $id) {
        $ev = $db->query("SELECT evenement_id FROM leave_request WHERE id=$id")->fetchColumn();
        if ($ev) {
            $db->exec("DELETE FROM evenement_recipient_cache WHERE event_id=" . (int)$ev);
            $db->exec("DELETE FROM evenement_target WHERE event_id=" . (int)$ev);
            $db->exec("DELETE FROM evenement_actor WHERE event_id=" . (int)$ev);
            $db->exec("DELETE FROM evenement WHERE id=" . (int)$ev);
        }
        $db->exec("DELETE FROM leave_request_agent WHERE leave_request_id=$id");
        $db->exec("DELETE FROM leave_sign_record WHERE leave_request_id=$id");
        $db->exec("DELETE FROM leave_approval WHERE leave_request_id=$id");
        $db->exec("DELETE FROM leave_request WHERE id=$id");
    }
    foreach ($db->query("SELECT id FROM live_event WHERE ref_type IN ('LEAVE','LEAVE_APPROVAL')")->fetchAll(PDO::FETCH_COLUMN) as $e) {
        $db->exec("DELETE FROM live_event_response WHERE live_event_id=" . (int)$e);
        $db->exec("DELETE FROM live_event_target WHERE live_event_id=" . (int)$e);
        $db->exec("DELETE FROM live_event WHERE id=" . (int)$e);
    }
}

echo "== 模式判定 ==\n";
$future = ['start_datetime' => date('Y-m-d', strtotime('+10 day')) . ' 08:00:00',
           'end_datetime'   => date('Y-m-d', strtotime('+10 day')) . ' 17:00:00'];
$todayR = ['start_datetime' => date('Y-m-d') . ' 08:00:00', 'end_datetime' => date('Y-m-d') . ' 17:00:00'];
$spanR  = ['start_datetime' => date('Y-m-d', strtotime('-2 day')) . ' 08:00:00',
           'end_datetime'   => date('Y-m-d', strtotime('+2 day')) . ' 17:00:00'];
$pastR  = ['start_datetime' => date('Y-m-d', strtotime('-10 day')) . ' 08:00:00',
           'end_datetime'   => date('Y-m-d', strtotime('-10 day')) . ' 17:00:00'];
ok(eg_leave_cancel_mode($future) === 'direct',   '未來的假 → direct（可直接撤回）');
ok(eg_leave_cancel_mode($todayR) === 'approval', '請假當日 → approval（需主管簽核）');
ok(eg_leave_cancel_mode($spanR)  === 'approval', '請假期間中 → approval');
ok(eg_leave_cancel_mode($pastR)  === 'blocked',  '請假已結束 → blocked（不開放自行撤回）');
ok(eg_leave_cancel_mode($pastR, true) === 'direct', '管理者不受限，一律 direct');

echo "== 已結束的假：自行撤回被擋、管理員可撤 ==\n";
$idPast = mkReq($db, $UID, $TYPE, $pastR['start_datetime'], $pastR['end_datetime'], 'approved');
$created[] = $idPast;
$r = eg_leave_cancel($db, $idPast, $UID, '__test__想偷偷撤掉');
ok(empty($r['ok']) && ($r['mode'] ?? '') === 'blocked', '本人撤回被擋', $r['msg'] ?? '');
ok(strpos($r['msg'] ?? '', '已休假卻無請假紀錄') !== false, '訊息說明為什麼不給撤', $r['msg'] ?? '');
$st = $db->query("SELECT status FROM leave_request WHERE id=$idPast")->fetchColumn();
ok($st === 'approved', '單據狀態未被改動');
$r = eg_leave_cancel($db, $idPast, 1, '__test__管理員代撤', true);
ok(!empty($r['ok']), '管理員可撤', $r['msg'] ?? '');
ok($db->query("SELECT status FROM leave_request WHERE id=$idPast")->fetchColumn() === 'canceled', '已轉為 canceled');

echo "== 請假當日：撤回需主管簽核 ==\n";
$idToday = mkReq($db, $UID, $TYPE, $todayR['start_datetime'], $todayR['end_datetime'], 'approved');
$created[] = $idToday;
// 沒填原因要被擋
$r = eg_leave_cancel($db, $idToday, $UID, '');
ok(empty($r['ok']) && strpos($r['msg'], '必須填寫原因') !== false, '未填原因被擋', $r['msg'] ?? '');
$r = eg_leave_cancel($db, $idToday, $UID, '__test__當日臨時要上班');
ok(!empty($r['ok']) && ($r['mode'] ?? '') === 'approval', '轉為撤回待簽核', $r['msg'] ?? '');
$row = $db->query("SELECT status, cancel_reason, evenement_id FROM leave_request WHERE id=$idToday")->fetch(PDO::FETCH_ASSOC);
ok($row['status'] === 'cancel_pending', '狀態＝cancel_pending', (string)$row['status']);
ok($row['cancel_reason'] === '__test__當日臨時要上班', '撤回原因已記錄');
$ap = $db->query("SELECT * FROM leave_approval WHERE leave_request_id=$idToday AND approval_kind='cancel'")->fetchAll(PDO::FETCH_ASSOC);
ok(count($ap) === 1 && $ap[0]['status'] === 'pending', '產生 kind=cancel 的待簽列', json_encode($ap));
$tr = (int)$db->query("SELECT COUNT(*) FROM leave_sign_record WHERE leave_request_id=$idToday AND step_no=97")->fetchColumn();
ok($tr === 1, '軌跡記 step_no=97（提出撤回）');
$signMode = (int)$db->query("SELECT COUNT(*) FROM live_event e JOIN live_event_target t ON t.live_event_id=e.id
                             WHERE e.ref_type='LEAVE_APPROVAL' AND e.ref_id=$idToday AND t.mode='sign'")->fetchColumn();
ok($signMode >= 1, '撤回待簽通知為 mode=sign（簽完前不消失）', (string)$signMode);

echo "== 撤回待簽期間的保護 ==\n";
$rowc = $db->query("SELECT * FROM leave_request WHERE id=$idToday")->fetch(PDO::FETCH_ASSOC);
$ce = eg_leave_can_edit($db, $rowc, $UID);
ok(!$ce['ok'] && strpos($ce['reason'], '撤回申請正待主管簽核') !== false, '撤回待簽期間不可修改內容', $ce['reason']);
$busy = eg_leave_user_busy_in_range($db, $UID, $todayR['start_datetime'], $todayR['end_datetime']);
ok($busy !== null, 'cancel_pending 仍視為佔用時段（假還沒真的取消）');

echo "== 主管簽核撤回：駁回→回復、核准→真撤除 ==\n";
$signer = (int)$ap[0]['approver_id'];
$r = eg_leave_sign_cancel($db, $idToday, 109110202, 'approved', '');   // 非簽核人
ok(empty($r['ok']), '非撤回簽核人被拒', $r['msg'] ?? '');
$r = eg_leave_sign_cancel($db, $idToday, $signer, 'rejected', '__test__當天不同意撤');
ok(!empty($r['ok']), '駁回撤回成功', $r['msg'] ?? '');
ok($db->query("SELECT status FROM leave_request WHERE id=$idToday")->fetchColumn() === 'approved',
   '駁回後回復為 approved（請假仍有效）');
// 再提一次並核准
$r = eg_leave_cancel($db, $idToday, $UID, '__test__再次提出');
ok(!empty($r['ok']), '可再次提出撤回', $r['msg'] ?? '');
$ap2 = $db->query("SELECT * FROM leave_approval WHERE leave_request_id=$idToday AND approval_kind='cancel' AND status='pending'")->fetch(PDO::FETCH_ASSOC);
ok($ap2 !== false, '重新產生待簽列（舊的已清掉不重複）');
$r = eg_leave_sign_cancel($db, $idToday, (int)$ap2['approver_id'], 'approved', '__test__同意撤');
ok(!empty($r['ok']), '核准撤回成功', $r['msg'] ?? '');
$row2 = $db->query("SELECT status, evenement_id, canceled_by FROM leave_request WHERE id=$idToday")->fetch(PDO::FETCH_ASSOC);
ok($row2['status'] === 'canceled', '核准後狀態＝canceled');
ok($row2['evenement_id'] === null, '行事曆事件已撤除');

echo "== 未來的假：仍可直接撤回（原行為不變）==\n";
$idF = mkReq($db, $UID, $TYPE, $future['start_datetime'], $future['end_datetime'], 'approved');
$created[] = $idF;
$r = eg_leave_cancel($db, $idF, $UID, '__test__直接撤');
ok(!empty($r['ok']), '直接撤回成功', $r['msg'] ?? '');
ok($db->query("SELECT status FROM leave_request WHERE id=$idF")->fetchColumn() === 'canceled', '直接轉 canceled');

echo "== 請假簽核與撤回簽核不互相干擾 ==\n";
$lib = file_get_contents('C:/MAMP/htdocs/EGsystem/src/common/leave_lib.php');
ok(substr_count($lib, "approval_kind = 'leave'") >= 4, '請假簽核查詢都有限定 kind=leave',
   (string)substr_count($lib, "approval_kind = 'leave'"));
$api = file_get_contents('C:/MAMP/htdocs/EGsystem/src/store/Leave_API.php');
ok(strpos($api, "eg_leave_sign_cancel") !== false, 'API 可簽核撤回申請');
ok(strpos($api, "'cancel_mode' => eg_leave_cancel_mode") !== false, 'detail 回傳 cancel_mode 供前端顯示');

cleanup($db, $created);
$left = (int)$db->query("SELECT COUNT(*) FROM leave_request WHERE reason LIKE '\\_\\_test\\_\\_%'")->fetchColumn();
ok($left === 0, '測試單已清除', (string)$left);

printf("\n結果：PASS %d / FAIL %d\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
