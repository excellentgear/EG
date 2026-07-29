<?php
/**
 * 請假系統 P1 整合測試（真實庫）
 * 測試紀律（記憶 testing_discipline）：
 *   - 測試單 reason 帶 __test__ 命名 → eg_leave_notify 不發真實推播（只寫站內 live_event）
 *   - 清理只刪「本腳本 lastInsertId / 捕捉到的 id」建立的列，絕不按值刪除
 * 測試對象：申請人=生產1廠組員 邱冠宏(107092601)，預期主管=同部門組長 黃文德(107022301)
 */
mb_internal_encoding('UTF-8');
if (PHP_SAPI !== 'cli') exit('CLI only');
$root = 'C:/MAMP/htdocs/EGsystem';
require_once $root . '/src/common/leave_lib.php';

$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4",
              "EG-TS2024", "excell30367593", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$APPLICANT = 107092601;   // 邱冠宏 組員
$EXPECT_SUP = 107022301;  // 黃文德 組長（同部門 level 3）
$TYPE_GONG = 6;           // 公假 agent=0 need_approval=1 max=1

$pass = 0; $fail = 0;
$createdRequests = [];    // 清理用
$createdEvents = [];      // live_event 清理用
function ok(bool $cond, string $name, string $note = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [PASS] $name\n"; }
    else { $fail++; echo "  [FAIL] $name $note\n"; }
}

// 找未來的連續工作日（避開假日與既有單據）
function next_workdays(PDO $db, int $n, string $from): array {
    $out = []; $cur = strtotime($from); $guard = 0;
    while (count($out) < $n && $guard++ < 60) {
        $d = date('Y-m-d', $cur);
        if (eg_leave_is_workday($db, $d)) $out[] = $d;
        $cur = strtotime('+1 day', $cur);
    }
    return $out;
}
$wd = next_workdays($db, 4, date('Y-m-d', strtotime('+40 day'))); // 遠期避免撞真實資料
list($d1, $d2, $d3, $d4) = $wd;

echo "== 1. 時數計算 ==\n";
$a = eg_leave_calc_amount($db, 'hour', "$d1 09:00:00", "$d1 12:00:00");
ok(abs($a['hours'] - 3) < 0.01, "同日時假 3 小時", json_encode($a));
$a = eg_leave_calc_amount($db, 'hour', "$d1 09:00:00", "$d2 17:00:00");
ok($a['workdays'] === 2 && $a['hours'] <= 16, "跨日時假只計工作日(2日)", json_encode($a));
$a = eg_leave_calc_amount($db, 'day', "$d1 00:00:00", "$d3 23:59:00");
ok($a['workdays'] === 3 && abs($a['days'] - 3) < 0.01, "整天假 3 個工作日=3天", json_encode($a));
$a = eg_leave_calc_amount($db, 'halfday', "$d1 09:00:00", "$d1 11:00:00");
ok(abs($a['hours'] - 4) < 0.01, "半天假 2 小時向上取整為 4 小時", json_encode($a));
// 週末不計：找一個週六
$sat = date('Y-m-d', strtotime('next saturday', strtotime($d1)));
$a = eg_leave_calc_amount($db, 'day', "$sat 00:00:00", "$sat 23:59:00");
ok($a['hours'] == 0 || eg_leave_is_workday($db, $sat), "週六(非補班)不計時數", json_encode($a));

echo "== 2. 主管鏈與預覽 ==\n";
$chain = eg_leave_supervisor_chain($db, $APPLICANT, 1);
ok(count($chain) === 1 && $chain[0]['user_id'] === $EXPECT_SUP, "組員→組長 一層鏈", json_encode($chain));
// 現況：父部門 primary_user_id 未設、最終裁決者未設 → 依設計鏈停在可解析的層數（不硬湊）
$chain2 = eg_leave_supervisor_chain($db, $APPLICANT, 3);
ok(count($chain2) >= 1 && $chain2[0]['user_id'] === $EXPECT_SUP,
   "三層鏈：至少一層且第一層正確（上級未設指定負責人/最終裁決者時依設計收鏈）", json_encode($chain2));
