<?php
// 2026-10-07：料號/客戶/廠商「本體」新增/編輯/刪除/狀態切換 + 齒輪規格編輯/刪除，
// 全面改成完全以角色為準（mdata_entity_add/edit/delete/status、mdata_gear_edit/delete）。
// 本測試逐步給測試角色加功能碼，驗證每加一項，對應動作才會從「被擋」變成「放行」，
// 其餘動作仍維持被擋（確認權限碼之間互相獨立，沒有一個碼就放行全部的情況）。
chdir(__DIR__);
require_once '../../src/common/DBConnection.php';
$db = new DBConnection();
$pdo = $db->getPDO();

$RUNNER = 'C:/MAMP/bin/php/php8.3.1/php.exe';
$SCRIPT = __DIR__ . '/_md_api_runner.php';
$UID = 107092601;
$STAMP = substr((string)time(), -6);
$PART_ID = 'TMDE' . $STAMP;
$CUST_ID = 'TME' . $STAMP;   // char(11) 上限，壓短一點
$MAKER_ID = 'TMEM' . $STAMP;

function call_md($RUNNER, $SCRIPT, $uid, array $post) {
    $b64 = base64_encode(json_encode($post, JSON_UNESCAPED_UNICODE));
    $cmd = sprintf('%s %s %d %s', escapeshellarg($RUNNER), escapeshellarg($SCRIPT), $uid, escapeshellarg($b64));
    $out = shell_exec($cmd . ' 2>&1');
    $out = preg_replace('/^\xEF\xBB\xBF/', '', $out);
    return json_decode($out, true);
}
$pass=0;$fail=0;
function check($name,$cond){ global $pass,$fail; if($cond){$pass++;echo "PASS: $name\n";} else {$fail++;echo "FAIL: $name\n";} }
function grant($pdo,$roleId,$code){ $pdo->prepare("INSERT IGNORE INTO role_features (role_id, feature_code) VALUES (?,?)")->execute([$roleId,$code]); }

$chkRole = $pdo->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.user_id=? AND r.module='master_data'");
$chkRole->execute([$UID]);
if ((int)$chkRole->fetchColumn() > 0) { echo "測試使用者目前已有 master_data 角色，為避免干擾不執行本測試\n"; exit(1); }

$refCustQ = $pdo->query("SELECT customer_id FROM customer_list LIMIT 1"); $REF_CUST = $refCustQ->fetchColumn() ?: '.D001';
$roleCode = 'TEST_MDENTITY_' . bin2hex(random_bytes(3));
$pdo->prepare("INSERT INTO roles (role_code, role_name, module, is_system) VALUES (?,?,'master_data',0)")->execute([$roleCode, '測試用-勿留']);
$roleId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)")->execute([$UID, $roleId]);
$pdo->prepare("INSERT INTO user_module_permissions (user_id, module_code, permission, scope) VALUES (?, '76', 'CDRU', 'page')")->execute([$UID]);
$legacyPermId = (int)$pdo->lastInsertId();
echo "測試角色 role_id={$roleId}（功能碼逐步追加），掛在既有在職員工 {$UID} 身上\n";

// ── 步驟①：完全沒有任何 entity/gear 功能碼（只有一個無關的 md_open）→ 全部動作應被擋 ──
grant($pdo, $roleId, 'md_open');
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'save_part','d_id'=>0,'D_Setting_Id'=>$PART_ID,'Type'=>'N','Customer_Id'=>$REF_CUST,'gears'=>'[]','bom_children'=>'[]','vendor_map'=>'[]','dedicated_part_map'=>'[]','machine_map'=>'[]','labels'=>json_encode([['label_id'=>1,'input_value'=>'100']])]);
check('①-1 無任何entity碼 → 新增料號被擋', is_array($r) && empty($r['success']));
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'save_customer','is_new'=>1,'customer_id'=>$CUST_ID,'customer'=>'測試']);
check('①-2 無任何entity碼 → 新增客戶被擋', is_array($r) && empty($r['success']));
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'save_maker','is_new'=>1,'maker_id_no'=>$MAKER_ID,'maker_id'=>'測試廠商']);
check('①-3 無任何entity碼 → 新增廠商被擋', is_array($r) && empty($r['success']));

