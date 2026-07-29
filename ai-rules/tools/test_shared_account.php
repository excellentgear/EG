<?php
// 共用帳號綁定/通知轉送 驗收測試（testing_discipline：只刪自己 lastInsertId 建立的列；
// 不呼叫真實推播發送，只驗證「收件人展開」與「回歸行為」）
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306", "EG-TS2024", "excell30367593");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("set names utf8mb4");
require_once 'C:/MAMP/htdocs/EGsystem/src/common/shared_account_lib.php';

$pass = 0; $fail = 0; $created = ['member' => [], 'event' => [], 'target' => []];
function ck(string $name, bool $ok, string $extra = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK] $name\n"; }
    else { $fail++; echo "  [FAIL] $name $extra\n"; }
}
function fanoutKeys(array $entries): array {
    $k = array_map(fn($e) => $e['deliver_uid'] . '<-' . $e['for_uid'], $entries);
    sort($k); return $k;
}

// 取三個真實在職員工當測試對象（不改他們任何資料，只建/刪綁定列）
$emps = $db->query("SELECT id, user_cname FROM `user` WHERE state IN (1,99) ORDER BY id LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
if (count($emps) < 3) { die("在職員工不足 3 位，無法測試\n"); }
[$A, $B, $C] = $emps;
// 共用帳號用「報工公用」(99992)
$SHARED = 99992;
$sharedWas = (int)$db->query("SELECT is_shared_account FROM `user` WHERE id = $SHARED")->fetchColumn();
$lockWas   = (int)$db->query("SELECT lock_password    FROM `user` WHERE id = $SHARED")->fetchColumn();

echo "測試對象：A={$A['user_cname']}({$A['id']})  B={$B['user_cname']}({$B['id']})  C={$C['user_cname']}({$C['id']})  共用帳號={$SHARED}\n\n";

try {
    // ── 前置：標記共用帳號、A=attach、B=notify、C=無綁定 ──
    $db->exec("UPDATE `user` SET is_shared_account = 1 WHERE id = $SHARED");
    $ins = $db->prepare("INSERT INTO shared_account_member (shared_uid, member_uid, mode, active, created_by) VALUES (?,?,?,1,0)");
    $ins->execute([$SHARED, $A['id'], 'attach']); $created['member'][] = (int)$db->lastInsertId();
    $ins->execute([$SHARED, $B['id'], 'notify']); $created['member'][] = (int)$db->lastInsertId();

    echo "── 1. fanout 展開規則 ──\n";
    $r = eg_shared_fanout($db, [(int)$A['id']]);
    ck('attach：只送共用帳號、本人不推播', fanoutKeys($r) === ["$SHARED<-{$A['id']}"], json_encode(fanoutKeys($r)));

    $r = eg_shared_fanout($db, [(int)$B['id']]);
    ck('notify：本人＋共用帳號雙送', fanoutKeys($r) === fanoutKeys([
        ['deliver_uid' => $SHARED, 'for_uid' => (int)$B['id']],
        ['deliver_uid' => (int)$B['id'], 'for_uid' => (int)$B['id']],
    ]), json_encode(fanoutKeys($r)));

    $r = eg_shared_fanout($db, [(int)$C['id']]);
    ck('未綁定者行為完全不變', fanoutKeys($r) === ["{$C['id']}<-{$C['id']}"], json_encode(fanoutKeys($r)));

    $r = eg_shared_fanout($db, [(int)$A['id'], (int)$B['id']]);
    $keys = fanoutKeys($r);
    ck('同一則同時給 A、B：共用帳號收到兩筆（各自 for_uid，不合併）',
        in_array("$SHARED<-{$A['id']}", $keys, true) && in_array("$SHARED<-{$B['id']}", $keys, true) && count($keys) === 3,
        json_encode($keys));

    $r = eg_shared_fanout($db, [(int)$A['id'], (int)$A['id']]);
    ck('重複收件人去重', count($r) === 1, json_encode(fanoutKeys($r)));

    echo "\n── 2. 標題前綴 ──\n";
    $e = eg_shared_fanout($db, [(int)$A['id']])[0];
    $t = eg_shared_prefix_title('請於今日完成點檢', $e);
    ck('轉送則加「【給 ○○○】」', $t === '【給 ' . $A['user_cname'] . '】請於今日完成點檢', $t);
    $t2 = eg_shared_prefix_title('請於今日完成點檢', ['deliver_uid' => 5, 'for_uid' => 5, 'for_name' => '']);
    ck('本人自己收：標題不變', $t2 === '請於今日完成點檢', $t2);

    echo "\n── 3. 指名 vs 廣播（現場平板洗版防線）──\n";
    $db->prepare("INSERT INTO live_event (eventdate, title, content, status, created_by, source)
                  VALUES (CURDATE(), '__test__共用帳號測試公告__', '測試內容', 0, 0, '__test__')")->execute();
    $eid = (int)$db->lastInsertId(); $created['event'][] = $eid;
    $tg = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?,?,?,'read')");
    $tg->execute([$eid, 'user', $A['id']]); $created['target'][] = (int)$db->lastInsertId();
    $tg->execute([$eid, 'all', 0]);          $created['target'][] = (int)$db->lastInsertId();

    $named = eg_shared_named_recipients($db, $eid);
    ck('指名收件人只取 target_type=user', $named === [(int)$A['id']], json_encode($named));

    // 廣播進來的 B（不在指名名單）→ 不轉送
    $r = eg_shared_fanout($db, [(int)$A['id'], (int)$B['id']], $named);
    $keys = fanoutKeys($r);
    ck('廣播對象不逐人轉送（B 只送本人）', in_array("{$B['id']}<-{$B['id']}", $keys, true) && !in_array("$SHARED<-{$B['id']}", $keys, true), json_encode($keys));
    ck('同一則中被指名的 A 仍然轉送', in_array("$SHARED<-{$A['id']}", $keys, true), json_encode($keys));

    echo "\n── 4. 站內清單可見範圍 ──\n";
    $v = eg_shared_view_uids($db, $SHARED); sort($v);
    $expect = [(int)$A['id'], (int)$B['id'], $SHARED]; sort($expect);
    ck('共用帳號看得到自己＋成員', $v === $expect, json_encode($v));
    $v2 = eg_shared_view_uids($db, (int)$C['id']);
    ck('一般帳號只看得到自己', $v2 === [(int)$C['id']], json_encode($v2));

    echo "\n── 5. 代簽身分驗證 ──\n";
    $pw = $db->query("SELECT user_password FROM `user` WHERE id = {$A['id']}")->fetchColumn();
    $a1 = eg_shared_resolve_actor($db, $SHARED, (int)$A['id'], '');
    ck('未輸密碼 → 要求輸入', !$a1['ok'] && $a1['msg'] === 'NEED_PASSWORD', json_encode($a1));
    $a2 = eg_shared_resolve_actor($db, $SHARED, (int)$A['id'], 'wrong-pw-xxx');
    ck('密碼錯誤 → 擋下', !$a2['ok'] && $a2['msg'] !== 'NEED_PASSWORD', json_encode($a2));
    $a3 = eg_shared_resolve_actor($db, $SHARED, (int)$A['id'], (string)$pw);
    ck('密碼正確 → 記在成員本人、signed_via=共用帳號', $a3['ok'] && $a3['uid'] === (int)$A['id'] && $a3['via'] === $SHARED, json_encode($a3));
    $a4 = eg_shared_resolve_actor($db, $SHARED, (int)$C['id'], (string)$pw);
    ck('非本共用帳號成員 → 拒絕代簽', !$a4['ok'], json_encode($a4));
    $a5 = eg_shared_resolve_actor($db, (int)$C['id'], 0, '');
    ck('本人自己操作 → 免密碼、行為不變', $a5['ok'] && $a5['uid'] === (int)$C['id'] && $a5['via'] === null, json_encode($a5));

    echo "\n── 6. 推播狀態過濾放行共用帳號 ──\n";
    require_once 'C:/MAMP/htdocs/EGsystem/src/push/push_send.php';
    $f = eg_push_active_user_filter($db, [$SHARED, (int)$A['id']]);
    ck('共用帳號(state=90)不再被狀態過濾丟掉', in_array($SHARED, $f, true), json_encode($f));
    $off = (int)$db->query("SELECT id FROM `user` WHERE state = 0 LIMIT 1")->fetchColumn();
    if ($off) { $f2 = eg_push_active_user_filter($db, [$off]); ck('離職者仍然不收推播（回歸）', $f2 === [], json_encode($f2)); }

    echo "\n── 7. 密碼鎖 ──\n";
    $db->exec("UPDATE `user` SET lock_password = 1 WHERE id = $SHARED");
    ck('lock_password=1 判定為鎖定', eg_shared_password_locked($db, $SHARED) === true);
    $db->exec("UPDATE `user` SET lock_password = 0 WHERE id = $SHARED");
    ck('lock_password=0 判定為未鎖', eg_shared_password_locked($db, $SHARED) === false);
    ck('一般員工預設未鎖（回歸）', eg_shared_password_locked($db, (int)$C['id']) === false);

    echo "\n── 8. 停用綁定即恢復原行為 ──\n";
    $db->prepare("UPDATE shared_account_member SET active = 0 WHERE id = ?")->execute([$created['member'][0]]);
    $r = eg_shared_fanout($db, [(int)$A['id']]);
    ck('active=0 的綁定不生效', fanoutKeys($r) === ["{$A['id']}<-{$A['id']}"], json_encode(fanoutKeys($r)));

} finally {
    // ── 清理：只刪自己建立的列，並還原共用帳號旗標 ──
    foreach ($created['target'] as $i) $db->prepare("DELETE FROM live_event_target WHERE id = ?")->execute([$i]);
    foreach ($created['event']  as $i) $db->prepare("DELETE FROM live_event WHERE id = ?")->execute([$i]);
    foreach ($created['member'] as $i) $db->prepare("DELETE FROM shared_account_member WHERE id = ?")->execute([$i]);
    $db->prepare("UPDATE `user` SET is_shared_account = ?, lock_password = ? WHERE id = ?")->execute([$sharedWas, $lockWas, $SHARED]);
    echo "\n清理完成（測試建立的 " . count($created['member']) . " 筆綁定 / " . count($created['event']) . " 則公告 / " . count($created['target']) . " 筆對象已刪除；共用帳號旗標已還原）\n";
}

echo "\n===== 結果：通過 $pass 項，失敗 $fail 項 =====\n";
exit($fail > 0 ? 1 : 0);