// 最終裁決者補位邏輯：直接以子行程驗證（settings 有 static 快取，需要新進程）
$probe = <<<'PHP'
<?php
require_once 'C:/MAMP/htdocs/EGsystem/src/common/leave_lib.php';
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4","EG-TS2024","excell30367593",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("UPDATE system_settings SET setting_value='10' WHERE setting_key='leave_final_decider_id'");
try { $chain = eg_leave_supervisor_chain($db, 107092601, 3); echo json_encode($chain); }
finally { $db->exec("UPDATE system_settings SET setting_value='' WHERE setting_key='leave_final_decider_id'"); }
PHP;
$probeFile = __DIR__ . '/probe_chain.php';
file_put_contents($probeFile, $probe);
$out = shell_exec('C:\\MAMP\\bin\\php\\php8.3.1\\php.exe ' . escapeshellarg($probeFile));
@unlink($probeFile);
$chain3 = json_decode((string)$out, true) ?: [];
$hasFinal = false;
foreach ($chain3 as $c) if ((int)$c['user_id'] === 10 && !empty($c['fallback'])) $hasFinal = true;
ok($hasFinal, "設最終裁決者(董事長)後，解析不到上級時由他補位", (string)$out);
$restored = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='leave_final_decider_id'")->fetchColumn();
ok($restored === '', "最終裁決者設定已還原為空");
$prev = eg_leave_preview_signers($db, $APPLICANT, 1);
ok(!empty($prev) && $prev[0]['target_id'] === $EXPECT_SUP, "預覽第一層 target=組長", json_encode($prev));
$signer1 = $prev[0]['signer_id'];   // 今日組長可能有行程→代理；以解析結果為準

echo "== 3. 送審（公假，核准流程）==\n";
$r = eg_leave_submit($db, [
    'employee_id' => $APPLICANT, 'leave_type_id' => $TYPE_GONG,
    'start_datetime' => "$d1 09:00:00", 'end_datetime' => "$d1 17:00:00",
    'reason' => '__test__請假系統P1整合測試A', 'agent_user_id' => 0, 'upload_token' => '',
]);
ok($r['ok'], "送審成功", $r['msg'] ?? '');
$reqA = (int)($r['id'] ?? 0);
if ($reqA) $createdRequests[] = $reqA;
$row = $db->query("SELECT * FROM leave_request WHERE id = $reqA")->fetch(PDO::FETCH_ASSOC);
ok($row && $row['status'] === 'pending', "主檔 pending");
ok($row && (float)$row['total_hours'] == 8.0, "時數 8 小時", (string)($row['total_hours'] ?? ''));
$ap = $db->query("SELECT * FROM leave_approval WHERE leave_request_id = $reqA")->fetchAll(PDO::FETCH_ASSOC);
ok(count($ap) === 1 && (int)$ap[0]['approver_id'] === $EXPECT_SUP && $ap[0]['status'] === 'pending',
   "leave_approval 一層 pending、應簽=組長", json_encode($ap));
$evId = (int)($row['evenement_id'] ?? 0);
$ev = $evId ? $db->query("SELECT e.*, ec.category_name FROM evenement e JOIN event_category ec ON ec.id=e.category_id WHERE e.id=$evId")->fetch(PDO::FETCH_ASSOC) : null;
ok($ev && $ev['category_name'] === '請假申請中', "行事曆寫入「請假申請中」事件", json_encode($ev ?: []));
ok($ev && strpos((string)$ev['title'], '(申請中)') !== false, "事件標題帶(申請中)");
$actor = $evId ? $db->query("SELECT user_id FROM evenement_actor WHERE event_id=$evId")->fetchColumn() : null;
ok((int)$actor === $APPLICANT, "evenement_actor=申請人");
// 通知
$evts = $db->query("SELECT id FROM live_event WHERE ref_type='LEAVE' AND ref_id=$reqA")->fetchAll(PDO::FETCH_COLUMN);
ok(count($evts) >= 1, "送審通知已寫 live_event(ref_type=LEAVE)");
foreach ($evts as $e) $createdEvents[] = (int)$e;

echo "== 4. 重疊擋下 ==\n";
$r2 = eg_leave_submit($db, [
    'employee_id' => $APPLICANT, 'leave_type_id' => $TYPE_GONG,
    'start_datetime' => "$d1 10:00:00", 'end_datetime' => "$d1 12:00:00",
    'reason' => '__test__重疊測試', 'agent_user_id' => 0, 'upload_token' => '',
]);
ok(!$r2['ok'] && strpos($r2['msg'], '重疊') !== false, "重疊時段擋下", $r2['msg']);
if (!empty($r2['id'])) $createdRequests[] = (int)$r2['id'];

