<?php
// 驗證：無代理人設定者可送出 agent=1 的假別；有代理人設定者仍必填且須為候選之一。
mb_internal_encoding('UTF-8');
require_once 'C:/MAMP/htdocs/EGsystem/src/common/leave_lib.php';
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4",
              "EG-TS2024", "excell30367593", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = 0; $fail = 0; $created = [];
function ok($c, $n, $note = '') { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $n\n"; } else { $fail++; echo "  [FAIL] $n $note\n"; } }

$NOAGENT = 113061801;   // 黃文孝 生產3廠組員：實測無任何代理候選
$HASAGENT = 112020603;  // 高志宏：有 user_delegate（何沐桐）
$TYPE_AGENT = 2;        // 事假 agent=1

ok(empty(eg_person_delegate_candidates($db, $NOAGENT)), '前提：黃文孝無代理候選');
ok(!empty(eg_person_delegate_candidates($db, $HASAGENT)), '前提：高志宏有代理候選');

// 找未來工作日
$d = date('Y-m-d', strtotime('+90 day'));
for ($i = 0; $i < 20 && !eg_leave_is_workday($db, $d); $i++) $d = date('Y-m-d', strtotime($d . ' +1 day'));

echo "== 無代理人設定者：agent=1 的假別可直接送出 ==\n";
$r = eg_leave_submit($db, ['employee_id' => $NOAGENT, 'leave_type_id' => $TYPE_AGENT,
    'start_datetime' => "$d 09:00:00", 'end_datetime' => "$d 11:00:00",
    'reason' => '__test__現場人員無代理人', 'agent_user_id' => 0, 'upload_token' => '']);
ok(!empty($r['ok']), '送出成功（不再被「須指定職務代理人」擋下）', $r['msg'] ?? '');
if (!empty($r['id'])) {
    $created[] = (int)$r['id'];
    $row = $db->query("SELECT agent_user_id FROM leave_request WHERE id=" . (int)$r['id'])->fetch(PDO::FETCH_ASSOC);
    ok($row['agent_user_id'] === null, '代理人欄位存 NULL（視為不需代理）');
}

echo "== 有代理人設定者：仍必填 ==\n";
$r2 = eg_leave_submit($db, ['employee_id' => $HASAGENT, 'leave_type_id' => $TYPE_AGENT,
    'start_datetime' => "$d 09:00:00", 'end_datetime' => "$d 11:00:00",
    'reason' => '__test__有代理人未指定', 'agent_user_id' => 0, 'upload_token' => '']);
ok(empty($r2['ok']) && strpos($r2['msg'], '須指定職務代理人') !== false, '未指定被擋下', $r2['msg'] ?? '');
if (!empty($r2['id'])) $created[] = (int)$r2['id'];

$r3 = eg_leave_submit($db, ['employee_id' => $HASAGENT, 'leave_type_id' => $TYPE_AGENT,
    'start_datetime' => "$d 09:00:00", 'end_datetime' => "$d 11:00:00",
    'reason' => '__test__指定不在清單的人', 'agent_user_id' => 10, 'upload_token' => '']);
ok(empty($r3['ok']) && strpos($r3['msg'], '不在您的代理人設定中') !== false, '指定非候選者被擋下', $r3['msg'] ?? '');
if (!empty($r3['id'])) $created[] = (int)$r3['id'];

// 清理（只刪本腳本建立的）
foreach ($created as $id) {
    $ev = $db->query("SELECT evenement_id FROM leave_request WHERE id=$id")->fetchColumn();
    if ($ev) {
        $db->exec("DELETE FROM evenement_recipient_cache WHERE event_id=" . (int)$ev);
        $db->exec("DELETE FROM evenement_target WHERE event_id=" . (int)$ev);
        $db->exec("DELETE FROM evenement_actor WHERE event_id=" . (int)$ev);
        $db->exec("DELETE FROM evenement WHERE id=" . (int)$ev);
    }
    $db->exec("DELETE FROM leave_sign_record WHERE leave_request_id=$id");
    $db->exec("DELETE FROM leave_approval WHERE leave_request_id=$id");
    $db->exec("DELETE FROM leave_request WHERE id=$id");
}
$ev2 = $db->query("SELECT id FROM live_event WHERE ref_type='LEAVE'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($ev2 as $e) {
    $db->exec("DELETE FROM live_event_response WHERE live_event_id=" . (int)$e);
    $db->exec("DELETE FROM live_event_target WHERE live_event_id=" . (int)$e);
    $db->exec("DELETE FROM live_event WHERE id=" . (int)$e);
}
$left = (int)$db->query("SELECT COUNT(*) FROM leave_request WHERE reason LIKE '\\_\\_test\\_\\_%'")->fetchColumn();
ok($left === 0, '測試單已清除乾淨', (string)$left);
printf("\n結果：PASS %d / FAIL %d\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
