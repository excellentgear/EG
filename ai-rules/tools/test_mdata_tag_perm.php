<?php
// 端對端測試：save_part 的料號標籤擁有權合併邏輯，透過真正的 save_part action 執行
// （不只是演算法本身，連同 _mdTagCan() 讀取角色功能碼的實際接線都一起驗證）。
chdir(__DIR__);
require_once '../../src/common/DBConnection.php';
$db = new DBConnection();
$pdo = $db->getPDO();

$RUNNER = 'C:/MAMP/bin/php/php8.3.1/php.exe';
$SCRIPT = __DIR__ . '/_md_api_runner.php';
$USER_A = 107092601; // 既有在職員工，暫掛測試角色（只給 mdata_tag_assign，不給 edit_others）
$OWNER_B = 1;         // 另一位「指派者」，借用現有帳號id當擁有者標記即可，不會真的改到他的任何資料
$PART_ID = 'TMDTAG' . substr((string)time(), -6);

function call_md3($RUNNER, $SCRIPT, $uid, array $post) {
    $b64 = base64_encode(json_encode($post, JSON_UNESCAPED_UNICODE));
    $cmd = sprintf('%s %s %d %s', escapeshellarg($RUNNER), escapeshellarg($SCRIPT), $uid, escapeshellarg($b64));
    $out = shell_exec($cmd . ' 2>&1');
    $out = preg_replace('/^\xEF\xBB\xBF/', '', $out);
    return [json_decode($out, true), $out];
}
$pass=0;$fail=0;
function check($name,$cond){ global $pass,$fail; if($cond){$pass++;echo "PASS: $name\n";} else {$fail++;echo "FAIL: $name\n";} }

$chkRole = $pdo->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.user_id=? AND r.module='master_data'");
$chkRole->execute([$USER_A]);
if ((int)$chkRole->fetchColumn() > 0) { echo "測試使用者目前已有 master_data 角色，為避免干擾不執行本測試\n"; exit(1); }

// ── setup：測試角色（只給 mdata_tag_assign）＋ legacy CDRU（讓 save_part 本身存得進去）
$roleCode = 'TEST_MDTAG_' . bin2hex(random_bytes(3));
$pdo->prepare("INSERT INTO roles (role_code, role_name, module, is_system) VALUES (?,?,'master_data',0)")->execute([$roleCode, '測試用-勿留']);
$roleId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO role_features (role_id, feature_code) VALUES (?, 'mdata_tag_assign')")->execute([$roleId]);
// 2026-10-07 起料號本體CRUD也改成角色制了，測標籤合併邏輯仍要先給 entity_edit 才存得進 save_part
$pdo->prepare("INSERT INTO role_features (role_id, feature_code) VALUES (?, 'mdata_entity_edit')")->execute([$roleId]);
$pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)")->execute([$USER_A, $roleId]);
$pdo->prepare("INSERT INTO user_module_permissions (user_id, module_code, permission, scope) VALUES (?, '76', 'CDRU', 'page')")->execute([$USER_A]);
$legacyPermId = (int)$pdo->lastInsertId();

// ── 直接建立測試料號（繞開 save_part 新增時的必填欄位檢查，單純測修改時的標籤合併邏輯）
$refCustQ = $pdo->query("SELECT customer_id FROM customer_list LIMIT 1"); $REF_CUST = $refCustQ->fetchColumn() ?: '.D001';
$pdo->prepare("INSERT INTO d_setting (D_Setting_Id, Type, Customer_Id, Created_By, Created_At) VALUES (?, 'N', ?, ?, NOW())")->execute([$PART_ID, $REF_CUST, (string)$OWNER_B]);
$dId = (int)$pdo->lastInsertId();
// 既有標籤：label_id=8（材質=SUS304），擁有者是 OWNER_B
$pdo->prepare("INSERT INTO item_label_map (d_id, label_id, input_value, created_by_id, created_by) VALUES (?, 8, 'SUS304', ?, 'ownerB')")->execute([$dId, $OWNER_B]);
echo "測試料號 d_id={$dId}（{$PART_ID}），role_id={$roleId}（僅授予 mdata_tag_assign），皆為暫時資料\n";

// ── userA 存檔：labels 送出只有全新的 label_id=18（BOSS審圖），完全沒有送 label_id=8
//    → 預期：label8（owner B）原樣保留；label18 新增成功、owner 記為 userA
list($r1, $raw1) = call_md3($RUNNER, $SCRIPT, $USER_A, [
    'action' => 'save_part', 'd_id' => $dId,
    'D_Setting_Id' => $PART_ID, 'Type' => 'N', 'Is_Assembly' => 0, 'Customer_Id' => $REF_CUST,
    'gears' => '[]', 'bom_children' => '[]', 'vendor_map' => '[]',
    'dedicated_part_map' => '[]', 'machine_map' => '[]', 'old_part_links' => '[]',
    'labels' => json_encode([['label_id'=>18,'input_value'=>'測試新標籤']]),
]);
check('①-0 save_part 呼叫成功', is_array($r1) && !empty($r1['success']));
if (!$r1 || empty($r1['success'])) echo "raw1: $raw1\n";

