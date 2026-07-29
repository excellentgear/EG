<?php
/**
 * 請假系統：半小時單位 ＋ 排班連動（依固定班別自動帶出請假時間）驗證。
 * 測試紀律：不建立任何請假單，只讀排班；唯一寫入是暫時新增/移除一筆夜班排班（用 lastInsertId 刪回）。
 */
mb_internal_encoding('UTF-8');
if (PHP_SAPI !== 'cli') exit('CLI only');
require_once 'C:/MAMP/htdocs/EGsystem/src/common/leave_lib.php';
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4",
              "EG-TS2024", "excell30367593", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pass = 0; $fail = 0;
function ok(bool $c, string $n, string $note = '') {
    global $pass, $fail;
    if ($c) { $pass++; echo "  [PASS] $n\n"; } else { $fail++; echo "  [FAIL] $n $note\n"; }
}

// 取一位確實有日班排班的人
$row = $db->query(
    "SELECT a.user_id, a.work_date, t.start_time, t.end_time
     FROM roster_shift_assign a JOIN roster_shift_type t ON t.id = a.shift_type_id
     WHERE t.is_overnight = 0 ORDER BY a.work_date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$row) { echo "跳過：目前沒有任何固定班別排班資料\n"; exit(0); }
$UID  = (int)$row['user_id'];
$DATE = $row['work_date'];

echo "== 半小時為單位（時假）==\n";
// 用工作日避免被工作日過濾掉
$wd = $DATE;
for ($i = 0; $i < 20 && !eg_leave_is_workday($db, $wd); $i++) $wd = date('Y-m-d', strtotime($wd . ' +1 day'));
$cases = [
    ['09:00', '09:30', 0.5, '整半小時＝0.5 小時'],
    ['09:00', '10:00', 1.0, '一小時＝1 小時'],
    ['09:00', '11:30', 2.5, '2 小時 30 分＝2.5 小時'],
    ['09:00', '09:10', 0.5, '10 分鐘無條件進位為 0.5 小時'],
    ['09:00', '09:40', 1.0, '40 分鐘無條件進位為 1 小時'],
    ['09:00', '10:20', 1.5, '1 小時 20 分進位為 1.5 小時'],
];
foreach ($cases as [$s, $e, $exp, $name]) {
    $a = eg_leave_calc_amount($db, 'hour', "$wd $s:00", "$wd $e:00");
    ok(abs($a['hours'] - $exp) < 0.001, $name, '實際 ' . $a['hours']);
}
// 時數一律是 0.5 的倍數
$a = eg_leave_calc_amount($db, 'hour', "$wd 08:07:00", "$wd 16:53:00");
ok(fmod($a['hours'] * 2, 1) == 0, '任意分鐘數算出的時數必為 0.5 的倍數', (string)$a['hours']);

echo "== 排班連動：單日 ==\n";
$sh = eg_leave_roster_shift($db, $UID, $DATE);
ok($sh !== null, "查得到 {$UID} 在 {$DATE} 的排班", json_encode($sh));
ok($sh && $sh['start_datetime'] === $DATE . ' ' . substr($row['start_time'], 0, 5) . ':00',
   '起始時間＝班別上班時間', $sh['start_datetime'] ?? '');
ok($sh && $sh['end_datetime'] === $DATE . ' ' . substr($row['end_time'], 0, 5) . ':00',
   '結束時間＝班別下班時間（非跨夜同日）', $sh['end_datetime'] ?? '');

$rg = eg_leave_roster_range($db, $UID, $DATE);
ok($rg['start_datetime'] === $sh['start_datetime'] && $rg['end_datetime'] === $sh['end_datetime'],
   '單日整天請假的建議起訖＝該日班別起訖', json_encode([$rg['start_datetime'], $rg['end_datetime']]));
ok(empty($rg['missing']), '該日有排班時 missing 為空');

echo "== 排班連動：跨日 ==\n";
$d2 = date('Y-m-d', strtotime($DATE . ' -1 day'));
$rg2 = eg_leave_roster_range($db, $UID, $d2, $DATE);
ok(strpos($rg2['start_datetime'], $d2) === 0, '起日取較早那天', $rg2['start_datetime']);
ok(strpos($rg2['end_datetime'], $DATE) === 0, '迄日取較晚那天的下班時間', $rg2['end_datetime']);
ok(strtotime($rg2['end_datetime']) > strtotime($rg2['start_datetime']), '結束一定晚於開始');

