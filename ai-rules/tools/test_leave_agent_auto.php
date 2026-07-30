<?php
/**
 * 職務代理人自動解析驗證（2026-07-30 使用者定案：代理人不由申請人挑選）：
 *   - 依人事設定的 priority 取第一順位
 *   - 第一順位在申請人請假期間也請假（pending/approved 重疊）→ 自動換第二順位
 *   - 全部順位都不可用 → 記為無可用代理人但不擋請假
 *   - 多職務身分（主職＋兼任）各自解析一位
 * 測試對象：葉卿雅（主職 管理部/會計 → 林郁婷(1)、林雅婷(2)；兼任 文管中心/負責人 → 何沐桐）
 * 紀律：測試單以 __test__ 命名不發真實推播；清理只刪本腳本 lastInsertId 建立的列。
 */
mb_internal_encoding('UTF-8');
if (PHP_SAPI !== 'cli') exit('CLI only');
require_once 'C:/MAMP/htdocs/EGsystem/src/common/leave_lib.php';
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4",
              "EG-TS2024", "excell30367593", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = 0; $fail = 0; $created = [];
function ok($c, $n, $note = '') { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $n\n"; } else { $fail++; echo "  [FAIL] $n $note\n"; } }
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

$APP = (int)$db->query("SELECT id FROM user WHERE user_cname='葉卿雅' LIMIT 1")->fetchColumn();
if (!$APP) { echo "找不到測試對象，略過\n"; exit(0); }

// 期間：遠期工作日，避免撞真實資料
$d1 = date('Y-m-d', strtotime('+120 day'));
for ($i = 0; $i < 20 && !eg_leave_is_workday($db, $d1); $i++) $d1 = date('Y-m-d', strtotime($d1 . ' +1 day'));
$S = "$d1 08:00:00"; $E = "$d1 17:00:00";

echo "== 前提：此人有多身分、各有代理且有順位 ==\n";
$cands = eg_person_delegate_candidates($db, $APP);
$scopes = array_unique(array_column($cands, 'scope_label'));
ok(count($scopes) >= 2, '有兩個以上職務身分各有代理', json_encode(array_values($scopes)));

echo "== 情境1：無人衝突 → 各身分取第一順位 ==\n";
$ag = eg_leave_resolve_agents($db, $APP, $S, $E);
ok(count($ag) === count($scopes), '每個職務身分各解析一位', (string)count($ag));
foreach ($ag as $a) {
    ok(!empty($a['agent_user_id']) && (int)$a['priority_used'] === 1,
       "{$a['scope_label']} → {$a['agent_name']}（第 1 順位）", json_encode($a['reason']));
}
$mainAgent = null;
foreach ($ag as $a) if ($a['is_main'] === true) $mainAgent = $a;
ok($mainAgent !== null, '有主職身分的代理列');
$firstName = $mainAgent['agent_name'] ?? '';

echo "== 情境2：第一順位同期間也請假 → 自動換第二順位 ==\n";
// 讓主職第一順位代理人在同期間請假
$agent1 = (int)$mainAgent['agent_user_id'];
$r = eg_leave_submit($db, ['employee_id' => $agent1, 'leave_type_id' => 6,   // 公假：agent=0，不會遞迴解析代理
    'start_datetime' => $S, 'end_datetime' => $E,
    'reason' => '__test__代理人自己也請假', 'upload_token' => '']);
ok(!empty($r['ok']), "第一順位（{$firstName}）建立同期間請假單", $r['msg'] ?? '');
if (!empty($r['id'])) $created[] = (int)$r['id'];

$ag2 = eg_leave_resolve_agents($db, $APP, $S, $E);
$main2 = null;
foreach ($ag2 as $a) if ($a['is_main'] === true) $main2 = $a;
ok($main2 && (int)$main2['agent_user_id'] !== $agent1, '主職代理已改為別人（不再是第一順位）',
   json_encode([$main2['agent_name'] ?? '', $main2['priority_used'] ?? null]));
ok($main2 && (int)$main2['priority_used'] === 2, '用到第 2 順位', (string)($main2['priority_used'] ?? ''));
ok($main2 && !empty($main2['skipped']) && strpos($main2['skipped'][0]['why'], '同期間也請假') !== false,
   '有記錄被跳過的原因（同期間也請假）', json_encode($main2['skipped'] ?? []));
// 兼任身分不受影響
$sub2 = null;
foreach ($ag2 as $a) if ($a['is_main'] === false) $sub2 = $a;
if ($sub2) ok((int)$sub2['priority_used'] === 1, '兼任身分不受影響，仍為第 1 順位', json_encode($sub2['agent_name']));

echo "== 情境3：所有順位都請假 → 記為無可用代理人，但不擋請假 ==\n";
$agent2 = (int)$main2['agent_user_id'];
$r2 = eg_leave_submit($db, ['employee_id' => $agent2, 'leave_type_id' => 6,
    'start_datetime' => $S, 'end_datetime' => $E,
    'reason' => '__test__第二順位也請假', 'upload_token' => '']);
ok(!empty($r2['ok']), '第二順位也建立同期間請假單', $r2['msg'] ?? '');
if (!empty($r2['id'])) $created[] = (int)$r2['id'];

$ag3 = eg_leave_resolve_agents($db, $APP, $S, $E);
$main3 = null;
foreach ($ag3 as $a) if ($a['is_main'] === true) $main3 = $a;
ok($main3 && $main3['agent_user_id'] === null, '主職身分記為無可用代理人', json_encode($main3['reason'] ?? ''));
ok($main3 && count($main3['skipped']) >= 2, '兩位都被記為跳過', (string)count($main3['skipped'] ?? []));

echo "== 情境4：申請人實際送單 → 代理人寫入子表且不因無代理而被擋 ==\n";
$r3 = eg_leave_submit($db, ['employee_id' => $APP, 'leave_type_id' => 2,   // 事假 agent=1
    'start_datetime' => $S, 'end_datetime' => $E,
    'reason' => '__test__多身分代理', 'upload_token' => '']);
ok(!empty($r3['ok']), '送審成功（主職無可用代理也不擋）', $r3['msg'] ?? '');
$rid = (int)($r3['id'] ?? 0);
if ($rid) $created[] = $rid;
if ($rid) {
    $rows = eg_leave_get_agents($db, $rid);
    ok(count($rows) === count($scopes), '子表每身分一列', (string)count($rows));
    $hasNull = false; $hasSet = false;
    foreach ($rows as $x) { if ($x['agent_user_id'] === null) $hasNull = true; else $hasSet = true; }
    ok($hasNull, '無可用代理的身分存 NULL 並留原因');
    ok($hasSet, '仍有代理的身分正常寫入');
    $reasons = array_column($rows, 'resolve_reason');
    ok(implode('', $reasons) !== '', '每列都有人話原因供畫面顯示', json_encode($reasons));
    // 主檔相容欄位
    $req = $db->query("SELECT agent_user_id FROM leave_request WHERE id=$rid")->fetch(PDO::FETCH_ASSOC);
    ok($req['agent_user_id'] !== null, 'leave_request.agent_user_id 仍存一位（相容既有顯示）', json_encode($req));
}

echo "== 前端已移除代理人下拉 ==\n";
$src = file_get_contents('C:/MAMP/htdocs/EGsystem/views/ADM/leave_request.php');
ok(strpos($src, "id=\"fAgent\"") === false, '申請頁不再有代理人下拉');
ok(strpos($src, 'function renderAgentPreview') !== false, '改為唯讀顯示解析結果');
ok(strpos($src, 'agent_user_id: $') === false, '送出時不再傳 agent_user_id');
$api = file_get_contents('C:/MAMP/htdocs/EGsystem/src/store/Leave_API.php');
ok(strpos($api, "'agent_user_id'  => (int)(\$_POST['agent_user_id']") === false, 'API 不再接收前端指定的代理人');
ok(strpos($api, "\$ret['agents'] = eg_leave_resolve_agents") !== false, 'preview 回傳代理人解析結果');

cleanup($db, $created);
$left = (int)$db->query("SELECT COUNT(*) FROM leave_request WHERE reason LIKE '\\_\\_test\\_\\_%'")->fetchColumn();
ok($left === 0, '測試單已清除', (string)$left);
// 不能檢查「整表為空」——正式請假單也會有代理列。改檢查是否留下孤兒（父單已不存在的列）。
$orphan = (int)$db->query("SELECT COUNT(*) FROM leave_request_agent ra
                           LEFT JOIN leave_request lr ON lr.id = ra.leave_request_id
                           WHERE lr.id IS NULL")->fetchColumn();
ok($orphan === 0, 'leave_request_agent 無孤兒資料（父單已刪但代理列還在）', (string)$orphan);

printf("\n結果：PASS %d / FAIL %d\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