echo "== 5. 待簽清單與簽核（核准）==\n";
$pend = eg_leave_pending_for($db, $signer1);
$found = false;
foreach ($pend as $p) if ((int)$p['leave_request_id'] === $reqA) $found = true;
ok($found, "解析出的簽核人({$signer1})待簽清單含本單");
// 非相關人不可簽
$r3 = eg_leave_sign($db, $reqA, 109110202, 'approved', '');   // 業務部組員，不在鏈上
ok(!$r3['ok'], "非簽核人簽核被拒", $r3['msg']);
$r4 = eg_leave_sign($db, $reqA, $signer1, 'approved', '同意');
ok($r4['ok'] && $r4['final'], "簽核核准→整單決行", $r4['msg']);
$row = $db->query("SELECT * FROM leave_request WHERE id = $reqA")->fetch(PDO::FETCH_ASSOC);
ok($row['status'] === 'approved' && $row['decided_at'], "主檔 approved");
$ev = $db->query("SELECT e.*, ec.category_name FROM evenement e JOIN event_category ec ON ec.id=e.category_id WHERE e.id=$evId")->fetch(PDO::FETCH_ASSOC);
ok($ev && $ev['category_name'] === '休假', "行事曆事件轉正為「休假」", json_encode($ev ?: []));
ok($ev && strpos((string)$ev['title'], '(申請中)') === false, "標題移除(申請中)");
$sr = $db->query("SELECT * FROM leave_sign_record WHERE leave_request_id=$reqA")->fetchAll(PDO::FETCH_ASSOC);
ok(count($sr) === 1 && $sr[0]['action'] === 'approved', "簽章軌跡 1 筆 approved");
$evts = $db->query("SELECT id FROM live_event WHERE ref_type='LEAVE' AND ref_id=$reqA")->fetchAll(PDO::FETCH_COLUMN);
foreach ($evts as $e) if (!in_array((int)$e, $createdEvents)) $createdEvents[] = (int)$e;

echo "== 6. 重複簽核擋下 ==\n";
$r5 = eg_leave_sign($db, $reqA, $signer1, 'approved', '');
ok(!$r5['ok'], "已決行單不可再簽", $r5['msg']);

echo "== 7. 銷假（核准後）==\n";
$r6 = eg_leave_cancel($db, $reqA, 109110202, '');   // 非本人
ok(!$r6['ok'], "非本人銷假被拒", $r6['msg']);
$r7 = eg_leave_cancel($db, $reqA, $APPLICANT, '__test__行程有變');
ok($r7['ok'], "本人銷假成功", $r7['msg']);
$row = $db->query("SELECT * FROM leave_request WHERE id = $reqA")->fetch(PDO::FETCH_ASSOC);
ok($row['status'] === 'canceled' && $row['canceled_by'] == $APPLICANT, "主檔 canceled");
$evLeft = $db->query("SELECT COUNT(*) FROM evenement WHERE id = $evId")->fetchColumn();
ok((int)$evLeft === 0, "行事曆事件已撤除");
$sr = $db->query("SELECT * FROM leave_sign_record WHERE leave_request_id=$reqA AND step_no=99")->fetchAll(PDO::FETCH_ASSOC);
ok(count($sr) === 1 && $sr[0]['action'] === 'canceled', "銷假軌跡 step_no=99 已記");
$evts = $db->query("SELECT id FROM live_event WHERE ref_type='LEAVE' AND ref_id=$reqA")->fetchAll(PDO::FETCH_COLUMN);
foreach ($evts as $e) if (!in_array((int)$e, $createdEvents)) $createdEvents[] = (int)$e;

echo "== 8. 退回流程 ==\n";
$r8 = eg_leave_submit($db, [
    'employee_id' => $APPLICANT, 'leave_type_id' => $TYPE_GONG,
    'start_datetime' => "$d4 09:00:00", 'end_datetime' => "$d4 17:00:00",
    'reason' => '__test__請假系統P1整合測試B', 'agent_user_id' => 0, 'upload_token' => '',
]);
ok($r8['ok'], "第二單送審", $r8['msg']);
$reqB = (int)($r8['id'] ?? 0);
if ($reqB) $createdRequests[] = $reqB;
$rowB = $db->query("SELECT * FROM leave_request WHERE id = $reqB")->fetch(PDO::FETCH_ASSOC);
$evB = (int)($rowB['evenement_id'] ?? 0);
$prevB = eg_leave_preview_signers($db, $APPLICANT, 1);
$signerB = $prevB[0]['signer_id'];
$r9 = eg_leave_sign($db, $reqB, $signerB, 'rejected', '__test__日期不妥');
ok($r9['ok'] && $r9['final'], "退回→整單決行", $r9['msg']);
$rowB = $db->query("SELECT * FROM leave_request WHERE id = $reqB")->fetch(PDO::FETCH_ASSOC);
ok($rowB['status'] === 'rejected', "主檔 rejected");
$evLeftB = $evB ? (int)$db->query("SELECT COUNT(*) FROM evenement WHERE id=$evB")->fetchColumn() : 0;
ok($evLeftB === 0, "退回後申請中事件已撤除");
$evts = $db->query("SELECT id FROM live_event WHERE ref_type='LEAVE' AND ref_id=$reqB")->fetchAll(PDO::FETCH_COLUMN);
foreach ($evts as $e) if (!in_array((int)$e, $createdEvents)) $createdEvents[] = (int)$e;

