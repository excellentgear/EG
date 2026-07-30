<?php
/**
 * 請假單徹底刪除（管理者／測試用）驗證：
 *   - 真的把主檔、簽核流程、簽章軌跡、行事曆事件(+actor/target/cache)、通知(+target/response) 全刪乾淨
 *   - 刪除前寫入 audit_log 留痕
 *   - 非管理者、確認碼不符、單號不存在 一律擋下
 * 本腳本自建測試單再刪除，全程只動自己 lastInsertId 建立的資料。
 */
mb_internal_encoding('UTF-8');
if (PHP_SAPI !== 'cli') exit('CLI only');
require_once 'C:/MAMP/htdocs/EGsystem/src/common/leave_lib.php';
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4",
              "EG-TS2024", "excell30367593", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = 0; $fail = 0;
function ok($c, $n, $note = '') { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $n\n"; } else { $fail++; echo "  [FAIL] $n $note\n"; } }

$UID = 113061801;   // 黃文孝（無代理候選，可直接送 agent=1 假別）
$ADMIN = 1;         // 超級管理員

// ── 建一張含簽核鏈、行事曆事件、通知的完整測試單 ──
$d = date('Y-m-d', strtotime('+110 day'));
for ($i = 0; $i < 20 && !eg_leave_is_workday($db, $d); $i++) $d = date('Y-m-d', strtotime($d . ' +1 day'));
$r = eg_leave_submit($db, ['employee_id' => $UID, 'leave_type_id' => 2,
    'start_datetime' => "$d 09:00:00", 'end_datetime' => "$d 12:00:00",
    'reason' => '__test__待刪除', 'agent_user_id' => 0, 'upload_token' => '']);
ok(!empty($r['ok']), '建立測試單', $r['msg'] ?? '');
$rid = (int)($r['id'] ?? 0);
if (!$rid) { echo "\n無法建立測試單，中止\n"; exit(1); }

$row = $db->query("SELECT * FROM leave_request WHERE id=$rid")->fetch(PDO::FETCH_ASSOC);
$evId = (int)($row['evenement_id'] ?? 0);
$evtIds = $db->query("SELECT id FROM live_event WHERE ref_type IN ('LEAVE','LEAVE_APPROVAL') AND ref_id=$rid")
             ->fetchAll(PDO::FETCH_COLUMN);
ok($evId > 0, '有行事曆事件可供驗證刪除', (string)$evId);
ok(count($evtIds) >= 1, '有通知可供驗證刪除', (string)count($evtIds));
$apN = (int)$db->query("SELECT COUNT(*) FROM leave_approval WHERE leave_request_id=$rid")->fetchColumn();
ok($apN >= 1, '有簽核流程列可供驗證刪除', (string)$apN);

echo "== 守門：不該刪的情況要擋下 ==\n";
$bad1 = eg_leave_delete($db, 999999999, $ADMIN);
ok(empty($bad1['ok']) && strpos($bad1['msg'], '不存在') !== false, '不存在的單號回錯誤', $bad1['msg'] ?? '');
// 權限：僅「員工 id=1 且 state=99 最高權限」可刪（2026-07-30 使用者要求，比一般管理者更嚴）
ok(eg_leave_is_superadmin($db, 1) === true, 'id=1 且 state=99 → 允許刪除');
ok(eg_leave_is_superadmin($db, 2) === false, 'id=2（state=90 特殊帳號）→ 不允許');
$anyAdmin = (int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                             WHERE r.is_system=1 AND ur.user_id<>1 LIMIT 1")->fetchColumn();
if ($anyAdmin) {
    ok(eg_leave_is_superadmin($db, $anyAdmin) === false,
       "具管理者角色但非 id=1（{$anyAdmin}）→ 仍不允許刪除");
} else { echo "  （無其他管理者角色帳號可測，略過）\n"; }
$state1 = (int)$db->query("SELECT state FROM user WHERE id=1")->fetchColumn();
ok($state1 === 99, '前提：id=1 的在職狀態為 99（最高權限）', (string)$state1);

$api = file_get_contents('C:/MAMP/htdocs/EGsystem/src/store/Leave_API.php');
ok(strpos($api, "case 'delete'") !== false, 'API 有 delete action');
ok(preg_match('/case \'delete\':.*?if \(!eg_leave_is_superadmin\(\$db, \$user_id\)\) bad\(/s', $api) === 1,
   'delete 用 eg_leave_is_superadmin 守門（非一般管理者旗標）');
ok(strpos($api, "\$_POST['confirm_id']") !== false, 'delete 需二次確認碼');
ok(preg_match('/case \'delete\':.*?need_csrf\(\)/s', $api) === 1, 'delete 有 CSRF 守門');
$src = file_get_contents('C:/MAMP/htdocs/EGsystem/views/ADM/leave_request.php');
ok(strpos($src, 'id="btnDeleteLeave"') !== false, '前端有刪除鈕');
ok(strpos($src, 'if ($IS_SUPERADMIN):') !== false, '刪除鈕只對最高權限帳號輸出');
ok(strpos($src, 'if(IS_SUPERADMIN) $(\'#btnDeleteLeave\').show()') !== false, 'JS 也只對最高權限顯示');
ok(strpos($src, '確定要刪除請輸入單號') !== false, '前端要求輸入單號才刪');

echo "== 實際刪除並驗證完全清乾淨 ==\n";
$auditBefore = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action_type='LEAVE_DELETE' AND target_id='$rid'")->fetchColumn();
$del = eg_leave_delete($db, $rid, $ADMIN);
ok(!empty($del['ok']), '刪除成功', $del['msg'] ?? '');

ok((int)$db->query("SELECT COUNT(*) FROM leave_request WHERE id=$rid")->fetchColumn() === 0, '主檔已刪除');
ok((int)$db->query("SELECT COUNT(*) FROM leave_approval WHERE leave_request_id=$rid")->fetchColumn() === 0, '簽核流程已刪除');
ok((int)$db->query("SELECT COUNT(*) FROM leave_sign_record WHERE leave_request_id=$rid")->fetchColumn() === 0, '簽章軌跡已刪除');
ok((int)$db->query("SELECT COUNT(*) FROM leave_attachment WHERE leave_request_id=$rid")->fetchColumn() === 0, '附件列已刪除');
ok((int)$db->query("SELECT COUNT(*) FROM evenement WHERE id=$evId")->fetchColumn() === 0, '行事曆事件已刪除');
ok((int)$db->query("SELECT COUNT(*) FROM evenement_actor WHERE event_id=$evId")->fetchColumn() === 0, '行事曆 actor 已刪除');
ok((int)$db->query("SELECT COUNT(*) FROM evenement_target WHERE event_id=$evId")->fetchColumn() === 0, '行事曆可見對象已刪除');
ok((int)$db->query("SELECT COUNT(*) FROM evenement_recipient_cache WHERE event_id=$evId")->fetchColumn() === 0, '行事曆收件快取已刪除');
$leftEvt = 0; $leftTg = 0; $leftRp = 0;
foreach ($evtIds as $e) {
    $leftEvt += (int)$db->query("SELECT COUNT(*) FROM live_event WHERE id=" . (int)$e)->fetchColumn();
    $leftTg  += (int)$db->query("SELECT COUNT(*) FROM live_event_target WHERE live_event_id=" . (int)$e)->fetchColumn();
    $leftRp  += (int)$db->query("SELECT COUNT(*) FROM live_event_response WHERE live_event_id=" . (int)$e)->fetchColumn();
}
ok($leftEvt === 0, '通知主檔已刪除', (string)$leftEvt);
ok($leftTg === 0, '通知收件對象已刪除', (string)$leftTg);
ok($leftRp === 0, '通知回應紀錄已刪除', (string)$leftRp);

$auditAfter = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action_type='LEAVE_DELETE' AND target_id='$rid'")->fetchColumn();
ok($auditAfter === $auditBefore + 1, '刪除已寫入 audit_log 留痕（仍可追溯刪了什麼）');
$chg = $db->query("SELECT changes FROM audit_log WHERE action_type='LEAVE_DELETE' AND target_id='$rid' ORDER BY id DESC LIMIT 1")->fetchColumn();
$j = json_decode((string)$chg, true);
ok(is_array($j) && isset($j['request']['reason']) && $j['request']['reason'] === '__test__待刪除',
   'audit_log 內含刪除前的單據內容', substr((string)$chg, 0, 80));

// 清理本腳本產生的稽核紀錄（測試不留垢；只刪自己這一筆 target_id）
$db->exec("DELETE FROM audit_log WHERE action_type='LEAVE_DELETE' AND target_id='$rid'");
ok((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action_type='LEAVE_DELETE' AND target_id='$rid'")->fetchColumn() === 0,
   '測試用稽核紀錄已清除');
$leftReq = (int)$db->query("SELECT COUNT(*) FROM leave_request WHERE reason LIKE '\\_\\_test\\_\\_%'")->fetchColumn();
ok($leftReq === 0, '無殘留測試單', (string)$leftReq);

printf("\n結果：PASS %d / FAIL %d\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
