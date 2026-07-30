<?php
/**
 * 請假單年度切換測試（2026-07-30）
 *   · list 未帶 year → 預設今年，且回傳 year / years 供前端畫下拉
 *   · list year=all → 不限年度，筆數 >= 今年
 *   · list year=去年 → 只出去年的單
 *   · 年度下拉選項只套「範圍」條件（切到去年後仍看得到今年，否則切不回來）
 *   · annual_summary 帶 year → 特休摘要與各假別累積都跟著換年度
 *   · eg_leave_years_of 一定含今年
 *
 * 測試資料以 reason 前綴 __test_year__ 標記，只刪自己 lastInsertId 建立的列（testing_discipline）。
 */
mb_internal_encoding('UTF-8');
if (PHP_SAPI !== 'cli') exit('CLI only');

require_once 'C:/MAMP/htdocs/EGsystem/src/common/leave_lib.php';
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4", "EG-TS2024", "excell30367593",
              [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$UID = 107092601;   // 邱冠宏（一般組員，與其他請假測試同一人）
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
    $out = trim((string)shell_exec($cmd));
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['__raw' => $out];
}
// runner 與 test_leave_api.php 同一份（該檔會重建，這裡沒有時自行建立）
if (!is_file(__DIR__ . '/_api_runner.php')) {
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
}

$curY  = (int)date('Y');
$prevY = $curY - 1;
$oldY  = $curY - 3;   // 更早的一年，用來驗證「切到舊年度後選項不會塌掉」

// ── 建立測試資料：今年 1 筆、去年 2 筆、三年前 1 筆（都直接寫 leave_request，不走送審流程）──
$created = [];
$ins = $db->prepare(
    "INSERT INTO leave_request (employee_id, leave_type_id, start_datetime, end_datetime, reason,
                                status, total_hours, total_days, submit_time)
     VALUES (?, ?, ?, ?, ?, 'approved', ?, ?, NOW())");
function mk(PDO $db, PDOStatement $ins, int $uid, int $typeId, string $day, float $hrs, float $days, array &$created): int {
    $ins->execute([$uid, $typeId, "$day 09:00:00", "$day 17:00:00", '__test_year__ 年度篩選測試', $hrs, $days]);
    $id = (int)$db->lastInsertId();
    $created[] = $id;
    return $id;
}
try {
    mk($db, $ins, $UID, 2, "$curY-03-04",  8, 1, $created);   // 事假 今年
    mk($db, $ins, $UID, 2, "$prevY-03-04", 8, 1, $created);   // 事假 去年
    mk($db, $ins, $UID, 1, "$prevY-05-06", 5, 0.63, $created);// 病假 去年
    mk($db, $ins, $UID, 2, "$oldY-08-08",  8, 1, $created);   // 事假 三年前
} catch (Throwable $e) {
    echo "建立測試資料失敗：" . $e->getMessage() . "\n";
    exit(1);
}
echo "建立測試單：#" . implode(' #', $created) . "\n";