// ── 步驟②：給 mdata_entity_add → 可以新增，但還不能編輯/刪除/狀態切換 ──
grant($pdo, $roleId, 'mdata_entity_add');
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'save_part','d_id'=>0,'D_Setting_Id'=>$PART_ID,'Type'=>'N','Customer_Id'=>$REF_CUST,'gears'=>'[]','bom_children'=>'[]','vendor_map'=>'[]','dedicated_part_map'=>'[]','machine_map'=>'[]','labels'=>json_encode([['label_id'=>1,'input_value'=>'100']])]);
check('②-1 有entity_add → 新增料號成功', is_array($r) && !empty($r['success']));
$dId = (int)($r['d_id'] ?? 0);
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'save_customer','is_new'=>1,'customer_id'=>$CUST_ID,'customer'=>'測試客戶勿留']);
check('②-2 有entity_add → 新增客戶成功', is_array($r) && !empty($r['success']));
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'save_maker','is_new'=>1,'maker_id_no'=>$MAKER_ID,'maker_id'=>'測試廠商勿留']);
check('②-3 有entity_add → 新增廠商成功', is_array($r) && !empty($r['success']));

check('②-4 料號真的建到DB裡', $dId > 0);
$chkC = $pdo->prepare("SELECT COUNT(*) FROM customer_list WHERE customer_id=?"); $chkC->execute([$CUST_ID]);
check('②-5 客戶真的建到DB裡', (int)$chkC->fetchColumn() === 1);
$chkM = $pdo->prepare("SELECT COUNT(*) FROM maker_list WHERE maker_id_no=?"); $chkM->execute([$MAKER_ID]);
check('②-6 廠商真的建到DB裡', (int)$chkM->fetchColumn() === 1);

// 還沒有 entity_edit：嘗試編輯剛建好的客戶名稱，應被擋
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'save_customer','is_new'=>0,'customer_id'=>$CUST_ID,'customer'=>'改過的名字']);
check('②-7 還沒有entity_edit → 修改客戶被擋', is_array($r) && empty($r['success']));
$row = $pdo->prepare("SELECT customer FROM customer_list WHERE customer_id=?"); $row->execute([$CUST_ID]); $cr = $row->fetch(PDO::FETCH_ASSOC);
check('②-8 客戶名稱確實沒被改動', $cr && $cr['customer'] === '測試客戶勿留');

// 還沒有 entity_status：切換客戶狀態應被擋
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'toggle_customer_status','customer_id'=>$CUST_ID]);
check('②-9 還沒有entity_status → 切換客戶狀態被擋', is_array($r) && empty($r['success']));

// 還沒有 entity_delete：刪除廠商應被擋
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'delete_maker','maker_id_no'=>$MAKER_ID]);
check('②-10 還沒有entity_delete → 刪除廠商被擋', is_array($r) && empty($r['success']));
$chkM2 = $pdo->prepare("SELECT COUNT(*) FROM maker_list WHERE maker_id_no=?"); $chkM2->execute([$MAKER_ID]);
check('②-11 廠商確實還在DB裡', (int)$chkM2->fetchColumn() === 1);

// ── 步驟③：補上 mdata_entity_edit → 編輯放行，刪除/狀態仍擋 ──
grant($pdo, $roleId, 'mdata_entity_edit');
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'save_customer','is_new'=>0,'customer_id'=>$CUST_ID,'customer'=>'改過的名字']);
check('③-1 補上entity_edit → 修改客戶成功', is_array($r) && !empty($r['success']));
$row = $pdo->prepare("SELECT customer FROM customer_list WHERE customer_id=?"); $row->execute([$CUST_ID]); $cr = $row->fetch(PDO::FETCH_ASSOC);
check('③-2 客戶名稱真的被改成功', $cr && $cr['customer'] === '改過的名字');

$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'toggle_customer_status','customer_id'=>$CUST_ID]);
check('③-3 entity_edit不等於entity_status → 切換狀態依然被擋', is_array($r) && empty($r['success']));

// ── 步驟④：補上 mdata_entity_status → 狀態切換放行 ──
grant($pdo, $roleId, 'mdata_entity_status');
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'toggle_customer_status','customer_id'=>$CUST_ID]);
check('④-1 補上entity_status → 切換客戶狀態成功', is_array($r) && !empty($r['success']));
$row = $pdo->prepare("SELECT is_inactive FROM customer_list WHERE customer_id=?"); $row->execute([$CUST_ID]); $cr = $row->fetch(PDO::FETCH_ASSOC);
check('④-2 客戶確實變成停用狀態', $cr && (int)$cr['is_inactive'] === 1);

