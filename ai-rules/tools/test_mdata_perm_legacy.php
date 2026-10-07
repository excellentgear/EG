<?php
// 補充測試：完全沒有被指派 master_data 角色的使用者，行為應與改版前完全相同
// （對帳單設定僅管理員可設、結帳/報價/收款/銀行帳戶則看舊式 CRUD 字母）。
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

// 給予舊式 CDRU（非 A）權限：代表「一般可編輯的使用者，但不是本頁管理員」
$pdo->prepare("INSERT INTO user_module_permissions (user_id, module_code, permission, scope) VALUES (?, '76', 'CDRU', 'page')")->execute([$TEST_UID]);
$legacyPermId = (int)$pdo->lastInsertId();

$r1 = call_md2($RUNNER, $SCRIPT, $TEST_UID, [
    'action' => 'save_customer', 'is_new' => 1,
    'customer_id' => $CUST_ID, 'customer' => '測試客戶勿留(舊規則)',
    'need_recon_stmt' => 1, 'recon_provide_by' => 'company', // 非admin，應被擋
    'settlement_mode' => 'EOM', 'settlement_day' => '', 'net_days' => '60', // CDRU可編輯，應成功
    'quote_method' => 'CIF', 'payment_method' => '支票',
    'bank_name' => '測試銀行', 'bank_branch' => '測試分行', 'bank_account' => '888888',
]);
check('①-0 新增客戶的 API 呼叫成功', is_array($r1) && !empty($r1['success']));

$row = $pdo->prepare("SELECT * FROM customer_list WHERE customer_id=?"); $row->execute([$CUST_ID]); $cust = $row->fetch(PDO::FETCH_ASSOC);
check('①-1 完全沒有master_data角色、非本頁管理員 → 對帳單設定依舊規則被擋下(need_recon_stmt仍是0)', $cust && (int)$cust['need_recon_stmt'] === 0);
check('①-2 結帳設定依舊規則(CDRU可編輯) → settlement_mode 真的改成EOM', $cust && $cust['settlement_mode'] === 'EOM');
check('①-3 報價方式依舊規則可編輯 → quote_method 真的改成CIF', $cust && $cust['quote_method'] === 'CIF');
check('①-4 收款方式依舊規則可編輯 → payment_method 真的改成支票', $cust && $cust['payment_method'] === '支票');
check('①-5 銀行帳戶依舊規則可編輯 → bank_name 真的存入', $cust && $cust['bank_name'] === '測試銀行');
check('①-6 月結天數依舊規則可編輯 → net_days 真的改成60', $cust && (int)$cust['net_days'] === 60);

echo "\n==== 結果：PASS=$pass FAIL=$fail ====\n";

$pdo->prepare("DELETE FROM customer_list WHERE customer_id=?")->execute([$CUST_ID]);
$pdo->prepare("DELETE FROM audit_log WHERE target_type='customer' AND target_id=?")->execute([$CUST_ID]);
$pdo->prepare("DELETE FROM user_module_permissions WHERE id=?")->execute([$legacyPermId]);
echo "已清理：customer_id={$CUST_ID}、legacy perm id={$legacyPermId}\n";
$chk = $pdo->prepare("SELECT COUNT(*) FROM customer_list WHERE customer_id=?"); $chk->execute([$CUST_ID]);
echo "清理後殘留：" . $chk->fetchColumn() . "（應為0）\n";
exit($fail > 0 ? 1 : 0);