try {
    echo "== list：預設年度 ==\n";
    $r = call_api($UID, ['action' => 'list', 'scope' => 'mine', 'page' => 1, 'per' => 50]);
    ok(!empty($r['success']), 'list 未帶 year 仍成功', json_encode(array_slice($r, 0, 3)));
    ok((string)($r['year'] ?? '') === (string)$curY, "未帶 year 預設今年（{$curY}）", json_encode($r['year'] ?? null));
    $ids = array_map(fn($x) => (int)$x['id'], $r['rows'] ?? []);
    ok(in_array($created[0], $ids, true), '今年的單出現在預設年度列表');
    ok(!in_array($created[1], $ids, true), '去年的單不出現在預設年度列表');

    echo "== list：years 下拉選項 ==\n";
    $years = array_map('intval', $r['years'] ?? []);
    ok(in_array($curY, $years, true), "years 含今年（{$curY}）", json_encode($years));
    ok(in_array($prevY, $years, true), "years 含去年（{$prevY}）", json_encode($years));
    ok(in_array($oldY, $years, true), "years 含 $oldY", json_encode($years));

    echo "== list：切到去年 ==\n";
    $r2 = call_api($UID, ['action' => 'list', 'scope' => 'mine', 'page' => 1, 'per' => 50, 'year' => $prevY]);
    $ids2 = array_map(fn($x) => (int)$x['id'], $r2['rows'] ?? []);
    ok(in_array($created[1], $ids2, true) && in_array($created[2], $ids2, true), '去年的兩筆都出現');
    ok(!in_array($created[0], $ids2, true), '今年的單不出現在去年列表');
    $years2 = array_map('intval', $r2['years'] ?? []);
    ok(in_array($curY, $years2, true) && in_array($oldY, $years2, true),
       '切到去年後，下拉仍看得到其他年度（選項只套範圍條件）', json_encode($years2));

    echo "== list：全部年度 ==\n";
    $rAll = call_api($UID, ['action' => 'list', 'scope' => 'mine', 'page' => 1, 'per' => 50, 'year' => 'all']);
    $idsAll = array_map(fn($x) => (int)$x['id'], $rAll['rows'] ?? []);
    $allFound = true;
    foreach ($created as $cid) if (!in_array($cid, $idsAll, true)) $allFound = false;
    ok($allFound, 'year=all 四筆測試單全部出現', json_encode($idsAll));
    ok((int)$rAll['total'] >= (int)$r['total'], 'year=all 總筆數 >= 今年總筆數',
       $rAll['total'] . ' vs ' . $r['total']);

    echo "== list：年度＋狀態可同時套用 ==\n";
    $rMix = call_api($UID, ['action' => 'list', 'scope' => 'mine', 'page' => 1, 'per' => 50,
                            'year' => $prevY, 'status' => 'pending']);
    $idsMix = array_map(fn($x) => (int)$x['id'], $rMix['rows'] ?? []);
    ok(!in_array($created[1], $idsMix, true), '去年+審核中：已核准的測試單被狀態濾掉');

    echo "== list：年度亂帶時退回不篩選 ==\n";
    $rJunk = call_api($UID, ['action' => 'list', 'scope' => 'mine', 'page' => 1, 'per' => 50, 'year' => 'abc']);
    ok(!empty($rJunk['success']), 'year=abc 不會噴錯', json_encode(array_slice($rJunk, 0, 2)));

    echo "== annual_summary：跟著年度換 ==\n";
    $aCur  = call_api($UID, ['action' => 'annual_summary', 'year' => $curY]);
    $aPrev = call_api($UID, ['action' => 'annual_summary', 'year' => $prevY]);
    ok(!empty($aCur['success']) && !empty($aPrev['success']), 'annual_summary 兩個年度都成功');
    ok((int)($aPrev['year'] ?? 0) === $prevY, 'annual_summary 回傳所查年度', json_encode($aPrev['year'] ?? null));
    $nameOf = fn($x) => array_column($x['year_usage'] ?? [], 'label', 'leave_name');
    $uCur = $nameOf($aCur); $uPrev = $nameOf($aPrev);
    ok(isset($uCur['事假']), '今年累積含事假', json_encode($uCur));
    ok(isset($uPrev['事假']) && isset($uPrev['病假']), '去年累積含事假與病假', json_encode($uPrev));
    // 兩年度各自計算：API 回的內容要跟 lib 直接算的完全一致（不是把今年的結果重複給）
    $libCur  = array_column(eg_leave_year_usage($db, $UID, $curY),  'label', 'leave_name');
    $libPrev = array_column(eg_leave_year_usage($db, $UID, $prevY), 'label', 'leave_name');
    ok($uCur === $libCur,  'API 今年累積 == lib 今年累積', json_encode([$uCur, $libCur]));
    ok($uPrev === $libPrev, 'API 去年累積 == lib 去年累積', json_encode([$uPrev, $libPrev]));
    ok($uCur !== $uPrev, '今年與去年的累積內容不同（沒有共用同一份結果）', json_encode([$uCur, $uPrev]));

    echo "== annual_summary：年度亂帶退回今年 ==\n";
    $aJunk = call_api($UID, ['action' => 'annual_summary', 'year' => 12]);
    ok((int)($aJunk['year'] ?? 0) === $curY, 'year=12 退回今年', json_encode($aJunk['year'] ?? null));

    echo "== eg_leave_years_of ==\n";
    $ys = eg_leave_years_of($db, $UID);
    ok(in_array($curY, $ys, true), '一定含今年', json_encode($ys));
    ok(in_array($prevY, $ys, true) && in_array($oldY, $ys, true), '含有資料的其他年度', json_encode($ys));
    $sorted = $ys; rsort($sorted);
    ok($ys === $sorted, '由新到舊排序', json_encode($ys));
    $noData = eg_leave_years_of($db, -999);
    ok($noData === [$curY], '完全沒請假的人也回傳今年', json_encode($noData));

    echo "== eg_leave_fmt_amount 格式 ==\n";
    ok(eg_leave_fmt_amount(13, 8) === '1天+5小時', '13 小時 → 1天+5小時');
    ok(eg_leave_fmt_amount(16, 8) === '2天', '16 小時 → 2天（整天不顯示小時）');
    ok(eg_leave_fmt_amount(3.5, 8) === '3.5小時', '3.5 小時 → 3.5小時');
    ok(eg_leave_fmt_amount(3.0, 8) === '3小時', '小數尾 0 省略');
} finally {
    // ── 清理：只刪自己建立的列 ──
    $del = $db->prepare("DELETE FROM leave_request WHERE id = ? AND reason LIKE '__test_year__%'");
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
