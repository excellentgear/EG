<?php
// 2026-10-07（三次改版）：使用者看了角色設定畫面後要求「料號／客戶／廠商本體操作」三種
// 要分開——不可以共用同一組功能碼（上一版 mdata_entity_add/edit/delete/status 是刻意合併
// 省工的設計，已被推翻）。新碼：mdata_part_add/edit/delete、
// mdata_customer_add/edit/delete/status、mdata_maker_add/edit/delete/status
// （料號沒有「狀態」，本來就沒有停用/啟用這個概念）；齒輪規格 mdata_gear_edit/delete
// 維持獨立不受影響。
//
// 本測試重點：①逐步給測試角色加功能碼，驗證每加一項，對應動作才會從「被擋」變成
// 「放行」②明確驗證三種實體互相獨立——只給 mdata_part_add 不可以用來新增客戶或廠商，
// 只給 mdata_customer_status 不可以用來切換廠商狀態，反之亦然。
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

// ── 步驟①：完全沒有任何本體功能碼（只有一個無關的 md_open）→ 全部動作應被擋 ──
grant($pdo, $roleId, 'md_open');
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'save_part','d_id'=>0,'D_Setting_Id'=>$PART_ID,'Type'=>'N','Customer_Id'=>$REF_CUST,'gears'=>'[]','bom_children'=>'[]','vendor_map'=>'[]','dedicated_part_map'=>'[]','machine_map'=>'[]','labels'=>json_encode([['label_id'=>1,'input_value'=>'100']])]);
check('①-1 無任何本體碼 → 新增料號被擋', is_array($r) && empty($r['success']));
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'save_customer','is_new'=>1,'customer_id'=>$CUST_ID,'customer'=>'測試']);
check('①-2 無任何本體碼 → 新增客戶被擋', is_array($r) && empty($r['success']));
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'save_maker','is_new'=>1,'maker_id_no'=>$MAKER_ID,'maker_id'=>'測試廠商']);
check('①-3 無任何本體碼 → 新增廠商被擋', is_array($r) && empty($r['success']));

// ── 步驟②：只給 mdata_part_add → 只能新增料號，客戶/廠商新增依然被擋（三者互相獨立） ──
grant($pdo, $roleId, 'mdata_part_add');
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'save_part','d_id'=>0,'D_Setting_Id'=>$PART_ID,'Type'=>'N','Customer_Id'=>$REF_CUST,'gears'=>'[]','bom_children'=>'[]','vendor_map'=>'[]','dedicated_part_map'=>'[]','machine_map'=>'[]','labels'=>json_encode([['label_id'=>1,'input_value'=>'100']])]);
check('②-1 有part_add → 新增料號成功', is_array($r) && !empty($r['success']));
$dId = (int)($r['d_id'] ?? 0);
check('②-2 料號真的建到DB裡', $dId > 0);

$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'save_customer','is_new'=>1,'customer_id'=>$CUST_ID,'customer'=>'測試客戶勿留']);
check('②-3 只有part_add → 新增客戶仍被擋（跨實體不互通）', is_array($r) && empty($r['success']));
$chkC0 = $pdo->prepare("SELECT COUNT(*) FROM customer_list WHERE customer_id=?"); $chkC0->execute([$CUST_ID]);
check('②-4 客戶確實沒有被建立', (int)$chkC0->fetchColumn() === 0);

$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'save_maker','is_new'=>1,'maker_id_no'=>$MAKER_ID,'maker_id'=>'測試廠商勿留']);
check('②-5 只有part_add → 新增廠商仍被擋（跨實體不互通）', is_array($r) && empty($r['success']));
$chkM0 = $pdo->prepare("SELECT COUNT(*) FROM maker_list WHERE maker_id_no=?"); $chkM0->execute([$MAKER_ID]);
check('②-6 廠商確實沒有被建立', (int)$chkM0->fetchColumn() === 0);

// ── 步驟③：補上 mdata_customer_add → 客戶新增放行，廠商新增依然被擋 ──
grant($pdo, $roleId, 'mdata_customer_add');
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'save_customer','is_new'=>1,'customer_id'=>$CUST_ID,'customer'=>'測試客戶勿留']);
check('③-1 補上customer_add → 新增客戶成功', is_array($r) && !empty($r['success']));
$chkC = $pdo->prepare("SELECT COUNT(*) FROM customer_list WHERE customer_id=?"); $chkC->execute([$CUST_ID]);
check('③-2 客戶真的建到DB裡', (int)$chkC->fetchColumn() === 1);

$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'save_maker','is_new'=>1,'maker_id_no'=>$MAKER_ID,'maker_id'=>'測試廠商勿留']);
check('③-3 customer_add不等於maker_add → 新增廠商依然被擋', is_array($r) && empty($r['success']));
$chkM1 = $pdo->prepare("SELECT COUNT(*) FROM maker_list WHERE maker_id_no=?"); $chkM1->execute([$MAKER_ID]);
check('③-4 廠商確實還沒被建立', (int)$chkM1->fetchColumn() === 0);

