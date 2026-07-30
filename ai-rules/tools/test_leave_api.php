<?php
/**
 * Leave_API HTTP 冒煙測試：模擬已登入 session 直接 include API，檢查各 action 回傳結構。
 * 不經 Apache（避免動到正式站），以 CLI 模擬 $_SESSION/$_REQUEST。
 */
mb_internal_encoding('UTF-8');
if (PHP_SAPI !== 'cli') exit('CLI only');

$UID = 107092601;   // 邱冠宏（一般組員）
$pass = 0; $fail = 0;
function ok(bool $c, string $n, string $note = '') {
    global $pass, $fail;
    if ($c) { $pass++; echo "  [PASS] $n\n"; } else { $fail++; echo "  [FAIL] $n $note\n"; }
}

function call_api(int $uid, array $req, array $post = []): array {
    // Windows 的 escapeshellarg 會把 JSON 的雙引號吃掉 → 一律 base64 傳遞
    $cmd = 'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe ' . escapeshellarg(__DIR__ . '/_api_runner.php')
         . ' ' . escapeshellarg((string)$uid)
         . ' ' . escapeshellarg(base64_encode(json_encode($req, JSON_UNESCAPED_UNICODE)))
         . ' ' . escapeshellarg(base64_encode(json_encode($post, JSON_UNESCAPED_UNICODE)));
    $out = trim((string)shell_exec($cmd));
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['__raw' => $out];
}

// runner：在子行程模擬 session 與請求後 include API
file_put_contents(__DIR__ . '/_api_runner.php', <<<'PHP'
<?php
// API 內部會自行 session_start()，這裡先開好 session 再塞值會觸發重複啟動 Notice → 濾掉 Notice
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

echo "== bootstrap ==\n";
$r = call_api($UID, ['action' => 'bootstrap']);
ok(!empty($r['success']), 'bootstrap 成功', json_encode(array_slice($r, 0, 3)));
ok(!empty($r['csrf']), '回傳 CSRF token');
// 假別筆數不寫死（管理員可自行新增假別），與 DB 實際筆數比對
$typeN = (int)(new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4",
                       "EG-TS2024", "excell30367593"))->query("SELECT COUNT(*) FROM leave_type")->fetchColumn();
ok(isset($r['leave_types']) && count($r['leave_types']) === $typeN,
   "假別筆數與 DB 一致（{$typeN} 筆）", (string)count($r['leave_types'] ?? []));
ok(isset($r['annual']) && array_key_exists('remaining', $r['annual']), '特休摘要含 remaining', json_encode($r['annual'] ?? []));
ok(isset($r['settings']['backdate_limit_days']), '設定含補請假天數');
$hasUnit = !empty($r['leave_types'][0]['unit_type']);
ok($hasUnit, '假別帶 unit_type 欄位');

echo "== preview ==\n";
// 找未來工作日
require_once 'C:/MAMP/htdocs/EGsystem/src/common/leave_lib.php';
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4", "EG-TS2024", "excell30367593",
              [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$d = date('Y-m-d', strtotime('+45 day'));
for ($i = 0; $i < 20 && !eg_leave_is_workday($db, $d); $i++) $d = date('Y-m-d', strtotime($d . ' +1 day'));
$r = call_api($UID, ['action' => 'preview', 'leave_type_id' => 6, 'start' => "$d 09:00:00", 'end' => "$d 17:00:00"]);
ok(!empty($r['success']), 'preview 成功', json_encode($r));
ok(isset($r['amount']['hours']) && $r['amount']['hours'] == 8, 'preview 時數 8 小時', json_encode($r['amount'] ?? []));
ok(!empty($r['signers']), 'preview 回傳簽核人預覽', json_encode($r['signers'] ?? []));

echo "== list / pending / annual ==\n";
$r = call_api($UID, ['action' => 'list', 'scope' => 'mine', 'page' => 1, 'per' => 10]);
ok(isset($r['success']) && $r['success'] && isset($r['rows']), 'list(mine) 成功', json_encode(array_slice($r, 0, 3)));
$r = call_api($UID, ['action' => 'list', 'scope' => 'all', 'page' => 1, 'per' => 10]);
ok(empty($r['success']), '一般使用者查 all 被擋', json_encode($r));
$r = call_api($UID, ['action' => 'pending_for_me']);
ok(!empty($r['success']) && isset($r['count']), 'pending_for_me 成功', json_encode($r));
$r = call_api($UID, ['action' => 'annual_summary', 'year' => date('Y')]);
ok(!empty($r['success']), 'annual_summary 成功', json_encode($r));

echo "== CSRF fail-closed ==\n";
$r = call_api($UID, ['action' => 'submit'], ['csrf' => 'bogus', 'leave_type_id' => 6]);
ok(empty($r['success']) && strpos((string)($r['message'] ?? ''), 'CSRF') !== false, '錯誤 CSRF 被擋', json_encode($r));
$r = call_api($UID, ['action' => 'sign'], ['csrf' => 'bogus', 'id' => 1, 'decision' => 'approve']);
ok(empty($r['success']) && strpos((string)($r['message'] ?? ''), 'CSRF') !== false, 'sign 錯誤 CSRF 被擋', json_encode($r));
$r = call_api($UID, ['action' => 'save_print_setting'], ['csrf' => 'bogus', 'header' => 'x']);
ok(empty($r['success']), '非管理員/錯 CSRF 改列印設定被擋', json_encode($r));

echo "== 無效 action ==\n";
$r = call_api($UID, ['action' => 'nope']);
ok(empty($r['success']), '無效 action 回錯誤', json_encode($r));

@unlink(__DIR__ . '/_api_runner.php');
printf("\n結果：PASS %d / FAIL %d\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
