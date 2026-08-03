<?php
/**
 * 留停提前結束測試（2026-07-31 定案，2026-08-03 建立）
 *
 * 重點：提前結束是「縮短結束日」不是「銷假」——已休過的那段必須留在系統裡，
 * 而且要看得出「原訂到 X、實際到 Y」。另驗權限、邊界、時數重算、user.state 復職。
 *
 * 測試資料以 reason 前綴 __test_ee__ 標記，只刪自己 lastInsertId 建立的列；
 * user.state 會先記下原值，測完一定改回去（動到員工主檔，不可留下痕跡）。
 */
mb_internal_encoding('UTF-8');
if (PHP_SAPI !== 'cli') exit('CLI only');

require_once 'C:/MAMP/htdocs/EGsystem/src/common/leave_lib.php';
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4", "EG-TS2024", "excell30367593",
              [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$UID   = 107092601;   // 邱冠宏（請假人）
$HR    = 1;           // 最高權限（當作人事操作者）
$PLAIN = 109110201;   // 何沐桐（一般人，驗權限）
$pass = 0; $fail = 0;
function ok(bool $c, string $n, string $note = '') {
    global $pass, $fail;
    if ($c) { $pass++; echo "  [PASS] $n\n"; } else { $fail++; echo "  [FAIL] $n $note\n"; }
}
function call_api(int $uid, array $req, array $post = []): array {
    $cmd = 'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe ' . escapeshellarg(__DIR__ . '/_api_runner.php')
         . ' ' . escapeshellarg((string)$uid)
         . ' ' . escapeshellarg(base64_encode(json_encode($req, JSON_UNESCAPED_UNICODE)))
         . ' ' . escapeshellarg(base64_encode(json_encode($post, JSON_UNESCAPED_UNICODE)));
    $j = json_decode(trim((string)shell_exec($cmd)), true);
    return is_array($j) ? $j : ['__raw' => 'no-json'];
}

/* 共用的 API runner：test_leave_api.php 跑完會把它刪掉，所以每支測試都要自己確保它在。
   （漏掉這段時，單獨跑會過、批次跑到 test_leave_api 之後就拿不到 JSON。） */
file_put_contents(__DIR__ . '/_api_runner.php', <<<'PHP'
<?php
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
$_SERVER['DOCUMENT_ROOT'] = 'C:/MAMP/htdocs';
$_SERVER['REQUEST_METHOD'] = 'POST';
session_start();
$_SESSION['id'] = (int)$argv[1];
$_SESSION['userName'] = 'test';
$req  = json_decode(base64_decode($argv[2]), true) ?: [];
$post = json_decode(base64_decode($argv[3]), true) ?: [];
$_GET = $req; $_POST = $post; $_REQUEST = array_merge($req, $post);
ob_start();
include 'C:/MAMP/htdocs/EGsystem/src/store/Leave_API.php';
$o = ob_get_clean();
echo $o;
PHP);

$PAR = $db->query("SELECT * FROM leave_type WHERE leave_name = '育嬰留停' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$created = [];
$stateBefore = (int)$db->query("SELECT state FROM `user` WHERE id = $UID")->fetchColumn();
echo "受測員工 #$UID 目前 state = {$stateBefore}（測完會改回這個值）\n";

$ins = $db->prepare(
    "INSERT INTO leave_request (employee_id, leave_type_id, start_datetime, end_datetime, reason, status,
                                total_hours, total_days, child_birthday, submit_time, decided_at)
     VALUES (?,?,?,?, '__test_ee__ 提前結束測試', ?, ?, ?, ?, NOW(), NOW())");
function mkReq(PDO $db, PDOStatement $ins, array $a, array &$created): int {
    $ins->execute($a);
    $id = (int)$db->lastInsertId(); $created[] = $id; return $id;
}

try {
    // 一張已核准的育嬰留停：2026-01-05 ~ 2026-12-31
    $id = mkReq($db, $ins, [$UID, $PAR['id'], '2026-01-05 08:00:00', '2026-12-31 17:00:00',
                            'approved', 1600, 200, '2025-01-15'], $created);
    echo "測試單 #{$id}：2026-01-05 ~ 2026-12-31（approved）\n";

    echo "== 參數與邊界 ==\n";
    $r = eg_leave_early_end($db, $id, $HR, '2026-06-30', '');
    ok(!$r['ok'] && strpos($r['msg'], '原因') !== false, '沒填原因 → 擋', $r['msg']);
    $r = eg_leave_early_end($db, $id, $HR, '2025-12-01', '提早復職');
    ok(!$r['ok'] && strpos($r['msg'], '不可早於請假開始日') !== false, '早於開始日 → 擋', $r['msg']);
    $r = eg_leave_early_end($db, $id, $HR, '2026-12-31', '提早復職');
    ok(!$r['ok'] && strpos($r['msg'], '必須早於原結束日') !== false, '等於原結束日 → 擋', $r['msg']);
    $r = eg_leave_early_end($db, $id, $HR, '2027-01-05', '提早復職');
    ok(!$r['ok'], '晚於原結束日 → 擋', $r['msg']);
    $r = eg_leave_early_end($db, $id, $HR, 'not-a-date', '提早復職');
    ok(!$r['ok'] && strpos($r['msg'], '格式') !== false, '日期格式錯 → 擋', $r['msg']);

    echo "== 只有已核准的單可以提前結束 ==\n";
    $pid = mkReq($db, $ins, [$UID, $PAR['id'], '2027-03-01 08:00:00', '2027-09-01 17:00:00',
                             'pending', 800, 100, '2025-01-15'], $created);
    $r = eg_leave_early_end($db, $pid, $HR, '2027-05-01', '提早復職');
    ok(!$r['ok'] && strpos($r['msg'], '已核准') !== false, '審核中的單 → 擋', $r['msg']);

    echo "== 正常提前結束 ==\n";
    // 先把 state 設成留職停薪，驗復職
    $db->prepare("UPDATE `user` SET state = 2 WHERE id = ?")->execute([$UID]);
    $r = eg_leave_early_end($db, $id, $HR, '2026-06-30', '提早復職');
    ok($r['ok'], '提前結束成功', $r['msg']);
    ok(!empty($r['state_changed']), '在職狀態有被改動', json_encode($r));
    $row = $db->query("SELECT * FROM leave_request WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    ok(substr($row['end_datetime'], 0, 10) === '2026-06-30', '結束日已縮短', $row['end_datetime']);
    ok(substr($row['end_datetime'], 11, 5) === '17:00', '結束時間沿用原本的 17:00', $row['end_datetime']);
    ok(substr($row['orig_end_datetime'], 0, 10) === '2026-12-31', '原訂結束日有留下來', (string)$row['orig_end_datetime']);
    ok($row['status'] === 'approved', '狀態仍是已核准（不是銷假）', $row['status']);
    ok((float)$row['total_days'] > 0 && (float)$row['total_days'] < 200, '天數已重算且變少', $row['total_days']);
    ok((int)$row['early_end_by'] === $HR, '記錄辦理人', (string)$row['early_end_by']);
    ok(strpos((string)$row['early_end_reason'], '提早復職') !== false, '記錄原因', (string)$row['early_end_reason']);
    ok(!empty($row['early_end_at']), '記錄辦理時間');
    $newState = (int)$db->query("SELECT state FROM `user` WHERE id = $UID")->fetchColumn();
    ok($newState === 1, 'user.state 已改回在職(1)', (string)$newState);
    $trace = $db->query("SELECT COUNT(*) FROM leave_sign_record WHERE leave_request_id = $id AND step_no = 97")->fetchColumn();
    ok((int)$trace === 1, '簽章軌跡有一筆 step 97（提前結束）', (string)$trace);

    echo "== 再提前一次：最初的原訂日不可被覆蓋 ==\n";
    $r = eg_leave_early_end($db, $id, $HR, '2026-05-31', '再提早');
    ok($r['ok'], '可以再提前一次', $r['msg']);
    $row = $db->query("SELECT * FROM leave_request WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    ok(substr($row['orig_end_datetime'], 0, 10) === '2026-12-31',
       '原訂結束日仍是最初的 2026-12-31，沒被 06-30 覆蓋', (string)$row['orig_end_datetime']);
    ok(substr($row['end_datetime'], 0, 10) === '2026-05-31', '結束日更新為 2026-05-31', $row['end_datetime']);

    echo "== 不動非留停狀態的員工 ==\n";
    $db->prepare("UPDATE `user` SET state = 1 WHERE id = ?")->execute([$UID]);
    $id2 = mkReq($db, $ins, [$UID, $PAR['id'], '2028-01-05 08:00:00', '2028-06-30 17:00:00',
                             'approved', 800, 100, '2025-01-15'], $created);
    $r = eg_leave_early_end($db, $id2, $HR, '2028-03-31', '提早復職');
    ok($r['ok'] && empty($r['state_changed']), '本來就在職(1) → 不動 state', json_encode($r));
    ok((int)$db->query("SELECT state FROM `user` WHERE id = $UID")->fetchColumn() === 1, 'state 仍為 1');

    echo "== 不改在職狀態的選項 ==\n";
    $db->prepare("UPDATE `user` SET state = 3 WHERE id = ?")->execute([$UID]);
    $id3 = mkReq($db, $ins, [$UID, $PAR['id'], '2029-01-05 08:00:00', '2029-06-30 17:00:00',
                             'approved', 800, 100, '2025-01-15'], $created);
    $r = eg_leave_early_end($db, $id3, $HR, '2029-03-31', '提早復職', false);
    ok($r['ok'] && empty($r['state_changed']), 'restore_state=false → 不動 state', json_encode($r));
    ok((int)$db->query("SELECT state FROM `user` WHERE id = $UID")->fetchColumn() === 3, 'state 仍為 3（育嬰留停）');

    echo "== API 權限 ==\n";
    $r = call_api($PLAIN, [], ['action' => 'early_end', 'id' => $id2, 'new_end_date' => '2028-02-01',
                               'reason' => '測試', 'csrf' => 'x']);
    ok(empty($r['success']), '一般使用者呼叫 early_end 被擋', json_encode($r));
    $r = call_api($HR, [], ['action' => 'early_end', 'id' => $id2, 'new_end_date' => '2028-02-01',
                            'reason' => '測試', 'csrf' => 'wrong']);
    ok(empty($r['success']) && strpos((string)($r['message'] ?? ''), 'CSRF') !== false,
       '錯誤 CSRF 被擋（fail-closed）', json_encode($r));
} finally {
    $db->prepare("UPDATE `user` SET state = ? WHERE id = ?")->execute([$stateBefore, $UID]);
    if ($created) {
        $in = implode(',', array_map('intval', $created));
        $db->exec("DELETE FROM leave_sign_record WHERE leave_request_id IN ($in)");
        $db->exec("DELETE FROM live_event WHERE ref_type = 'LEAVE' AND ref_id IN ($in)");
        $del = $db->prepare("DELETE FROM leave_request WHERE id = ? AND reason LIKE '__test_ee__%'");
        foreach ($created as $cid) $del->execute([$cid]);
        $left = (int)$db->query("SELECT COUNT(*) FROM leave_request WHERE id IN ($in)")->fetchColumn();
        ok($left === 0, '測試單已清除', "殘留 $left 筆");
    }
    $back = (int)$db->query("SELECT state FROM `user` WHERE id = $UID")->fetchColumn();
    ok($back === $stateBefore, "員工在職狀態已還原為 $stateBefore", (string)$back);
}

echo "\n結果：PASS $pass / FAIL $fail\n";
exit($fail > 0 ? 1 : 0);
