<?php
/**
 * leave_management_api.php 擴充驗證：假別新欄位 CRUD、刪除防呆、請假系統設定讀寫。
 * 測試資料以 __test__ 前綴命名，清理只刪本腳本建立的列。
 */
mb_internal_encoding('UTF-8');
if (PHP_SAPI !== 'cli') exit('CLI only');
$pass = 0; $fail = 0;
function ok(bool $c, string $n, string $note='') {
    global $pass,$fail;
    if ($c) { $pass++; echo "  [PASS] $n\n"; } else { $fail++; echo "  [FAIL] $n $note\n"; }
}
$runner = __DIR__ . '/_hr_runner.php';
file_put_contents($runner, <<<'PHP'
<?php
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
$_SERVER['DOCUMENT_ROOT'] = 'C:/MAMP/htdocs';
$_SERVER['REQUEST_METHOD'] = $argv[4] ?? 'GET';
session_start();
$_SESSION['id'] = (int)$argv[1];
$_SESSION['userName'] = 'test';
$get  = json_decode(base64_decode($argv[2]), true) ?: [];
$post = json_decode(base64_decode($argv[3]), true) ?: [];
$_GET = $get; $_POST = $post; $_REQUEST = array_merge($get, $post);
ob_start();
include 'C:/MAMP/htdocs/EGsystem/views/ADM/leave_management_api.php';
echo ob_get_clean();
PHP);
function api(string $action, array $post = [], string $method = 'GET'): array {
    global $runner;
    $cmd = 'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe ' . escapeshellarg($runner) . ' 1 '
         . escapeshellarg(base64_encode(json_encode(['action' => $action])))
         . ' ' . escapeshellarg(base64_encode(json_encode($post)))
         . ' ' . escapeshellarg($method);
    $out = trim((string)shell_exec($cmd));
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['__raw' => $out];
}

