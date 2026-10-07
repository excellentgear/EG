<?php
// 2026-10-07 改版：使用者確認已對會用到本頁的人員指派完 master_data 角色，拍板停用
// 「完全沒被指派角色時暫時沿用舊式 CRUD 字母」這條過渡期回退規則。
// 本測試原本驗證「過渡期回退」，現在改驗證「回退已停用」：完全沒有 master_data 角色的
// 使用者，即使舊式權限字母是 CDRU（本來全部欄位可編輯），現在對帳單/結帳/報價/收款/
// 銀行帳戶一律被擋下（除非被指派角色且角色裡有勾該功能）。
chdir(__DIR__);
require_once '../../src/common/DBConnection.php';
$db = new DBConnection();
$pdo = $db->getPDO();

$RUNNER = 'C:/MAMP/bin/php/php8.3.1/php.exe';
$SCRIPT = __DIR__ . '/_md_api_runner.php';
$TEST_UID = 107092601;
$CUST_ID  = 'TMDL' . substr((string)time(), -6);

function call_md2($RUNNER, $SCRIPT, $uid, array $post) {
    $b64 = base64_encode(json_encode($post, JSON_UNESCAPED_UNICODE));
    $cmd = sprintf('%s %s %d %s', escapeshellarg($RUNNER), escapeshellarg($SCRIPT), $uid, escapeshellarg($b64));
    $out = shell_exec($cmd . ' 2>&1');
    $out = preg_replace('/^\xEF\xBB\xBF/', '', $out);
    return json_decode($out, true);
}
$pass=0;$fail=0;
function check($name,$cond){ global $pass,$fail; if($cond){$pass++;echo "PASS: $name\n";} else {$fail++;echo "FAIL: $name\n";} }

// 確認這個測試使用者目前完全沒有 master_data 角色（若有，先中止，不動到別人正在設定的東西）
$chkRole = $pdo->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.user_id=? AND r.module='master_data'");
$chkRole->execute([$TEST_UID]);
if ((int)$chkRole->fetchColumn() > 0) { echo "測試使用者目前已有 master_data 角色，為避免干擾不執行本測試\n"; exit(1); }

// 給予舊式 CDRU（非 A）權限：代表「一般可編輯的使用者，但不是本頁管理員」，
// 且刻意不給任何 master_data 角色——驗證回退規則已停用。
$pdo->prepare("INSERT INTO user_module_permissions (user_id, module_code, permission, scope) VALUES (?, '76', 'CDRU', 'page')")->execute([$TEST_UID]);
$legacyPermId = (int)$pdo->lastInsertId();

$r1 = call_md2($RUNNER, $SCRIPT, $TEST_UID, [
    'action' => 'save_customer', 'is_new' => 1,
    'customer_id' => $CUST_ID, 'customer' => '測試客戶勿留(回退停用)',
    'need_recon_stmt' => 1, 'recon_provide_by' => 'company',
    'settlement_mode' => 'EOM', 'settlement_day' => '', 'net_days' => '60',
    'quote_method' => 'CIF', 'payment_method' => '支票',
    'bank_name' => '測試銀行', 'bank_branch' => '測試分行', 'bank_account' => '888888',
]);
check('①-0 新增客戶的 API 呼叫成功（存檔本身不受此權限門擋）', is_array($r1) && !empty($r1['success']));

$row = $pdo->prepare("SELECT * FROM customer_list WHERE customer_id=?"); $row->execute([$CUST_ID]); $cust = $row->fetch(PDO::FETCH_ASSOC);
check('①-1 完全沒有master_data角色 → 對帳單設定被擋下(need_recon_stmt仍是0)', $cust && (int)$cust['need_recon_stmt'] === 0);
check('①-2 完全沒有master_data角色 → 結帳設定被擋下，settlement_mode退回預設FIXED（即使舊式CDRU本來可編輯）', $cust && $cust['settlement_mode'] === 'FIXED');
check('①-3 完全沒有master_data角色 → 報價方式被擋下，退回預設FOB', $cust && $cust['quote_method'] === 'FOB');
check('①-4 完全沒有master_data角色 → 收款方式被擋下，退回預設匯款', $cust && $cust['payment_method'] === '匯款');
check('①-5 完全沒有master_data角色 → 銀行帳戶被擋下，仍是空的', $cust && $cust['bank_name'] === '');
check('①-6 完全沒有master_data角色 → 月結天數被擋下，仍是NULL', $cust && $cust['net_days'] === null);

echo "\n==== 結果：PASS=$pass FAIL=$fail ====\n";

$pdo->prepare("DELETE FROM customer_list WHERE customer_id=?")->execute([$CUST_ID]);
$pdo->prepare("DELETE FROM audit_log WHERE target_type='customer' AND target_id=?")->execute([$CUST_ID]);
$pdo->prepare("DELETE FROM user_module_permissions WHERE id=?")->execute([$legacyPermId]);
echo "已清理：customer_id={$CUST_ID}、legacy perm id={$legacyPermId}\n";
$chk = $pdo->prepare("SELECT COUNT(*) FROM customer_list WHERE customer_id=?"); $chk->execute([$CUST_ID]);
echo "清理後殘留：" . $chk->fetchColumn() . "（應為0）\n";
exit($fail > 0 ? 1 : 0);
