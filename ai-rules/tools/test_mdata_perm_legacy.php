<?php
// 2026-10-07（二次改版）：使用者確認「料號/客戶/廠商本體的新增/修改/刪除/狀態切換」也
// 一併改成完全以角色為準，CDRU 字母全面停用（不再只是維護設定子欄位被擋，而是連
// save_customer/save_part 這個動作本身，沒有 mdata_entity_add/edit 角色功能就直接被拒絕）。
// 本測試驗證：完全沒有 master_data 角色的使用者，即使舊式權限字母是 CDRU（本來全部
// 可編輯），現在連「新增客戶」「修改客戶」這種最基本的動作都會被整個擋下，不是只擋
// 對帳單/結帳等子欄位。
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

// 給予舊式 CDRU（非 A）權限：代表「以前一般可編輯的使用者，但不是本頁管理員」，
// 且刻意不給任何 master_data 角色——驗證回退規則已停用、本體CRUD也一併擋下。
$pdo->prepare("INSERT INTO user_module_permissions (user_id, module_code, permission, scope) VALUES (?, '76', 'CDRU', 'page')")->execute([$TEST_UID]);
$legacyPermId = (int)$pdo->lastInsertId();

// ① 嘗試新增客戶：連這個動作本身都應該被擋下（不是存檔成功、只是子欄位被忽略）
$r1 = call_md2($RUNNER, $SCRIPT, $TEST_UID, [
    'action' => 'save_customer', 'is_new' => 1,
    'customer_id' => $CUST_ID, 'customer' => '測試客戶勿留(應被擋)',
]);
check('①-1 完全沒有master_data角色 → 新增客戶這個動作本身被整個拒絕（即使舊式CDRU本來可以）', is_array($r1) && empty($r1['success']));
$chk1 = $pdo->prepare("SELECT COUNT(*) FROM customer_list WHERE customer_id=?"); $chk1->execute([$CUST_ID]);
check('①-2 資料庫裡真的沒有被建立這筆客戶', (int)$chk1->fetchColumn() === 0);

// ② 由管理員先建好一筆測試客戶（繞開API，純粹測試準備），再用role-less使用者嘗試修改
$pdo->prepare("INSERT INTO customer_list (customer_id, customer, settlement_mode, Created_By, Created_At) VALUES (?,?,?,?,NOW())")
    ->execute([$CUST_ID, '測試客戶勿留(原始)', 'FIXED', 1]);
$r2 = call_md2($RUNNER, $SCRIPT, $TEST_UID, [
    'action' => 'save_customer', 'is_new' => 0,
    'customer_id' => $CUST_ID, 'customer' => '測試客戶勿留(被改過的名稱)',
]);
check('②-1 完全沒有master_data角色 → 修改既有客戶這個動作本身被整個拒絕', is_array($r2) && empty($r2['success']));
$row2 = $pdo->prepare("SELECT customer FROM customer_list WHERE customer_id=?"); $row2->execute([$CUST_ID]); $cust2 = $row2->fetch(PDO::FETCH_ASSOC);
check('②-2 客戶名稱完全沒被改動（仍是原始值）', $cust2 && $cust2['customer'] === '測試客戶勿留(原始)');

// ③ 切換客戶停用狀態：也應被擋
$r3 = call_md2($RUNNER, $SCRIPT, $TEST_UID, ['action' => 'toggle_customer_status', 'customer_id' => $CUST_ID]);
check('③-1 完全沒有master_data角色 → 切換客戶狀態被擋下', is_array($r3) && empty($r3['success']));
$row3 = $pdo->prepare("SELECT is_inactive FROM customer_list WHERE customer_id=?"); $row3->execute([$CUST_ID]); $cust3 = $row3->fetch(PDO::FETCH_ASSOC);
check('③-2 停用狀態沒被改動（仍是啟用）', $cust3 && (int)$cust3['is_inactive'] === 0);

echo "\n==== 結果：PASS=$pass FAIL=$fail ====\n";

$pdo->prepare("DELETE FROM customer_list WHERE customer_id=?")->execute([$CUST_ID]);
$pdo->prepare("DELETE FROM audit_log WHERE target_type='customer' AND target_id=?")->execute([$CUST_ID]);
$pdo->prepare("DELETE FROM user_module_permissions WHERE id=?")->execute([$legacyPermId]);
echo "已清理：customer_id={$CUST_ID}、legacy perm id={$legacyPermId}\n";
$chk = $pdo->prepare("SELECT COUNT(*) FROM customer_list WHERE customer_id=?"); $chk->execute([$CUST_ID]);
echo "清理後殘留：" . $chk->fetchColumn() . "（應為0）\n";
exit($fail > 0 ? 1 : 0);