$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4","EG-TS2024","excell30367593",
              [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$createdTypes = [];

echo "== 假別清單含新欄位 ==\n";
$r = api('get_leave_types');
ok(($r['status'] ?? '') === 'success', 'get_leave_types 成功', json_encode($r));
$first = $r['data'][0] ?? [];
ok(array_key_exists('unit_type', $first) && array_key_exists('require_attachment', $first)
   && array_key_exists('attach_min_days', $first) && array_key_exists('allow_attach_later', $first),
   '回傳含 unit_type/require_attachment/attach_min_days/allow_attach_later', json_encode($first));
$sick = null;
foreach ($r['data'] as $t) if ($t['leave_name'] === '病假') $sick = $t;
ok($sick && (int)$sick['require_attachment'] === 1 && (int)$sick['allow_attach_later'] === 1,
   '病假預設為需附證明且可補件', json_encode($sick ?: []));

echo "== 新增假別（帶新欄位）==\n";
$r = api('add_leave_type', ['name' => '__test__特別假', 'need_manager_sign' => 'on', 'max_level' => 1,
                            'unit_type' => 'day', 'require_attachment' => 'on', 'attach_min_days' => '3',
                            'allow_attach_later' => '0'], 'POST');
ok(($r['status'] ?? '') === 'success', '新增成功', json_encode($r));
$newId = (int)$db->query("SELECT id FROM leave_type WHERE leave_name='__test__特別假'")->fetchColumn();
if ($newId) $createdTypes[] = $newId;
$row = $db->query("SELECT * FROM leave_type WHERE id=$newId")->fetch(PDO::FETCH_ASSOC);
ok($row && $row['unit_type'] === 'day', '粒度存入 day', json_encode($row ?: []));
ok($row && (int)$row['require_attachment'] === 1 && (float)$row['attach_min_days'] == 3.0, '需證明+門檻3天');
ok($row && (int)$row['allow_attach_later'] === 0, '不允許補件（hidden 0 生效）');

echo "== 編輯假別 ==\n";
$r = api('update_leave_type', ['id' => $newId, 'name' => '__test__特別假', 'need_manager_sign' => 'on',
                               'max_level' => 2, 'unit_type' => 'halfday',
                               'allow_attach_later' => '0'], 'POST');   // 取消勾選 require_attachment
ok(($r['status'] ?? '') === 'success', '更新成功', json_encode($r));
$row = $db->query("SELECT * FROM leave_type WHERE id=$newId")->fetch(PDO::FETCH_ASSOC);
ok($row['unit_type'] === 'halfday' && (int)$row['require_attachment'] === 0,
   '粒度改 halfday、取消需附證明', json_encode($row));

echo "== 刪除防呆（使用中不可刪）==\n";
// 建一張引用此假別的請假單
$db->prepare("INSERT INTO leave_request (employee_id, leave_type_id, start_datetime, end_datetime, reason, status)
              VALUES (107092601, ?, '2030-01-02 09:00:00', '2030-01-02 17:00:00', '__test__防呆', 'canceled')")
   ->execute([$newId]);
$reqId = (int)$db->lastInsertId();
$r = api('delete_leave_type', ['id' => $newId], 'POST');
ok(($r['status'] ?? '') === 'error' && strpos($r['message'] ?? '', '使用中') !== false,
   '有請假單引用時刪除被擋', json_encode($r));
$db->exec("DELETE FROM leave_request WHERE id=$reqId");
$r = api('delete_leave_type', ['id' => $newId], 'POST');
ok(($r['status'] ?? '') === 'success', '無引用時可正常刪除', json_encode($r));
$left = (int)$db->query("SELECT COUNT(*) FROM leave_type WHERE id=$newId")->fetchColumn();
ok($left === 0, '假別已刪除');
if ($left === 0) $createdTypes = array_diff($createdTypes, [$newId]);

echo "== 請假系統設定讀寫 ==\n";
$r = api('get_leave_settings');
ok(($r['status'] ?? '') === 'success' && isset($r['data']['leave_backdate_limit_days']),
   'get_leave_settings 成功', json_encode(array_slice($r, 0, 2)));
ok(!empty($r['users']), '回傳最終裁決者候選人清單');
$origBackdate = $r['data']['leave_backdate_limit_days'];
$origBase     = $r['data']['leave_attach_base'];
$r = api('save_leave_settings', ['leave_backdate_limit_days' => '14',
                                 'leave_attach_base' => '__test__\\\\nas\\leave'], 'POST');
ok(($r['status'] ?? '') === 'success', 'save_leave_settings 成功', json_encode($r));
$v = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='leave_backdate_limit_days'")->fetchColumn();
ok($v === '14', '補請假天數已寫入', (string)$v);
// 負數應被夾為 0
api('save_leave_settings', ['leave_backdate_limit_days' => '-5'], 'POST');
$v = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='leave_backdate_limit_days'")->fetchColumn();
ok($v === '0', '負數被夾為 0', (string)$v);
// 還原
api('save_leave_settings', ['leave_backdate_limit_days' => $origBackdate,
                            'leave_attach_base' => $origBase], 'POST');
$v = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='leave_backdate_limit_days'")->fetchColumn();
$v2 = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='leave_attach_base'")->fetchColumn();
ok($v === $origBackdate && $v2 === $origBase, '設定已還原原值', "$v / $v2");

// 清理殘留
foreach ($createdTypes as $id) $db->exec("DELETE FROM leave_type WHERE id=" . (int)$id);
$leftT = (int)$db->query("SELECT COUNT(*) FROM leave_type WHERE leave_name LIKE '\\_\\_test\\_\\_%'")->fetchColumn();
$leftR = (int)$db->query("SELECT COUNT(*) FROM leave_request WHERE reason LIKE '\\_\\_test\\_\\_%'")->fetchColumn();
echo "  殘留檢查：測試假別 $leftT 筆、測試請假單 $leftR 筆\n";
@unlink($runner);
printf("\n結果：PASS %d / FAIL %d\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