// ── 步驟⑤：補上 mdata_entity_delete → 刪除放行 ──
grant($pdo, $roleId, 'mdata_entity_delete');
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'delete_maker','maker_id_no'=>$MAKER_ID]);
check('⑤-1 補上entity_delete → 刪除廠商成功', is_array($r) && !empty($r['success']));
$chkM3 = $pdo->prepare("SELECT COUNT(*) FROM maker_list WHERE maker_id_no=?"); $chkM3->execute([$MAKER_ID]);
check('⑤-2 廠商真的從DB消失', (int)$chkM3->fetchColumn() === 0);

// ── 步驟⑥：齒輪規格刪除——先插一筆測試齒輪規格列，驗證 mdata_gear_delete 獨立於其他entity碼 ──
$pdo->prepare("INSERT INTO d_setting_gear (d_setting_id, Module, Teeth, Created_By) VALUES (?,?,?,?)")->execute([$dId, 2.0, 20, (string)$UID]);
$gearId = (int)$pdo->lastInsertId();
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'delete_gear_row','gear_id'=>$gearId,'d_setting_id'=>$dId]);
check('⑥-1 還沒有gear_delete → 刪除齒輪規格列被擋（即使已有entity_delete）', is_array($r) && empty($r['success']));
$chkG = $pdo->prepare("SELECT COUNT(*) FROM d_setting_gear WHERE gear_id=?"); $chkG->execute([$gearId]);
check('⑥-2 齒輪規格列確實還在', (int)$chkG->fetchColumn() === 1);

grant($pdo, $roleId, 'mdata_gear_delete');
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'delete_gear_row','gear_id'=>$gearId,'d_setting_id'=>$dId]);
check('⑥-3 補上gear_delete → 刪除齒輪規格列成功', is_array($r) && !empty($r['success']));
$chkG2 = $pdo->prepare("SELECT COUNT(*) FROM d_setting_gear WHERE gear_id=?"); $chkG2->execute([$gearId]);
check('⑥-4 齒輪規格列真的被刪除', (int)$chkG2->fetchColumn() === 0);

// ── 步驟⑦：確認料號刪除也受 entity_delete 控管（已有，驗證一次） ──
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'delete_part','d_id'=>$dId]);
check('⑦-1 已有entity_delete → 刪除料號成功', is_array($r) && !empty($r['success']));
$chkP = $pdo->prepare("SELECT COUNT(*) FROM d_setting WHERE d_id=?"); $chkP->execute([$dId]);
check('⑦-2 料號真的被刪除', (int)$chkP->fetchColumn() === 0);

echo "\n==== 結果：PASS=$pass FAIL=$fail ====\n";

// ── teardown：全部明確用 id 清理 ──
$pdo->prepare("DELETE FROM d_setting_gear WHERE gear_id=?")->execute([$gearId]);
$pdo->prepare("DELETE FROM d_setting WHERE d_id=?")->execute([$dId]);
$pdo->prepare("DELETE FROM customer_list WHERE customer_id=?")->execute([$CUST_ID]);
$pdo->prepare("DELETE FROM maker_list WHERE maker_id_no=?")->execute([$MAKER_ID]);
$pdo->prepare("DELETE FROM audit_log WHERE target_id IN (?,?,?) OR (target_type='part' AND target_id=?)")->execute([$CUST_ID,$MAKER_ID,(string)$dId,$PART_ID]);
$pdo->prepare("DELETE FROM user_roles WHERE user_id=? AND role_id=?")->execute([$UID, $roleId]);
$pdo->prepare("DELETE FROM role_features WHERE role_id=?")->execute([$roleId]);
$pdo->prepare("DELETE FROM roles WHERE role_id=?")->execute([$roleId]);
$pdo->prepare("DELETE FROM user_module_permissions WHERE id=?")->execute([$legacyPermId]);
echo "已清理：d_id={$dId}、customer_id={$CUST_ID}、maker_id_no={$MAKER_ID}、role_id={$roleId}\n";
$cp = $pdo->prepare("SELECT COUNT(*) FROM d_setting WHERE d_id=?"); $cp->execute([$dId]);
$cc = $pdo->prepare("SELECT COUNT(*) FROM customer_list WHERE customer_id=?"); $cc->execute([$CUST_ID]);
$cm = $pdo->prepare("SELECT COUNT(*) FROM maker_list WHERE maker_id_no=?"); $cm->execute([$MAKER_ID]);
$cr2 = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE role_id=?"); $cr2->execute([$roleId]);
echo "清理後殘留：part=".$cp->fetchColumn()." customer=".$cc->fetchColumn()." maker=".$cm->fetchColumn()." role=".$cr2->fetchColumn()."（應全為0）\n";
exit($fail > 0 ? 1 : 0);
