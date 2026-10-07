<?php
// 主檔管理「維護設定角色化權限」端對端測試（真的透過 CLI include 執行 master_data_management.php
// 的 save_customer 動作，驗證 _mdPerm() 在實際 POST 路徑上確實生效，不只是演算法本身正確）。
// 可重複執行：每次用全新 role_code/customer_id，結束一律明確用 id 清理，不影響任何既有資料。
chdir(__DIR__);
require_once '../../src/common/DBConnection.php';
$db = new DBConnection();
$pdo = $db->getPDO();

$RUNNER = 'C:/MAMP/bin/php/php8.3.1/php.exe';
$SCRIPT = __DIR__ . '/_md_api_runner.php';
$TEST_UID = 107092601; // 既有在職員工，僅暫時多掛一個測試角色，測完整，完整還原
$CUST_ID  = 'TMDP' . substr((string)time(), -6); // customer_id 欄位長度有限(約char(11))，需控制在短字串

function call_md($RUNNER, $SCRIPT, $uid, array $post) {
    $b64 = base64_encode(json_encode($post, JSON_UNESCAPED_UNICODE));
    $cmd = sprintf('%s %s %d %s', escapeshellarg($RUNNER), escapeshellarg($SCRIPT), $uid, escapeshellarg($b64));
    $out = shell_exec($cmd . ' 2>&1');
    // 某個被 include 的檔案帶 UTF-8 BOM，json_decode 前先剝掉（測試環境的坑，不是程式錯）
    $out = preg_replace('/^\xEF\xBB\xBF/', '', $out);
    $json = json_decode($out, true);
    return [$json, $out];
}

$pass = 0; $fail = 0;
function check($name, $cond) { global $pass, $fail; if ($cond) { $pass++; echo "PASS: $name\n"; } else { $fail++; echo "FAIL: $name\n"; } }

// ── setup：建立一個只給 mdata_recon_edit、不給 mdata_settle_edit 的測試角色，掛給測試使用者 ──
$roleCode = 'TEST_MDPERM_' . bin2hex(random_bytes(3));
$pdo->prepare("INSERT INTO roles (role_code, role_name, module, is_system) VALUES (?,?,'master_data',0)")->execute([$roleCode, '測試用-勿留']);
$roleId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO role_features (role_id, feature_code) VALUES (?, 'mdata_recon_edit')")->execute([$roleId]);
$pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)")->execute([$TEST_UID, $roleId]);
// 給這個使用者在本頁完整的舊式 CRUD 權限，才能驗證「settle 即使legacy可編輯也被新角色擋下」
$pdo->prepare("INSERT INTO user_module_permissions (user_id, module_code, permission, scope) VALUES (?, '76', 'CDRU', 'page')")->execute([$TEST_UID]);
$legacyPermId = (int)$pdo->lastInsertId();
echo "測試角色 role_id={$roleId}（僅授予 mdata_recon_edit）、legacy CDRU perm id={$legacyPermId}，皆掛在既有在職員工 {$TEST_UID} 身上，測完立即還原\n";

// ── ① 新增測試客戶，同時嘗試設定 need_recon_stmt=1（應成功，因為角色有 mdata_recon_edit）
//     與 settlement_mode='EOM'（應被忽略退回預設 FIXED，因為角色沒有 mdata_settle_edit）
list($r1, $raw1) = call_md($RUNNER, $SCRIPT, $TEST_UID, [
    'action' => 'save_customer', 'is_new' => 1,
    'customer_id' => $CUST_ID, 'customer' => '測試客戶勿留',
    'need_recon_stmt' => 1, 'recon_provide_by' => 'company',
    'settlement_mode' => 'EOM', 'settlement_day' => '', 'net_days' => '99',
    'quote_method' => 'CIF', 'payment_method' => '支票',
    'bank_name' => '測試銀行', 'bank_branch' => '測試分行', 'bank_account' => '999999',
]);
check('①-0 新增客戶的 API 呼叫本身成功', is_array($r1) && !empty($r1['success']));
if (!$r1) { echo "原始輸出：$raw1\n"; }

$row = $pdo->prepare("SELECT * FROM customer_list WHERE customer_id=?"); $row->execute([$CUST_ID]); $cust = $row->fetch(PDO::FETCH_ASSOC);
check('①-1 有 mdata_recon_edit → need_recon_stmt 真的被設成 1', $cust && (int)$cust['need_recon_stmt'] === 1);
check('①-2 有 mdata_recon_edit → recon_provide_by 真的被設成 company', $cust && $cust['recon_provide_by'] === 'company');
check('①-3 沒有 mdata_settle_edit → settlement_mode 沒被改成 EOM，退回預設 FIXED', $cust && $cust['settlement_mode'] === 'FIXED');
check('①-4 沒有 mdata_settle_edit → net_days 沒被改成 99（退回 NULL）', $cust && $cust['net_days'] === null);
check('①-5 沒有 mdata_payterm_edit → payment_method 沒被改成支票（退回預設匯款）', $cust && $cust['payment_method'] === '匯款');
check('①-6 沒有 mdata_bank_edit → 銀行資料仍是空的', $cust && $cust['bank_name'] === '');
check('①-7 quote_method 同樣沒有授權 → 退回預設 FOB', $cust && $cust['quote_method'] === 'FOB');