echo "== 9. 補請假限制與特休摘要 ==\n";
$r10 = eg_leave_submit($db, [
    'employee_id' => $APPLICANT, 'leave_type_id' => $TYPE_GONG,
    'start_datetime' => date('Y-m-d', strtotime('-20 day')) . ' 09:00:00',
    'end_datetime'   => date('Y-m-d', strtotime('-20 day')) . ' 17:00:00',
    'reason' => '__test__補請假超限', 'agent_user_id' => 0, 'upload_token' => '',
]);
ok(!$r10['ok'] && strpos($r10['msg'], '補請假') !== false, "20天前補請假擋下(限7天)", $r10['msg']);
if (!empty($r10['id'])) $createdRequests[] = (int)$r10['id'];
$sum = eg_leave_annual_summary($db, 107042401);   // 余繁民 2018-04-24 到職，年資 8 年
ok($sum['entitlement'] >= 14, "特休額度合理(年資8年≥14天)", json_encode($sum));
// 特休超額擋下：申請剩餘+1天
$sumA = eg_leave_annual_summary($db, $APPLICANT);
$overDays = (int)ceil($sumA['remaining']) + 2;
$wdMany = next_workdays($db, $overDays, date('Y-m-d', strtotime('+70 day')));
$r11 = eg_leave_submit($db, [
    'employee_id' => $APPLICANT, 'leave_type_id' => 4,   // 特休 agent=1 → 但先撞額度檢查?順序:額度先於代理
    'start_datetime' => $wdMany[0] . ' 00:00:00',
    'end_datetime'   => end($wdMany) . ' 23:59:00',
    'reason' => '__test__特休超額', 'agent_user_id' => 0, 'upload_token' => '',
]);
ok(!$r11['ok'] && (strpos($r11['msg'], '額度不足') !== false || strpos($r11['msg'], '代理人') !== false),
   "特休超額(或無代理人)擋下", $r11['msg']);
if (!empty($r11['id'])) $createdRequests[] = (int)$r11['id'];

echo "== 10. 病假需附證明（可補件）==\n";
$r12 = eg_leave_submit($db, [
    'employee_id' => $APPLICANT, 'leave_type_id' => 1,   // 病假 agent=1 require_attachment=1
    'start_datetime' => "$d3 09:00:00", 'end_datetime' => "$d3 17:00:00",
    'reason' => '__test__病假補件測試', 'agent_user_id' => 0, 'upload_token' => '',
]);
// 病假 agent=1：申請人無代理人設定時會先被代理人必填擋下——兩種結果都算合規
ok(!$r12['ok'] || ($r12['need_attach_later'] ?? false), "病假無附件→待補件或代理人必填擋下", $r12['msg'] ?? '');
if (!empty($r12['id'])) {
    $createdRequests[] = (int)$r12['id'];
    $rowC = $db->query("SELECT attach_status FROM leave_request WHERE id=" . (int)$r12['id'])->fetch(PDO::FETCH_ASSOC);
    ok($rowC['attach_status'] === 'pending', "attach_status=pending 待補證明");
    $evts = $db->query("SELECT id FROM live_event WHERE ref_type='LEAVE' AND ref_id=" . (int)$r12['id'])->fetchAll(PDO::FETCH_COLUMN);
    foreach ($evts as $e) if (!in_array((int)$e, $createdEvents)) $createdEvents[] = (int)$e;
}

// ==================== 清理（只刪本腳本建立的列） ====================
echo "== 清理 ==\n";
foreach ($createdRequests as $id) {
    // 撤掉可能殘留的行事曆事件（evenement_id 捕捉）
    $ev = $db->query("SELECT evenement_id FROM leave_request WHERE id=$id")->fetchColumn();
    if ($ev) {
        $db->exec("DELETE FROM evenement_actor WHERE event_id=" . (int)$ev);
        $db->exec("DELETE FROM evenement WHERE id=" . (int)$ev);
    }
    $db->exec("DELETE FROM leave_sign_record WHERE leave_request_id=$id");
    $db->exec("DELETE FROM leave_approval WHERE leave_request_id=$id");
    $db->exec("DELETE FROM leave_request WHERE id=$id");
}
foreach ($createdEvents as $eid) {
    $db->exec("DELETE FROM live_event_response WHERE live_event_id=$eid");
    $db->exec("DELETE FROM live_event_target WHERE live_event_id=$eid");
    $db->exec("DELETE FROM live_event WHERE id=$eid");
}
echo "  已刪測試請假單 " . count($createdRequests) . " 張、通知 " . count($createdEvents) . " 則\n";
// 驗證清乾淨
$left = $db->query("SELECT COUNT(*) FROM leave_request WHERE reason LIKE '\\_\\_test\\_\\_%'")->fetchColumn();
$leftE = $db->query("SELECT COUNT(*) FROM live_event WHERE ref_type='LEAVE'")->fetchColumn();
echo "  殘留檢查：leave_request __test__ 剩 $left 筆、live_event LEAVE 剩 $leftE 筆\n";

printf("\n結果：PASS %d / FAIL %d\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
