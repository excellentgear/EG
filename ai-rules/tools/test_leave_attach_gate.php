<?php
/**
 * 請假系統：只有「需附證明文件」的假別才提供上傳。
 * 驗證前端區塊會依假別顯示/隱藏，以及後端 API 對未設定的假別一律擋下（防繞過畫面直打 API）。
 * 不建立請假單；只讀假別設定。
 */
mb_internal_encoding('UTF-8');
if (PHP_SAPI !== 'cli') exit('CLI only');
$pass = 0; $fail = 0;
function ok(bool $c, string $n, string $note = '') {
    global $pass, $fail;
    if ($c) { $pass++; echo "  [PASS] $n\n"; } else { $fail++; echo "  [FAIL] $n $note\n"; }
}

$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4",
              "EG-TS2024", "excell30367593", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// ── 後端：以子行程呼叫 API（Windows escapeshellarg 會吃掉 JSON 引號，故用 base64）──
// runner 支援固定 session id，讓「先 bootstrap 取 CSRF」與「再 attach_upload」共用同一個 session
// （API 每個 action 結尾都 exit，故一個 action 一個進程）
$runner = __DIR__ . '/_att_runner.php';
file_put_contents($runner, <<<'PHP'
<?php
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
$_SERVER['DOCUMENT_ROOT'] = 'C:/MAMP/htdocs';
$_SERVER['REQUEST_METHOD'] = 'POST';
session_id($argv[3]);          // 固定 session id
session_start();
$_SESSION['id'] = (int)$argv[1];
$_SESSION['userName'] = 'test';
$post = json_decode(base64_decode($argv[2]), true) ?: [];
$_GET = $post; $_POST = $post; $_REQUEST = $post;
// 模擬 multipart 上傳的檔案（用 is_uploaded_file 之外的路徑：move_uploaded_file 會失敗，
// 但假別守門在寫檔之前，正好可驗證「該擋的有擋、該過的有過到寫檔階段」）
if (!empty($post['__with_file'])) {
    $tmp = tempnam(sys_get_temp_dir(), 'lv');
    file_put_contents($tmp, 'x');
    $_FILES['file'] = ['name' => 'test.pdf', 'type' => 'application/pdf',
                       'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 1];
}
ob_start();
include 'C:/MAMP/htdocs/EGsystem/src/store/Leave_API.php';
echo ob_get_clean();
PHP);
$SID = 'lvattgate' . getmypid();
function api(int $uid, array $post): array {
    global $runner, $SID;
    $cmd = 'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe ' . escapeshellarg($runner) . ' ' . escapeshellarg((string)$uid)
         . ' ' . escapeshellarg(base64_encode(json_encode($post, JSON_UNESCAPED_UNICODE)))
         . ' ' . escapeshellarg($SID);
    $out = trim((string)shell_exec($cmd));
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['__raw' => $out];
}

$UID = 107092601;
// 取一個「需附證明」與一個「不需附證明」的假別
$needType = $db->query("SELECT id, leave_name FROM leave_type WHERE require_attachment = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$freeType = $db->query("SELECT id, leave_name FROM leave_type WHERE require_attachment = 0 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ok($needType !== false, '有設定需附證明的假別可測（預期：病假）', json_encode($needType ?: []));
ok($freeType !== false, '有未設定需附證明的假別可測', json_encode($freeType ?: []));

echo "== 後端守門（帶有效 CSRF 的端到端驗證）==\n";
$boot = api($UID, ['action' => 'bootstrap']);
$csrf = $boot['csrf'] ?? '';
ok($csrf !== '', 'bootstrap 取得 CSRF（同一 session 供後續使用）', json_encode(array_slice($boot, 0, 2)));

// 1) 免證明的假別 → 必須被擋，且訊息要指出是「未設定需附證明」
$r = api($UID, ['action' => 'attach_upload', 'csrf' => $csrf, 'upload_token' => str_repeat('a', 32),
                'leave_type_id' => (int)$freeType['id'], '__with_file' => 1]);
ok(empty($r['success']) && strpos((string)($r['message'] ?? ''), '未設定需附證明文件') !== false,
   "免證明假別（{$freeType['leave_name']}）上傳被擋，且訊息正確", json_encode($r));

// 2) 需證明的假別 → 不可被假別檢查擋下（應通過守門，往後才因附件目錄/搬檔等環境條件而止）
$r2 = api($UID, ['action' => 'attach_upload', 'csrf' => $csrf, 'upload_token' => str_repeat('b', 32),
                 'leave_type_id' => (int)$needType['id'], '__with_file' => 1]);
$blockedByType = strpos((string)($r2['message'] ?? ''), '未設定需附證明文件') !== false;
ok(!$blockedByType, "需證明假別（{$needType['leave_name']}）不會被假別守門擋下", json_encode($r2));

// 3) 未帶假別 → 也要擋（防止漏傳參數就繞過）
$r3 = api($UID, ['action' => 'attach_upload', 'csrf' => $csrf, 'upload_token' => str_repeat('c', 32),
                 '__with_file' => 1]);
ok(empty($r3['success']), '未帶 leave_type_id 一律擋下', json_encode($r3));

// 4) CSRF 仍是第一道關
$r4 = api($UID, ['action' => 'attach_upload', 'csrf' => 'bogus', 'upload_token' => str_repeat('d', 32),
                 'leave_type_id' => (int)$needType['id'], '__with_file' => 1]);
ok(empty($r4['success']) && strpos((string)($r4['message'] ?? ''), 'CSRF') !== false,
   'CSRF 無效仍優先擋下（fail-closed）', json_encode($r4));

// 確認整段測試沒有真的寫進任何附件列
$leftAtt = (int)$db->query("SELECT COUNT(*) FROM leave_attachment WHERE upload_token IN "
    . "('" . str_repeat('a', 32) . "','" . str_repeat('b', 32) . "','" . str_repeat('c', 32) . "','" . str_repeat('d', 32) . "')")->fetchColumn();
ok($leftAtt === 0, '測試未殘留任何 leave_attachment 資料', (string)$leftAtt);

echo "== API 原始碼含守門邏輯 ==\n";
$api = file_get_contents('C:/MAMP/htdocs/EGsystem/src/store/Leave_API.php');
ok(strpos($api, 'assertNeedAttach') !== false, 'attach_upload 有 assertNeedAttach 守門');
ok(preg_match('/assertNeedAttach\(\(int\)\$req\[.leave_type_id.\]\)/', $api) === 1, '補件路徑用單據的假別檢查');
ok(strpos($api, "assertNeedAttach((int)(\$_POST['leave_type_id'] ?? 0))") !== false, '新增暫存路徑用送來的假別檢查');
ok(strpos($api, '未設定需附證明文件，不提供上傳') !== false, '擋下時有明確訊息');

echo "== 前端：上傳區塊依假別顯示/隱藏 ==\n";
$src = file_get_contents('C:/MAMP/htdocs/EGsystem/views/ADM/leave_request.php');
$need = [
    '上傳區塊有 id 可切換'        => 'id="attachBlock"',
    '預設隱藏'                    => 'id="attachBlock" style="display:none;"',
    '依假別切換顯示'              => "$('#attachBlock').toggle(needAtt)",
    '未選假別時隱藏'              => "$('#attachBlock').hide()",
    '切到免證明假別清空暫存狀態'  => "$('#tempList').empty()",
    '上傳時帶假別供後端驗證'      => "fd.append('leave_type_id', tid)",
    '未選假別不給上傳'            => '請先選擇假別',
    '詳情頁依假別決定是否顯示'    => 'const needAtt = (+o.require_attachment === 1)',
    '詳情頁補上傳需 needAtt'      => 'if(needAtt && String(o.employee_id) === String(ME.id)',
    '已有附件仍會列出'            => 'if(needAtt || hasAtt)',
];
foreach ($need as $n => $needle) ok(strpos($src, $needle) !== false, $n);

@unlink($runner);
printf("\n結果：PASS %d / FAIL %d\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