$tags = $pdo->prepare("SELECT label_id, input_value, created_by_id FROM item_label_map WHERE d_id=? ORDER BY label_id"); $tags->execute([$dId]);
$tagRows = $tags->fetchAll(PDO::FETCH_ASSOC);
$byLabel = [];
foreach ($tagRows as $t) { $byLabel[(int)$t['label_id']] = $t; }
check('①-1 label8（他人指派）即使沒送出也原樣保留', isset($byLabel[8]) && $byLabel[8]['input_value']==='SUS304');
check('①-2 label8 的擁有者仍是 OWNER_B（沒被改成userA）', isset($byLabel[8]) && (int)$byLabel[8]['created_by_id']===$OWNER_B);
check('①-3 label18 新增成功', isset($byLabel[18]));
check('①-4 label18 的擁有者記為userA', isset($byLabel[18]) && (int)$byLabel[18]['created_by_id']===$USER_A);

// ── userA 再存一次，這次試圖「修改」label8的值（他沒有edit_others權限）
list($r2) = call_md3($RUNNER, $SCRIPT, $USER_A, [
    'action' => 'save_part', 'd_id' => $dId,
    'D_Setting_Id' => $PART_ID, 'Type' => 'N', 'Is_Assembly' => 0, 'Customer_Id' => $REF_CUST,
    'gears' => '[]', 'bom_children' => '[]', 'vendor_map' => '[]',
    'dedicated_part_map' => '[]', 'machine_map' => '[]', 'old_part_links' => '[]',
    'labels' => json_encode([['label_id'=>8,'input_value'=>'被userA亂改'],['label_id'=>18,'input_value'=>'測試新標籤']]),
]);
check('②-0 save_part 呼叫成功', is_array($r2) && !empty($r2['success']));
$tags2 = $pdo->prepare("SELECT label_id, input_value FROM item_label_map WHERE d_id=? AND label_id=8"); $tags2->execute([$dId]);
$row2 = $tags2->fetch(PDO::FETCH_ASSOC);
check('②-1 無edit_others權限，label8的值沒被改動（仍是SUS304）', $row2 && $row2['input_value']==='SUS304');

// ── 補上 mdata_tag_edit_others 後，再試一次應該真的改得動
$pdo->prepare("INSERT INTO role_features (role_id, feature_code) VALUES (?, 'mdata_tag_edit_others')")->execute([$roleId]);
list($r3) = call_md3($RUNNER, $SCRIPT, $USER_A, [
    'action' => 'save_part', 'd_id' => $dId,
    'D_Setting_Id' => $PART_ID, 'Type' => 'N', 'Is_Assembly' => 0, 'Customer_Id' => $REF_CUST,
    'gears' => '[]', 'bom_children' => '[]', 'vendor_map' => '[]',
    'dedicated_part_map' => '[]', 'machine_map' => '[]', 'old_part_links' => '[]',
    'labels' => json_encode([['label_id'=>8,'input_value'=>'改成SUS316']]),
]);
$tags3 = $pdo->prepare("SELECT label_id, input_value FROM item_label_map WHERE d_id=?"); $tags3->execute([$dId]);
$rows3 = $tags3->fetchAll(PDO::FETCH_ASSOC);
$lbl8v = null; foreach ($rows3 as $r) if ((int)$r['label_id']===8) $lbl8v = $r['input_value'];
check('③-1 補上 edit_others 後，label8 真的改成功', $lbl8v === '改成SUS316');
check('③-2 補上 edit_others 存檔後，label18 因為沒送出而被移除', count($rows3)===1);

echo "\n==== 結果：PASS=$pass FAIL=$fail ====\n";

// ── teardown ──
$pdo->prepare("DELETE FROM item_label_map WHERE d_id=?")->execute([$dId]);
$pdo->prepare("DELETE FROM d_setting WHERE d_id=?")->execute([$dId]);
$pdo->prepare("DELETE FROM audit_log WHERE target_id=? OR target_id=?")->execute([$PART_ID, (string)$dId]);
$pdo->prepare("DELETE FROM user_roles WHERE user_id=? AND role_id=?")->execute([$USER_A, $roleId]);
$pdo->prepare("DELETE FROM role_features WHERE role_id=?")->execute([$roleId]);
$pdo->prepare("DELETE FROM roles WHERE role_id=?")->execute([$roleId]);
$pdo->prepare("DELETE FROM user_module_permissions WHERE id=?")->execute([$legacyPermId]);
echo "已清理：d_id={$dId}、role_id={$roleId}、legacy perm id={$legacyPermId}\n";
$chk = $pdo->prepare("SELECT COUNT(*) FROM d_setting WHERE d_id=?"); $chk->execute([$dId]);
$chk2 = $pdo->prepare("SELECT COUNT(*) FROM item_label_map WHERE d_id=?"); $chk2->execute([$dId]);
echo "清理後殘留：d_setting=" . $chk->fetchColumn() . " item_label_map=" . $chk2->fetchColumn() . "（應全為0）\n";
exit($fail>0?1:0);