// ── ② 再次存檔（修改），嘗試把 need_recon_stmt 關掉、settlement_mode 再試一次改成 VARIABLE
list($r2, $raw2) = call_md($RUNNER, $SCRIPT, $TEST_UID, [
    'action' => 'save_customer', 'is_new' => 0,
    'customer_id' => $CUST_ID, 'customer' => '測試客戶勿留',
    'need_recon_stmt' => 0,
    'settlement_mode' => 'VARIABLE',
]);
check('②-0 修改客戶的 API 呼叫本身成功', is_array($r2) && !empty($r2['success']));
$row2 = $pdo->prepare("SELECT * FROM customer_list WHERE customer_id=?"); $row2->execute([$CUST_ID]); $cust2 = $row2->fetch(PDO::FETCH_ASSOC);
check('②-1 有 mdata_recon_edit → 可以把 need_recon_stmt 關掉', $cust2 && (int)$cust2['need_recon_stmt'] === 0);
check('②-2 沒有 mdata_settle_edit → settlement_mode 依然是 FIXED（沒被改成 VARIABLE）', $cust2 && $cust2['settlement_mode'] === 'FIXED');

// ── ③ 驗證：同一支角色若補上 mdata_settle_edit，立即就能改得動（確認功能碼真的有連動，不是永遠擋死）
$pdo->prepare("INSERT INTO role_features (role_id, feature_code) VALUES (?, 'mdata_settle_edit')")->execute([$roleId]);
list($r3, $raw3) = call_md($RUNNER, $SCRIPT, $TEST_UID, [
    'action' => 'save_customer', 'is_new' => 0,
    'customer_id' => $CUST_ID, 'customer' => '測試客戶勿留',
    'settlement_mode' => 'VARIABLE',
]);
$row3 = $pdo->prepare("SELECT * FROM customer_list WHERE customer_id=?"); $row3->execute([$CUST_ID]); $cust3 = $row3->fetch(PDO::FETCH_ASSOC);
check('③-1 補上 mdata_settle_edit 後，settlement_mode 真的改成 VARIABLE 了', $cust3 && $cust3['settlement_mode'] === 'VARIABLE');

// ── ④ 驗證：audit_log 真的留下了這張客戶的新增/修改紀錄 ──
$auditQ = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE target_type='customer' AND target_id=?"); $auditQ->execute([$CUST_ID]);
check('④-1 customer 的新增/修改已留下 audit_log 紀錄', (int)$auditQ->fetchColumn() > 0);

echo "\n==== 結果：PASS=$pass FAIL=$fail ====\n";

// ── teardown：完整還原，一律用明確 id/role_id/customer_id 刪除 ──
$pdo->prepare("DELETE FROM customer_list WHERE customer_id=?")->execute([$CUST_ID]);
$pdo->prepare("DELETE FROM audit_log WHERE target_type='customer' AND target_id=?")->execute([$CUST_ID]);
$pdo->prepare("DELETE FROM user_roles WHERE user_id=? AND role_id=?")->execute([$TEST_UID, $roleId]);
$pdo->prepare("DELETE FROM role_features WHERE role_id=?")->execute([$roleId]);
$pdo->prepare("DELETE FROM roles WHERE role_id=?")->execute([$roleId]);
$pdo->prepare("DELETE FROM user_module_permissions WHERE id=?")->execute([$legacyPermId]);
echo "已清理：customer_id={$CUST_ID}、role_id={$roleId}、legacy perm id={$legacyPermId}\n";

// 驗證清理乾淨
$chk = $pdo->prepare("SELECT COUNT(*) FROM customer_list WHERE customer_id=?"); $chk->execute([$CUST_ID]);
$chk2 = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE role_id=?"); $chk2->execute([$roleId]);
$chk3 = $pdo->prepare("SELECT COUNT(*) FROM user_roles WHERE user_id=? AND role_id=?"); $chk3->execute([$TEST_UID, $roleId]);
echo "清理後殘留：customer=" . $chk->fetchColumn() . " role=" . $chk2->fetchColumn() . " user_roles=" . $chk3->fetchColumn() . "（應全為0）\n";

exit($fail > 0 ? 1 : 0);