// ── 步驟④：補上 mdata_maker_add → 廠商新增放行 ──
grant($pdo, $roleId, 'mdata_maker_add');
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'save_maker','is_new'=>1,'maker_id_no'=>$MAKER_ID,'maker_id'=>'測試廠商勿留']);
check('④-1 補上maker_add → 新增廠商成功', is_array($r) && !empty($r['success']));
$chkM = $pdo->prepare("SELECT COUNT(*) FROM maker_list WHERE maker_id_no=?"); $chkM->execute([$MAKER_ID]);
check('④-2 廠商真的建到DB裡', (int)$chkM->fetchColumn() === 1);

// ── 步驟⑤：此刻只有三個 *_add，尚未有任何 *_edit → 三者編輯一律被擋 ──
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'save_customer','is_new'=>0,'customer_id'=>$CUST_ID,'customer'=>'改過的名字']);
check('⑤-1 還沒有customer_edit → 修改客戶被擋', is_array($r) && empty($r['success']));
$row = $pdo->prepare("SELECT customer FROM customer_list WHERE customer_id=?"); $row->execute([$CUST_ID]); $cr = $row->fetch(PDO::FETCH_ASSOC);
check('⑤-2 客戶名稱確實沒被改動', $cr && $cr['customer'] === '測試客戶勿留');

// ── 步驟⑥：補上 mdata_customer_edit → 客戶編輯放行，但不等於 part_edit/maker_edit ──
grant($pdo, $roleId, 'mdata_customer_edit');
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'save_customer','is_new'=>0,'customer_id'=>$CUST_ID,'customer'=>'改過的名字']);
check('⑥-1 補上customer_edit → 修改客戶成功', is_array($r) && !empty($r['success']));
$row = $pdo->prepare("SELECT customer FROM customer_list WHERE customer_id=?"); $row->execute([$CUST_ID]); $cr = $row->fetch(PDO::FETCH_ASSOC);
check('⑥-2 客戶名稱真的被改成功', $cr && $cr['customer'] === '改過的名字');

$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'save_part','d_id'=>$dId,'D_Setting_Id'=>$PART_ID,'Type'=>'N','Customer_Id'=>$REF_CUST,'Remark'=>'改過的備註','gears'=>'[]','bom_children'=>'[]','vendor_map'=>'[]','dedicated_part_map'=>'[]','machine_map'=>'[]','labels'=>json_encode([['label_id'=>1,'input_value'=>'100']])]);
check('⑥-3 customer_edit不等於part_edit → 修改料號依然被擋（跨實體不互通）', is_array($r) && empty($r['success']));
$rowP = $pdo->prepare("SELECT Remark FROM d_setting WHERE d_id=?"); $rowP->execute([$dId]); $pr = $rowP->fetch(PDO::FETCH_ASSOC);
check('⑥-4 料號備註確實沒被改動', $pr && ($pr['Remark']==='' || $pr['Remark']===null));

// ── 步驟⑦：客戶/廠商狀態切換——customer_status 與 maker_status 互相獨立 ──
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'toggle_customer_status','customer_id'=>$CUST_ID]);
check('⑦-1 還沒有customer_status → 切換客戶狀態被擋', is_array($r) && empty($r['success']));

grant($pdo, $roleId, 'mdata_customer_status');
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'toggle_customer_status','customer_id'=>$CUST_ID]);
check('⑦-2 補上customer_status → 切換客戶狀態成功', is_array($r) && !empty($r['success']));
$row = $pdo->prepare("SELECT is_inactive FROM customer_list WHERE customer_id=?"); $row->execute([$CUST_ID]); $cr = $row->fetch(PDO::FETCH_ASSOC);
check('⑦-3 客戶確實變成停用狀態', $cr && (int)$cr['is_inactive'] === 1);

// 注：廠商狀態切換在畫面上實際是走除錯工具的「停用廠商」（dup_deactivate_maker，
// 把 status 設為單字元 'X'）；toggle_maker_status 這個動作在前端從未被任何按鈕呼叫
// 過（UI 註解明講「廠商停用/啟用請透過編輯 modal 的廠商狀態欄位操作」），且其 SQL
// 把 status 寫成「停用」兩個全角字但該欄位是 char(1)，對任何人（含超級管理員）呼叫
// 都會直接 SQL 例外——這是與本次權限分拆無關的既有死碼缺陷，不在本次範圍內修正，
// 故改用真正會被使用的 dup_deactivate_maker 驗證 mdata_maker_status 這個權限碼。
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'dup_deactivate_maker','maker_id_no'=>$MAKER_ID]);
check('⑦-4 customer_status不等於maker_status → 停用廠商依然被擋', is_array($r) && empty($r['success']));
$rowMk = $pdo->prepare("SELECT status FROM maker_list WHERE maker_id_no=?"); $rowMk->execute([$MAKER_ID]); $mkr = $rowMk->fetch(PDO::FETCH_ASSOC);
check('⑦-5 廠商狀態確實沒被改動', $mkr && $mkr['status'] !== 'X');

