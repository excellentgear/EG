<?php
/**
 * 假別特殊規則測試（喪假／育嬰類）  2026-07-31
 *
 * 驗的是 eg_leave_rule_check() 這唯一守門處：必填、日期合理性、期限、年齡閘門、
 * 單次最少天數、每一事件／每一子女的累計上限，以及「不是這個假別的欄位不可以被存進去」。
 * 另有一段端到端：直接呼叫 eg_leave_submit()，確認前端就算被繞過也擋得下來。
 *
 * 測試資料以 reason 前綴 __test_rules__ 標記，只刪自己 lastInsertId 建立的列。
 */
mb_internal_encoding('UTF-8');
if (PHP_SAPI !== 'cli') exit('CLI only');

require_once 'C:/MAMP/htdocs/EGsystem/src/common/leave_lib.php';
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4", "EG-TS2024", "excell30367593",
              [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$UID = 107092601;   // 邱冠宏
$pass = 0; $fail = 0;
function ok(bool $c, string $n, string $note = '') {
    global $pass, $fail;
    if ($c) { $pass++; echo "  [PASS] $n\n"; } else { $fail++; echo "  [FAIL] $n $note\n"; }
}
function typeOf(PDO $db, string $name): array {
    $st = $db->prepare("SELECT * FROM leave_type WHERE leave_name = ? LIMIT 1");
    $st->execute([$name]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) { echo "找不到假別 $name\n"; exit(1); }
    return $t;
}
/** 呼叫規則檢查；$days/$hours 直接給，才能精準測邊界（不受該日期是否工作日影響） */
function chk(PDO $db, int $uid, array $type, string $s, string $e, float $days, array $extra, ?int $ex = null): array {
    return eg_leave_rule_check($db, $uid, $type, $s . ' 09:00:00', $e . ' 17:00:00',
                               ['days' => $days, 'hours' => $days * 8], $extra, $ex);
}

$BER  = typeOf($db, '喪假');
$PAR  = typeOf($db, '育嬰留停');
$PARH = typeOf($db, '育嬰假');
$SICK = typeOf($db, '病假');
$grades = eg_leave_rule_grades($db, true);
if (count($grades) < 3) { echo "喪假親等預設資料不足，請先跑 migrate_leave_rules.php\n"; exit(1); }
$G8 = $grades[0]; $G6 = $grades[1]; $G3 = $grades[2];
echo "假別：喪假#{$BER['id']}(deadline={$BER['rule_deadline_days']}) 育嬰留停#{$PAR['id']}(max={$PAR['rule_max_value']}{$PAR['rule_max_unit']},min={$PAR['rule_min_days']},age={$PAR['rule_child_age_years']}) 育嬰假#{$PARH['id']}\n";
echo "親等：{$G8['grade_name']}={$G8['max_days']}天 / {$G6['grade_name']}={$G6['max_days']}天 / {$G3['grade_name']}={$G3['max_days']}天\n";

$created = [];
$ins = $db->prepare(
    "INSERT INTO leave_request (employee_id, leave_type_id, start_datetime, end_datetime, reason, status,
                                total_hours, total_days, rel_grade_id, deceased_date, child_birthday, submit_time)
     VALUES (?,?,?,?, '__test_rules__ 規則測試', ?, ?, ?, ?, ?, ?, NOW())");
function mk(PDO $db, PDOStatement $ins, array $a, array &$created): int {
    $ins->execute($a);
    $id = (int)$db->lastInsertId();
    $created[] = $id;
    return $id;
}

try {
    /* 端到端要先做：後面會建一張跨 2025~2026 的育嬰留停測試單，
       那張單一存在，任何 2026 年的送審都會先被「時段重疊」擋下，測不到規則本身。 */
    echo "== 端到端：送審會用同一套規則擋下（前端被繞過也擋得住）==\n";
    $wd = date('Y-m-d', strtotime('+40 day'));
    for ($i = 0; $i < 20 && !eg_leave_is_workday($db, $wd); $i++) $wd = date('Y-m-d', strtotime($wd . ' +1 day'));
    $sub = eg_leave_submit($db, [
        'employee_id' => $UID, 'leave_type_id' => (int)$BER['id'],
        'start_datetime' => $wd . ' 09:00:00', 'end_datetime' => $wd . ' 17:00:00',
        'reason' => '__test_rules__ 端到端',
    ]);
    if (!empty($sub['id'])) $created[] = (int)$sub['id'];
    ok(!$sub['ok'] && strpos($sub['msg'], '亡故親屬關係') !== false,
       '直接呼叫 submit 沒帶親等 → 擋', $sub['msg']);
    $sub = eg_leave_submit($db, [
        'employee_id' => $UID, 'leave_type_id' => (int)$BER['id'],
        'start_datetime' => $wd . ' 09:00:00', 'end_datetime' => $wd . ' 17:00:00',
        'reason' => '__test_rules__ 端到端', 'rel_grade_id' => $G8['id'],
        'deceased_date' => date('Y-m-d', strtotime($wd . ' -200 day')),   // 距請假日已超過 100 日
    ]);
    if (!empty($sub['id'])) $created[] = (int)$sub['id'];
    ok(!$sub['ok'] && strpos($sub['msg'], '100 日內請畢') !== false,
       'submit 逾期喪假 → 擋', $sub['msg']);
    $sub = eg_leave_submit($db, [
        'employee_id' => $UID, 'leave_type_id' => (int)$PAR['id'],
        'start_datetime' => $wd . ' 09:00:00', 'end_datetime' => $wd . ' 17:00:00',
        'reason' => '__test_rules__ 端到端',
    ]);
    if (!empty($sub['id'])) $created[] = (int)$sub['id'];
    ok(!$sub['ok'] && strpos($sub['msg'], '子女出生日期') !== false,
       'submit 育嬰留停沒帶子女出生日 → 擋', $sub['msg']);

    echo "== 沒設規則的假別完全不受影響 ==\n";
    $r = chk($db, $UID, $SICK, '2026-08-03', '2026-08-04', 2, []);
    ok($r['ok'], '病假（rule_kind 空）直接放行', $r['msg']);
    ok($r['quota'] === null, '沒有規則就沒有額度資訊');

    echo "== 喪假：必填 ==\n";
    $r = chk($db, $UID, $BER, '2026-08-03', '2026-08-04', 2, []);
    ok(!$r['ok'] && strpos($r['msg'], '亡故親屬關係') !== false, '沒選親等被擋', $r['msg']);
    $r = chk($db, $UID, $BER, '2026-08-03', '2026-08-04', 2, ['rel_grade_id' => $G8['id']]);
    ok(!$r['ok'] && strpos($r['msg'], '死亡日期') !== false, '沒填死亡日期被擋', $r['msg']);

    echo "== 喪假：日期合理性 ==\n";
    $future = date('Y-m-d', strtotime('+3 day'));
    $r = chk($db, $UID, $BER, '2026-08-03', '2026-08-04', 2,
             ['rel_grade_id' => $G8['id'], 'deceased_date' => $future]);
    ok(!$r['ok'] && strpos($r['msg'], '未來') !== false, '死亡日期不可為未來', $r['msg']);
    $r = chk($db, $UID, $BER, '2026-06-20', '2026-06-21', 2,
             ['rel_grade_id' => $G8['id'], 'deceased_date' => '2026-07-01']);
    ok(!$r['ok'] && strpos($r['msg'], '不可早於死亡日期') !== false, '開始日不可早於死亡日', $r['msg']);

    echo "== 喪假：死亡日起 100 日內請畢 ==\n";
    $ext8 = ['rel_grade_id' => $G8['id'], 'deceased_date' => '2026-07-01'];
    // 100 日後 = 2026-10-09
    $r = chk($db, $UID, $BER, '2026-10-08', '2026-10-09', 2, $ext8);
    ok($r['ok'], '結束日剛好是第 100 天 → 過', $r['msg']);
    $r = chk($db, $UID, $BER, '2026-10-09', '2026-10-10', 2, $ext8);
    ok(!$r['ok'] && strpos($r['msg'], '100 日內請畢') !== false, '結束日超過第 100 天 → 擋', $r['msg']);

    echo "== 喪假：依親等的天數上限 ==\n";
    $r = chk($db, $UID, $BER, '2026-07-02', '2026-07-10', 8, $ext8);
    ok($r['ok'], '父母/配偶 剛好 8 天 → 過', $r['msg']);
    $r = chk($db, $UID, $BER, '2026-07-02', '2026-07-13', 9, $ext8);
    ok(!$r['ok'] && strpos($r['msg'], '超過') !== false, '父母/配偶 9 天 → 擋', $r['msg']);
    $ext3 = ['rel_grade_id' => $G3['id'], 'deceased_date' => '2026-07-01'];
    $r = chk($db, $UID, $BER, '2026-07-02', '2026-07-06', 4, $ext3);
    ok(!$r['ok'], '兄弟姊妹等 4 天 → 擋（上限 3 天）', $r['msg']);
    $r = chk($db, $UID, $BER, '2026-07-02', '2026-07-05', 3, $ext3);
    ok($r['ok'], '兄弟姊妹等 3 天 → 過', $r['msg']);
    $q = eg_leave_rule_quota($db, $UID, $BER, $ext3);
    ok($q && abs($q['cap'] - 3) < 0.01, '額度資訊的上限 = 該親等天數', json_encode($q));

    echo "== 喪假：同一次治喪跨多張單累計 ==\n";
    mk($db, $ins, [$UID, $BER['id'], '2026-07-02 09:00:00', '2026-07-07 17:00:00', 'approved',
                   40, 5, $G8['id'], '2026-07-01', null], $created);
    $q = eg_leave_rule_quota($db, $UID, $BER, $ext8);
    ok($q && abs($q['used'] - 5) < 0.01 && abs($q['remaining'] - 3) < 0.01,
       '已請 5 天 → 剩 3 天', json_encode($q));
    $r = chk($db, $UID, $BER, '2026-07-08', '2026-07-11', 4, $ext8);
    ok(!$r['ok'], '再請 4 天（5+4>8）→ 擋', $r['msg']);
    $r = chk($db, $UID, $BER, '2026-07-08', '2026-07-10', 3, $ext8);
    ok($r['ok'], '再請 3 天（5+3=8）→ 過', $r['msg']);
    // 不同死亡日＝不同事件，不該互相佔用（死亡日必須是過去，這裡用 7/15）
    $ext8b = ['rel_grade_id' => $G8['id'], 'deceased_date' => '2026-07-15'];
    $r = chk($db, $UID, $BER, '2026-07-16', '2026-07-25', 8, $ext8b);
    ok($r['ok'], '另一次治喪（不同死亡日）額度重新計算', $r['msg']);
    // 送審中的單也要佔用，否則連送多張就能繞過
    $pid = mk($db, $ins, [$UID, $BER['id'], '2026-07-16 09:00:00', '2026-07-19 17:00:00', 'pending',
                          24, 3, $G8['id'], '2026-07-15', null], $created);
    $q = eg_leave_rule_quota($db, $UID, $BER, $ext8b);
    ok($q && abs($q['used'] - 3) < 0.01, '審核中的單也計入已請', json_encode($q));
    // 修改自己那張單時要把自己排除
    $q = eg_leave_rule_quota($db, $UID, $BER, $ext8b, $pid);
    ok($q && abs($q['used']) < 0.01, '改自己這張單時把自己排除，不會跟自己相撞', json_encode($q));

    echo "== 育嬰留停：必填與日期合理性 ==\n";
    $CB = '2025-01-15';                       // 子女出生日；3 歲生日 = 2028-01-15
    $r = chk($db, $UID, $PAR, '2026-03-01', '2026-09-01', 120, []);
    ok(!$r['ok'] && strpos($r['msg'], '子女出生日期') !== false, '沒填子女出生日被擋', $r['msg']);
    $r = chk($db, $UID, $PAR, '2024-12-01', '2025-06-01', 120, ['child_birthday' => $CB]);
    ok(!$r['ok'] && strpos($r['msg'], '不可早於子女出生日') !== false, '開始日不可早於出生日', $r['msg']);
    $r = chk($db, $UID, $PAR, '2026-03-01', '2026-09-01', 120, ['child_birthday' => date('Y-m-d', strtotime('+2 day'))]);
    ok(!$r['ok'] && strpos($r['msg'], '未來') !== false, '出生日不可為未來', $r['msg']);

    echo "== 育嬰留停：子女滿 3 歲前 ==\n";
    $r = chk($db, $UID, $PAR, '2027-10-01', '2028-01-15', 70, ['child_birthday' => $CB]);
    ok($r['ok'], '結束日剛好是 3 歲生日當天 → 過', $r['msg']);
    $r = chk($db, $UID, $PAR, '2027-10-01', '2028-01-16', 70, ['child_birthday' => $CB]);
    ok(!$r['ok'] && strpos($r['msg'], '滿 3 歲前') !== false, '結束日超過 3 歲生日 → 擋', $r['msg']);

    echo "== 育嬰留停：單次不得少於 30 曆日 ==\n";
    $r = chk($db, $UID, $PAR, '2026-03-01', '2026-03-30', 20, ['child_birthday' => $CB]);
    ok($r['ok'], '3/1~3/30 = 30 曆日 → 過', $r['msg']);
    $r = chk($db, $UID, $PAR, '2026-03-01', '2026-03-29', 20, ['child_birthday' => $CB]);
    ok(!$r['ok'] && strpos($r['msg'], '不得少於') !== false, '3/1~3/29 = 29 曆日 → 擋', $r['msg']);
    ok(abs(eg_leave_rule_cal_days('2026-03-01 09:00:00', '2026-03-01 17:00:00') - 1) < 0.01,
       '同一天的曆日數 = 1');

    echo "== 育嬰留停：每一子女最長 2 年（以曆日累計）==\n";
    ok(abs(eg_leave_rule_cap_days(2, 'year') - 730) < 0.01, '2 年換算 730 曆日');
    // 先放一張 2025-02-01 ~ 2026-12-31（699 曆日）
    mk($db, $ins, [$UID, $PAR['id'], '2025-02-01 09:00:00', '2026-12-31 17:00:00', 'approved',
                   0, 0, null, null, $CB], $created);
    $q = eg_leave_rule_quota($db, $UID, $PAR, ['child_birthday' => $CB]);
    ok($q && abs($q['used'] - 699) < 0.01, '已用 699 曆日', json_encode($q));
    ok($q && $q['unit'] === 'year' && strpos($q['measure'], '曆日') !== false,
       '長假的累計以曆日衡量', json_encode($q));
    $r = chk($db, $UID, $PAR, '2027-01-05', '2027-03-05', 40, ['child_birthday' => $CB]);
    ok(!$r['ok'] && strpos($r['msg'], '每一子女上限') !== false,
       '699 + 60 > 730 → 擋', $r['msg']);
    $r = chk($db, $UID, $PAR, '2027-01-05', '2027-02-03', 20, ['child_birthday' => $CB]);
    ok($r['ok'], '699 + 30 = 729 ≤ 730 → 過', $r['msg']);
    // 不同子女各自歸戶
    $r = chk($db, $UID, $PAR, '2027-01-05', '2027-06-05', 100, ['child_birthday' => '2026-05-20']);
    ok($r['ok'], '另一名子女額度獨立計算', $r['msg']);

    echo "== 育嬰類：多子女合併計算是「提醒」不是「擋下」==\n";
    $r = chk($db, $UID, $PAR, '2027-01-05', '2027-06-05', 100, ['child_birthday' => '2026-05-20']);
    ok($r['ok'] && count($r['warns']) > 0, '有其他子女紀錄 → 放行但給提醒', json_encode($r['warns']));
    ok(strpos(implode('', $r['warns']), '合併計算') !== false, '提醒內容講到合併計算');

    echo "== 育嬰假（時假制，與育嬰留停共用同一套規則）==\n";
    ok($PARH['rule_kind'] === 'parental', '育嬰假的 rule_kind = parental');
    $r = chk($db, $UID, $PARH, '2028-02-01', '2028-02-01', 1, ['child_birthday' => $CB]);
    ok(!$r['ok'] && strpos($r['msg'], '滿 3 歲前') !== false, '育嬰假一樣受 3 歲閘門限制', $r['msg']);
    $r = chk($db, $UID, $PARH, '2026-03-02', '2026-03-02', 1, ['child_birthday' => $CB]);
    ok($r['ok'], '育嬰假 3 歲前單日 → 過（未設上限、未設單次最少天數）', $r['msg']);
    ok(eg_leave_rule_quota($db, $UID, $PARH, ['child_birthday' => $CB]) === null,
       '育嬰假未設上限 → 沒有額度限制');

    echo "== 換假別時不該存的欄位要清成 null ==\n";
    $extAll = ['rel_grade_id' => $G8['id'], 'deceased_date' => '2026-07-01', 'child_birthday' => $CB];
    $s1 = eg_leave_rule_extra_store($BER, $extAll);
    ok($s1['rel_grade_id'] == $G8['id'] && $s1['deceased_date'] === '2026-07-01' && $s1['child_birthday'] === null,
       '喪假只存親等與死亡日', json_encode($s1));
    $s2 = eg_leave_rule_extra_store($PAR, $extAll);
    ok($s2['child_birthday'] === $CB && $s2['rel_grade_id'] === null && $s2['deceased_date'] === null,
       '育嬰類只存子女出生日', json_encode($s2));
    $s3 = eg_leave_rule_extra_store($SICK, $extAll);
    ok($s3['rel_grade_id'] === null && $s3['deceased_date'] === null && $s3['child_birthday'] === null,
       '一般假別三個欄位都清空（不然改過假別的舊值會留在單上）', json_encode($s3));
    $e0 = eg_leave_rule_extra_in(['rel_grade_id' => '0', 'deceased_date' => '', 'child_birthday' => 'xxx']);
    ok($e0['rel_grade_id'] === null && $e0['deceased_date'] === null && $e0['child_birthday'] === null,
       '空字串／無效日期一律轉 null（不會把 "" 寫進 DATE 欄位）', json_encode($e0));
} finally {
    $del = $db->prepare("DELETE FROM leave_request WHERE id = ? AND reason LIKE '__test_rules__%'");
    foreach ($created as $cid) $del->execute([$cid]);
    $left = 0;
    if ($created) {
        $in = implode(',', array_map('intval', $created));
        $left = (int)$db->query("SELECT COUNT(*) FROM leave_request WHERE id IN ($in)")->fetchColumn();
    }
    ok($left === 0, '測試單已清除', "殘留 $left 筆");
}

echo "\n結果：PASS $pass / FAIL $fail\n";
exit($fail > 0 ? 1 : 0);