echo "== 排班連動：查無排班時回退 ==\n";
$far = '2019-03-05';   // 遠早於排班資料範圍
$rg3 = eg_leave_roster_range($db, $UID, $far);
ok($rg3['start_shift'] === null, '查無排班時 start_shift 為 null');
ok(in_array($far, $rg3['missing'], true), 'missing 標出沒排班的日期', json_encode($rg3['missing']));
ok(strpos($rg3['start_datetime'], $far . ' 08:00') === 0, '回退為預設上班時間 08:00', $rg3['start_datetime']);
ok(strtotime($rg3['end_datetime']) > strtotime($rg3['start_datetime']), '回退時結束仍晚於開始', $rg3['end_datetime']);

echo "== 排班連動：跨夜班結束落到隔天 ==\n";
$night = $db->query("SELECT id, start_time, end_time FROM roster_shift_type WHERE is_overnight = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$tmpId = 0;
if ($night) {
    $tDate = '2019-03-06';   // 用不影響真實排班的遠期日期
    $st = $db->prepare("INSERT INTO roster_shift_assign (shift_type_id, user_id, work_date, created_at)
                        VALUES (?,?,?,NOW())");
    $st->execute([(int)$night['id'], $UID, $tDate]);
    $tmpId = (int)$db->lastInsertId();

    $ns = eg_leave_roster_shift($db, $UID, $tDate);
    ok($ns !== null && $ns['is_overnight'] === true, '跨夜班可查得且標記 is_overnight', json_encode($ns));
    $expEnd = date('Y-m-d', strtotime($tDate . ' +1 day')) . ' ' . substr($night['end_time'], 0, 5) . ':00';
    ok($ns && $ns['end_datetime'] === $expEnd, '跨夜班結束時間自動落到隔天',
       ($ns['end_datetime'] ?? '') . ' 期望 ' . $expEnd);
    ok($ns && strtotime($ns['end_datetime']) > strtotime($ns['start_datetime']), '跨夜班結束晚於開始');

    // 清理：只刪本腳本 lastInsertId 建立的那一列
    $db->prepare("DELETE FROM roster_shift_assign WHERE id = ?")->execute([$tmpId]);
    $left = (int)$db->query("SELECT COUNT(*) FROM roster_shift_assign WHERE id = $tmpId")->fetchColumn();
    ok($left === 0, '測試用跨夜排班已刪除（只刪自己建立的 id）');
} else {
    echo "  （無跨夜班別，略過此段）\n";
}

echo "== 前端欄位與規範 ==\n";
$src = file_get_contents('C:/MAMP/htdocs/EGsystem/views/ADM/leave_request.php');
$need = [
    '開始日期欄位 fDateFrom'   => 'id="fDateFrom"',
    '結束日期欄位 fDateTo'     => 'id="fDateTo"',
    '排班提示區 shiftHint'     => 'id="shiftHint"',
    // 時間欄改為只選時間（日期已在旁邊選好），半小時刻度
    '開始時間欄為 time 型別'   => 'type="time" class="form-control input-sm eg-inp" id="fTimeFrom" step="1800"',
    '結束時間欄為 time 型別'   => 'type="time" class="form-control input-sm eg-inp" id="fTimeTo" step="1800"',
    '日期欄限 4 碼年'          => 'id="fDateFrom" max="9999-12-31"',
    '日期與時間同一欄位組'     => 'display:flex;gap:6px;',
    '送出時組合日期＋時間'     => 'function startDT()',
    'applyShift 已定義'        => 'function applyShift',
    '重新帶入按鈕'             => 'applyShift(true)',
    '呼叫 roster_shift API'    => "action:'roster_shift'",
    '跨夜自動帶出結束日期'     => "$('#fDateTo').val(dPart(r.end_datetime)",
];
foreach ($need as $n => $needle) ok(strpos($src, $needle) !== false, $n);

printf("\n結果：PASS %d / FAIL %d\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