grant($pdo, $roleId, 'mdata_maker_status');
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'dup_deactivate_maker','maker_id_no'=>$MAKER_ID]);
check('⑦-6 補上maker_status → 停用廠商成功', is_array($r) && !empty($r['success']));
$rowMk2 = $pdo->prepare("SELECT status FROM maker_list WHERE maker_id_no=?"); $rowMk2->execute([$MAKER_ID]); $mkr2 = $rowMk2->fetch(PDO::FETCH_ASSOC);
check('⑦-7 廠商確實變成停用狀態', $mkr2 && $mkr2['status'] === 'X');

// ── 步驟⑧：刪除——customer_delete 與 maker_delete 互相獨立 ──
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'delete_maker','maker_id_no'=>$MAKER_ID]);
check('⑧-1 還沒有maker_delete → 刪除廠商被擋', is_array($r) && empty($r['success']));
$chkM2 = $pdo->prepare("SELECT COUNT(*) FROM maker_list WHERE maker_id_no=?"); $chkM2->execute([$MAKER_ID]);
check('⑧-2 廠商確實還在DB裡', (int)$chkM2->fetchColumn() === 1);

grant($pdo, $roleId, 'mdata_customer_delete');
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'delete_maker','maker_id_no'=>$MAKER_ID]);
check('⑧-3 customer_delete不等於maker_delete → 刪除廠商依然被擋', is_array($r) && empty($r['success']));
$chkM2b = $pdo->prepare("SELECT COUNT(*) FROM maker_list WHERE maker_id_no=?"); $chkM2b->execute([$MAKER_ID]);
check('⑧-4 廠商確實還在DB裡', (int)$chkM2b->fetchColumn() === 1);

grant($pdo, $roleId, 'mdata_maker_delete');
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'delete_maker','maker_id_no'=>$MAKER_ID]);
check('⑧-5 補上maker_delete → 刪除廠商成功', is_array($r) && !empty($r['success']));
$chkM3 = $pdo->prepare("SELECT COUNT(*) FROM maker_list WHERE maker_id_no=?"); $chkM3->execute([$MAKER_ID]);
check('⑧-6 廠商真的從DB消失', (int)$chkM3->fetchColumn() === 0);

// ── 步驟⑨：齒輪規格刪除——先插一筆測試齒輪規格列，驗證 mdata_gear_delete 獨立於其他本體碼 ──
$pdo->prepare("INSERT INTO d_setting_gear (d_setting_id, Module, Teeth, Created_By) VALUES (?,?,?,?)")->execute([$dId, 2.0, 20, (string)$UID]);
$gearId = (int)$pdo->lastInsertId();
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'delete_gear_row','gear_id'=>$gearId,'d_setting_id'=>$dId]);
check('⑨-1 還沒有gear_delete → 刪除齒輪規格列被擋（即使已有customer/maker_delete）', is_array($r) && empty($r['success']));
$chkG = $pdo->prepare("SELECT COUNT(*) FROM d_setting_gear WHERE gear_id=?"); $chkG->execute([$gearId]);
check('⑨-2 齒輪規格列確實還在', (int)$chkG->fetchColumn() === 1);

grant($pdo, $roleId, 'mdata_gear_delete');
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'delete_gear_row','gear_id'=>$gearId,'d_setting_id'=>$dId]);
check('⑨-3 補上gear_delete → 刪除齒輪規格列成功', is_array($r) && !empty($r['success']));
$chkG2 = $pdo->prepare("SELECT COUNT(*) FROM d_setting_gear WHERE gear_id=?"); $chkG2->execute([$gearId]);
check('⑨-4 齒輪規格列真的被刪除', (int)$chkG2->fetchColumn() === 0);

// ── 步驟⑩：料號刪除——目前只有 customer_delete/maker_delete，料號刪除應仍被擋，補上 part_delete 才放行 ──
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'delete_part','d_id'=>$dId]);
check('⑩-1 customer_delete/maker_delete不等於part_delete → 刪除料號依然被擋', is_array($r) && empty($r['success']));
$chkP0 = $pdo->prepare("SELECT COUNT(*) FROM d_setting WHERE d_id=?"); $chkP0->execute([$dId]);
check('⑩-2 料號確實還在DB裡', (int)$chkP0->fetchColumn() === 1);

grant($pdo, $roleId, 'mdata_part_delete');
$r = call_md($RUNNER, $SCRIPT, $UID, ['action'=>'delete_part','d_id'=>$dId]);
check('⑩-3 補上part_delete → 刪除料號成功', is_array($r) && !empty($r['success']));
$chkP = $pdo->prepare("SELECT COUNT(*) FROM d_setting WHERE d_id=?"); $chkP->execute([$dId]);
check('⑩-4 料號真的被刪除', (int)$chkP->fetchColumn() === 0);

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
