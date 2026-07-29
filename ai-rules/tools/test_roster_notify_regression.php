<?php
// ROSTER 通知回歸測試（ai-rules/13 陷阱表要求）：確認改造收件人展開層後，
// 輪值排班既有通知鏈行為不變。**不發真實通知**：收件人挑「沒有 Web Push 訂閱、也沒綁 Telegram」的員工。
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306", "EG-TS2024", "excell30367593");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("set names utf8mb4");
require_once 'C:/MAMP/htdocs/EGsystem/src/common/roster_lib.php';
require_once 'C:/MAMP/htdocs/EGsystem/src/common/shared_account_lib.php';

$pass = 0; $fail = 0; $createdEvents = [];
function ck(string $n, bool $ok, string $x = '') { global $pass, $fail; if ($ok) { $pass++; echo "  [OK] $n\n"; } else { $fail++; echo "  [FAIL] $n $x\n"; } }

// 挑一位「無推播訂閱、無 Telegram 綁定」的在職員工當收件人 → 全程不會有任何東西真的送出
$safe = $db->query("SELECT u.id, u.user_cname FROM `user` u
                    WHERE u.state IN (1,99)
                      AND NOT EXISTS (SELECT 1 FROM push_subscription p WHERE p.user_id = u.id AND p.is_active = 1)
                      AND NOT EXISTS (SELECT 1 FROM telegram_users t WHERE t.user_id = u.id AND t.is_active = 1)
                    LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$safe) die("找不到「無訂閱且無 Telegram」的員工，為避免發出真實通知，中止測試\n");
echo "安全收件人：{$safe['user_cname']}({$safe['id']})（無 Web Push 訂閱、無 Telegram 綁定）\n\n";

try {
    echo "── ROSTER 通知鏈回歸 ──\n";

    // 1) 測試資料防護仍在
    ck('title 含 __ 的測試資料不發通知（回歸）', roster_notify($db, '__test__輪值__', '內容', [(int)$safe['id']], 0) === null);

    // 2) 真實路徑：建立 event + target + 走推播/Telegram（收件人無任何通道 → 實際 0 送出）
    $eid = roster_notify($db, '輪值排班回歸測試（自動化）', '此為系統回歸測試，可忽略。', [(int)$safe['id']], 0);
    ck('roster_notify 正常建立 live_event', is_int($eid) && $eid > 0, (string)$eid);
    if ($eid) {
        $createdEvents[] = $eid;
        $ev = $db->query("SELECT ref_type, source FROM live_event WHERE id = $eid")->fetch(PDO::FETCH_ASSOC);
        ck("ref_type='ROSTER'、source='輪值排班' 不變", $ev['ref_type'] === 'ROSTER' && $ev['source'] === '輪值排班', json_encode($ev));

        $tg = $db->query("SELECT target_type, target_id, mode FROM live_event_target WHERE live_event_id = $eid")->fetchAll(PDO::FETCH_ASSOC);
        ck('live_event_target 仍為 user/read 指名', count($tg) === 1 && $tg[0]['target_type'] === 'user'
            && (int)$tg[0]['target_id'] === (int)$safe['id'] && $tg[0]['mode'] === 'read', json_encode($tg));

        // 3) 收件人展開：未綁定者一人一則、不轉送
        $named = eg_shared_named_recipients($db, $eid);
        ck('指名收件人解析正確', $named === [(int)$safe['id']], json_encode($named));
        $fan = eg_shared_fanout($db, [(int)$safe['id']], $named);
        ck('未綁定者展開後仍只有本人一筆（行為不變）',
            count($fan) === 1 && $fan[0]['deliver_uid'] === (int)$safe['id'] && $fan[0]['for_uid'] === (int)$safe['id'], json_encode($fan));

        // 4) 實際跑一次推播出口（該員工無訂閱 → sent 應為 0，且不得拋錯）
        require_once 'C:/MAMP/htdocs/EGsystem/src/push/push_send.php';
        $r = eg_push_event_notify($db, $eid, [(int)$safe['id']]);
        ck('eg_push_event_notify 正常回傳、無真實送出', !empty($r['ok']) && (int)($r['sent'] ?? -1) === 0, json_encode($r));
    }
} finally {
    foreach ($createdEvents as $e) {
        $db->prepare("DELETE FROM live_event_target WHERE live_event_id = ?")->execute([$e]);
        $db->prepare("DELETE FROM live_event_for_user WHERE live_event_id = ?")->execute([$e]);
        $db->prepare("DELETE FROM live_event WHERE id = ?")->execute([$e]);
    }
    echo "\n清理完成（刪除本次建立的 " . count($createdEvents) . " 則測試 live_event 及其對象/已閱列）\n";
}

echo "\n===== 結果：通過 $pass 項，失敗 $fail 項 =====\n";
exit($fail > 0 ? 1 : 0);
