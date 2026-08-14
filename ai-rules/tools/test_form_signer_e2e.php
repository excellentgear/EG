<?php
// 表單簽核設計器 — CLI 端到端測試（比照 review_form 先例，無登入憑證可操作瀏覽器，先用 CLI 直接呼叫函式驗證邏輯）。
// 用法：php test_form_signer_e2e.php
// 測試完會印出建立的 template_id/case_id，供人工用 sql.php 追查；不自動清理(比照 testing_discipline 記憶，
// 這批是新建的獨立測試資料，不影響既有資料，之後可用 template_id/case_id 手動清)。
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306", "EG-TS2024", "excell30367593");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("set names utf8mb4");
require_once __DIR__ . '/../../src/common/form_signer_lib.php';

$fail = 0;
function chk($label, $cond) {
    global $fail;
    echo ($cond ? "PASS" : "FAIL") . " - $label\n";
    if (!$cond) $fail++;
}

// 測試人員(取自現有在職員工)：submitter=105030102(林雅婷)；stage1 slot=107022301(黃文德)、10(陳俊宏)、
// 105030102(=submitter,固定人員模式測試「本人可以是固定簽核人,不受SoD限制」)；
// dept_auto_manager 測 department_id=5(預期解析出 110052601 吳佳靜, level=2 最高)；決策階段 top_approver 解析出 10(陳俊宏)。
$SUBMITTER = 105030102; $SUBMITTER_NAME = '林雅婷';
$U_A = 107022301; $U_A_NAME = '黃文德';
$U_B = 10; $U_B_NAME = '陳俊宏'; // 同時也是 top_approver 綁定人
$DEPT_MGR_DEPT = 5; $DEPT_MGR_EXPECT = 110052601;

echo "== 1. 建立樣板 + 頁面 + 階段/槽位 ==\n";
$tplId = fsd_template_create($db, '端到端測試模板', 'image', 'e2e_test.png', 1, 'CLI測試');
chk('建立樣板 id>0', $tplId > 0);
fsd_template_pages_save($db, $tplId, [['page_no'=>1, 'width_pt'=>595, 'height_pt'=>842]]);
$pages = fsd_template_pages_get($db, $tplId);
chk('頁面尺寸已存', count($pages) === 1 && (float)$pages[0]['width_pt'] === 595.0);

fsd_stages_save($db, $tplId, [
    ['stage_type'=>'advisory', 'name'=>'意見階段測試', 'auto_sign'=>0, 'signers'=>[
        ['mode'=>'user', 'user_id'=>$U_A, 'label'=>$U_A_NAME],
        ['mode'=>'user', 'user_id'=>$U_B, 'label'=>$U_B_NAME],
        ['mode'=>'user', 'user_id'=>$SUBMITTER, 'label'=>$SUBMITTER_NAME], // 固定人員=送出人本人,不受SoD限制,應正常待回應
        ['mode'=>'dept_auto_manager', 'dept_id'=>$DEPT_MGR_DEPT, 'label'=>'部門5主管'],
    ]],
    ['stage_type'=>'decision', 'name'=>'決策階段測試', 'auto_sign'=>0, 'signers'=>[
        ['mode'=>'top_approver', 'label'=>'最高決策者'],
    ]],
]);
$stages = fsd_stage_list($db, $tplId);
chk('階段數=2', count($stages) === 2);
chk('第1關4槽位', count($stages[0]['signers']) === 4);
chk('第2關1槽位', count($stages[1]['signers']) === 1);

/** 建立一個已送出(in_progress)的案件：走完整草稿→上傳→框選(白名單全放)→送出流程，回傳case_id。供後面章節重複使用。 */
function createAndSubmitCase(PDO $db, int $tplId, int $submitter, string $submitterName, string $title, array $minFrac): int {
    $r = fsd_case_create_draft($db, $tplId, $submitter, $submitterName, $title, date('Y-m-d'), 'image', 'e2e_'.bin2hex(random_bytes(3)).'.png');
    $caseId = $r['id'];
    fsd_case_pages_save($db, $caseId, [['page_no'=>1, 'width_pt'=>595, 'height_pt'=>842]]);
    $case = fsd_case_get($db, $caseId);
    foreach (array_keys(fsd_case_field_whitelist($db, $case)) as $key) {
        $slotKey = substr($key, 0, strrpos($key,'_')); $boxType = substr($key, strrpos($key,'_')+1);
        $w = $boxType === 'stamp' ? $minFrac['min_w']+0.02 : 0.25;
        $h = $boxType === 'stamp' ? $minFrac['min_h']+0.02 : 0.06;
        fsd_case_field_save($db, $caseId, ['slot_key'=>$slotKey, 'box_type'=>$boxType, 'page_no'=>1, 'x'=>0.1, 'y'=>0.1, 'w'=>$w, 'h'=>$h]);
    }
    fsd_case_submit($db, $caseId, $submitter);
    return $caseId;
}

echo "\n== 2. 框選區塊(圖章框最小尺寸驗證) ==\n";
$slot1 = $stages[0]['signers'][0]['id']; // U_A
$minFrac = fsd_field_min_frac(['width_pt'=>595, 'height_pt'=>842]);
echo "  A4頁面圖章框最小分數: w=" . round($minFrac['min_w'],4) . " h=" . round($minFrac['min_h'],4) . "\n";
$tooSmall = fsd_field_save($db, $tplId, ['stage_signer_id'=>$slot1, 'box_type'=>'stamp', 'page_no'=>1, 'x'=>0.1, 'y'=>0.1, 'w'=>0.02, 'h'=>0.02]);
chk('太小的圖章框被擋下', $tooSmall['ok'] === false);
$okStamp = fsd_field_save($db, $tplId, ['stage_signer_id'=>$slot1, 'box_type'=>'stamp', 'page_no'=>1, 'x'=>0.1, 'y'=>0.1, 'w'=>$minFrac['min_w']+0.02, 'h'=>$minFrac['min_h']+0.02]);
chk('合格尺寸的圖章框存檔成功', $okStamp['ok'] === true);
$okReply = fsd_field_save($db, $tplId, ['stage_signer_id'=>$slot1, 'box_type'=>'reply', 'page_no'=>1, 'x'=>0.1, 'y'=>0.3, 'w'=>0.3, 'h'=>0.08]);
chk('回覆框存檔成功(不受最小尺寸限制)', $okReply['ok'] === true);
foreach (array_slice($stages[0]['signers'], 1) as $sg) {
    fsd_field_save($db, $tplId, ['stage_signer_id'=>$sg['id'], 'box_type'=>'stamp', 'page_no'=>1, 'x'=>0.5, 'y'=>0.1, 'w'=>$minFrac['min_w']+0.02, 'h'=>$minFrac['min_h']+0.02]);
}
foreach ($stages[1]['signers'] as $sg) {
    fsd_field_save($db, $tplId, ['stage_signer_id'=>$sg['id'], 'box_type'=>'stamp', 'page_no'=>1, 'x'=>0.7, 'y'=>0.7, 'w'=>$minFrac['min_w']+0.02, 'h'=>$minFrac['min_h']+0.02]);
}
chk('框選共6個', count(fsd_field_list($db, $tplId)) === 6);

echo "\n== 3. 發布 schema(存檔即發布快照) ==\n";
$ver = fsd_template_schema_publish($db, $tplId, 'CLI測試');
chk('發布版本=1', $ver === 1);
$tpl = fsd_template_get($db, $tplId);
chk('published_version同步更新', (int)$tpl['published_version'] === 1);
$schema = fsd_template_schema_at_version($db, $tplId, 1);
chk('schema含2階段', count($schema['stages']) === 2);
chk('schema含6個框選(孤兒框已排除)', count($schema['fields']) === 6);

echo "\n== 3b. 階段/槽位差異更新(不可誤刪既有框選,2026-08-14使用者實測回報的bug回歸測試) ==\n";
$beforeFieldCount = count(fsd_field_list($db, $tplId));
$stagesForRelabel = fsd_stage_list($db, $tplId);
// 模擬「編輯標籤名稱(顯示用)」：只改第1關第1槽位的label文字，其餘槽位/階段原封不動地連id一起送回去
$stagesForRelabel[0]['signers'][0]['label'] = '改過的標籤名稱';
$payload = array_map(function($s) {
    return ['id'=>$s['id'], 'stage_type'=>$s['stage_type'], 'name'=>$s['name'], 'auto_sign'=>$s['auto_sign'],
            'signers'=>array_map(function($sg) {
                return ['id'=>$sg['id'], 'mode'=>$sg['mode'], 'user_id'=>$sg['user_id'], 'dept_id'=>$sg['dept_id'], 'label'=>$sg['label']];
            }, $s['signers'])];
}, $stagesForRelabel);
fsd_stages_save($db, $tplId, $payload);
chk('改標籤名稱後框選數量不變(未被連帶砍掉)', count(fsd_field_list($db, $tplId)) === $beforeFieldCount);
$relabeled = fsd_stage_signer_list($db, $stagesForRelabel[0]['id']);
chk('標籤名稱確實改到了', $relabeled[0]['label'] === '改過的標籤名稱');
$sameSignerIds = array_column(fsd_stage_signer_list($db, $stagesForRelabel[0]['id']), 'id');
chk('槽位id保持不變(不是delete+insert重來)', in_array($stagesForRelabel[0]['signers'][0]['id'], $sameSignerIds, true));

// 真的刪除一個槽位(第1關第4槽位=部門主管槽)時，該槽位的框選才應該被連動清掉
$removedSignerId = $stagesForRelabel[0]['signers'][3]['id'];
$fieldsForRemovedSigner = count(array_filter(fsd_field_list($db, $tplId), fn($f) => (int)$f['stage_signer_id'] === (int)$removedSignerId));
chk('待刪除槽位原本有框選', $fieldsForRemovedSigner > 0);
$payload2 = $payload;
array_splice($payload2[0]['signers'], 3, 1); // 真的移除第4個槽位
fsd_stages_save($db, $tplId, $payload2);
chk('框選數量因真的刪除槽位而減少', count(fsd_field_list($db, $tplId)) === $beforeFieldCount - $fieldsForRemovedSigner);
chk('其餘槽位的框選仍完整保留', count(fsd_field_list($db, $tplId)) === $beforeFieldCount - 1);
// 注意：後面章節都是對published v1快照(建立案件用schema)操作，不受這裡live table後續異動影響，故不需要復原。

echo "\n== 4. 建立案件草稿(自己上傳文件)+框選(白名單)+送出 ==\n";
$r = fsd_case_create_draft($db, $tplId, $SUBMITTER, $SUBMITTER_NAME, '端到端測試案件', date('Y-m-d'), 'image', 'e2e_case_test.png');
chk('建立案件草稿成功: ' . ($r['msg'] ?? ''), $r['ok'] === true);
$caseId = $r['id'] ?? 0;
$case = fsd_case_get($db, $caseId);
chk('案件狀態draft', $case['status'] === 'draft');
chk('案件檔名已存', $case['file_name'] === 'e2e_case_test.png');

fsd_case_pages_save($db, $caseId, [['page_no'=>1, 'width_pt'=>595, 'height_pt'=>842]]);
chk('案件頁面尺寸已存', count(fsd_case_pages_get($db, $caseId)) === 1);

$whitelist = fsd_case_field_whitelist($db, $case);
chk('白名單共6個(等同樣板已框選的6個)', count($whitelist) === 6);
$badTry = fsd_case_field_save($db, $caseId, ['slot_key'=>'s2_g1', 'box_type'=>'reply', 'page_no'=>1, 'x'=>0.1, 'y'=>0.1, 'w'=>0.3, 'h'=>0.08]);
chk('樣板沒框選過的欄位(決策階段回覆框)案件不可框選', $badTry['ok'] === false);
foreach (array_keys($whitelist) as $key) {
    list($slotKey, $boxType) = [substr($key, 0, strrpos($key,'_')), substr($key, strrpos($key,'_')+1)];
    $w = $boxType === 'stamp' ? $minFrac['min_w']+0.02 : 0.25;
    $h = $boxType === 'stamp' ? $minFrac['min_h']+0.02 : 0.06;
    $fr = fsd_case_field_save($db, $caseId, ['slot_key'=>$slotKey, 'box_type'=>$boxType, 'page_no'=>1, 'x'=>0.1, 'y'=>0.1, 'w'=>$w, 'h'=>$h]);
    if (!$fr['ok']) { chk('案件框選'.$key.'失敗: '.$fr['msg'], false); }
}
chk('案件框選共6個(白名單全部放好)', count(fsd_case_field_list($db, $caseId)) === 6);

$badSubmit = null;
$caseNoFields = fsd_case_create_draft($db, $tplId, $SUBMITTER, $SUBMITTER_NAME, '未上傳測試', date('Y-m-d'), '', '');
$rNoFile = fsd_case_submit($db, $caseNoFields['id'], $SUBMITTER);
chk('未上傳文件不可送出', $rNoFile['ok'] === false);
$db->prepare("DELETE FROM fsd_case WHERE id=?")->execute([$caseNoFields['id']]);

$rSubmit = fsd_case_submit($db, $caseId, $SUBMITTER);
chk('送出成功: ' . ($rSubmit['msg'] ?? ''), $rSubmit['ok'] === true);
$case = fsd_case_get($db, $caseId);
chk('案件狀態in_progress', $case['status'] === 'in_progress');
chk('目前在第1關', (int)$case['current_stage_seq'] === 1);

$resps = fsd_case_responses($db, $caseId);
$bySlot = [];
foreach ($resps as $rr) $bySlot[$rr['slot_key']] = $rr;
chk('第1關產生4筆回應紀錄', count($resps) === 4);
$sodCount = count(array_filter($resps, fn($x) => $x['decision'] === 'skipped_sod'));
chk('固定人員模式即使等於送出人本人也不受SoD限制,0筆被強制略過', $sodCount === 0);
$pendingCount = count(array_filter($resps, fn($x) => $x['decision'] === null));
chk('4筆皆待回應(含送出人自己那槽)', $pendingCount === 4);

// 驗證 dept_auto_manager 槽位確實解析到部門5的最高階主管
$deptSlotResp = null;
foreach ($resps as $rr) if ((int)$rr['resolved_user_id'] === $DEPT_MGR_EXPECT) $deptSlotResp = $rr;
chk('dept_auto_manager槽位解析出部門5主管(110052601)', $deptSlotResp !== null);

chk('尚未可推進(還有人沒回應)', fsd_stage_is_ready_to_advance($db, $caseId, 1) === false);

echo "\n== 5. 意見階段逐一回應 ==\n";
$rA = fsd_case_advisory_respond($db, $caseId, $U_A, $U_A_NAME, 'agree', '沒問題，同意。');
chk('U_A回應成功', $rA['ok'] === true && $rA['status'] === 'in_progress');
$rA2 = fsd_case_advisory_respond($db, $caseId, $U_A, $U_A_NAME, 'agree', '重複回應');
chk('U_A不可重複回應', $rA2['ok'] === false);

$rB = fsd_case_advisory_respond($db, $caseId, $U_B, $U_B_NAME, 'disagree', '有疑慮，不同意。');
chk('U_B回應成功', $rB['ok'] === true);

$rSelf = fsd_case_advisory_respond($db, $caseId, $SUBMITTER, $SUBMITTER_NAME, 'agree', '本人身兼固定簽核人');
chk('固定人員=送出人本人可以正常回應自己那槽(不受SoD限制)', $rSelf['ok'] === true);

$rDept = fsd_case_advisory_respond($db, $caseId, $DEPT_MGR_EXPECT, '吳佳靜', 'agree', '同意');
chk('部門主管回應後應推進到第2關: ' . ($rDept['status'] ?? ''), $rDept['ok'] === true && $rDept['status'] === 'in_progress');

$case = fsd_case_get($db, $caseId);
chk('已推進到第2關(決策階段)', (int)$case['current_stage_seq'] === 2);

$rec = eg_approval_latest($db, 'form_signer', $caseId, 'stage_2');
chk('決策階段已建立待簽核approval_record', $rec !== null && $rec['status'] === 'pending');

echo "\n== 6. 決策階段回應(OR-gate) ==\n";
$rDec = fsd_case_decision_respond($db, $caseId, $U_B, $U_B_NAME, 'approved', '核准通過');
chk('決策核准成功: ' . ($rDec['msg'] ?? ''), $rDec['ok'] === true && $rDec['status'] === 'approved');

$case = fsd_case_get($db, $caseId);
chk('案件狀態=approved(已無下一關,案件完成)', $case['status'] === 'approved');

$rDecAgain = fsd_case_decision_respond($db, $caseId, $U_B, $U_B_NAME, 'approved', '重複決策');
chk('已決策的案件不可重複決策', $rDecAgain['ok'] === false);

$rUrge = fsd_case_urge($db, $caseId, $SUBMITTER);
chk('已完成的案件不可催辦', $rUrge['ok'] === false);

echo "\n== 7. 通知確認(live_event) ==\n";
$evCount = $db->query("SELECT COUNT(*) FROM live_event WHERE ref_type LIKE 'FSD_%' AND ref_id=$caseId")->fetchColumn();
chk('本案件累計產生至少3筆live_event通知(意見階段開啟+決策階段開啟+完成通知)', (int)$evCount >= 3);

echo "\n== 8. 駁回流程測試(另建一案) ==\n";
$caseId2 = createAndSubmitCase($db, $tplId, $SUBMITTER, $SUBMITTER_NAME, '駁回測試案件', $minFrac);
fsd_case_advisory_respond($db, $caseId2, $U_A, $U_A_NAME, 'disagree', 'x');
fsd_case_advisory_respond($db, $caseId2, $U_B, $U_B_NAME, 'agree', 'x');
fsd_case_advisory_respond($db, $caseId2, $SUBMITTER, $SUBMITTER_NAME, 'agree', 'x'); // 固定人員=送出人本人,不受SoD限制,一樣要回應
fsd_case_advisory_respond($db, $caseId2, $DEPT_MGR_EXPECT, '吳佳靜', 'agree', 'x');
$rReject = fsd_case_decision_respond($db, $caseId2, $U_B, $U_B_NAME, 'rejected', '不核准，退回');
chk('決策駁回成功', $rReject['ok'] === true && $rReject['status'] === 'rejected');
$case2 = fsd_case_get($db, $caseId2);
chk('案件狀態=rejected', $case2['status'] === 'rejected');

echo "\n== 9. AS文件編號動態module code ==\n";
chk('module code格式正確', fsd_asdoc_module($tplId) === 'fsd_tpl_' . $tplId);

echo "\n== 10. 自動簽核is_auto旗標與清洗(一般使用者看不到「系統自動簽核」) ==\n";
$stagesAuto = [
    ['stage_type'=>'advisory', 'name'=>'自動意見關', 'auto_sign'=>1, 'signers'=>[
        ['mode'=>'user', 'user_id'=>$U_A, 'label'=>$U_A_NAME],
    ]],
    ['stage_type'=>'decision', 'name'=>'自動決策關', 'auto_sign'=>1, 'signers'=>[
        ['mode'=>'top_approver', 'label'=>'最高決策者'],
    ]],
];
$tplId3 = fsd_template_create($db, '自動簽核測試模板', 'image', 'e2e3.png', 1, 'CLI測試');
fsd_template_pages_save($db, $tplId3, [['page_no'=>1, 'width_pt'=>595, 'height_pt'=>842]]);
fsd_stages_save($db, $tplId3, $stagesAuto);
$stages3 = fsd_stage_list($db, $tplId3);
foreach ($stages3 as $s3) foreach ($s3['signers'] as $sg3) {
    fsd_field_save($db, $tplId3, ['stage_signer_id'=>$sg3['id'], 'box_type'=>'stamp', 'page_no'=>1, 'x'=>0.1, 'y'=>0.1, 'w'=>$minFrac['min_w']+0.02, 'h'=>$minFrac['min_h']+0.02]);
}
fsd_template_schema_publish($db, $tplId3, 'CLI測試');
$caseId3 = createAndSubmitCase($db, $tplId3, $SUBMITTER, $SUBMITTER_NAME, '自動簽核測試案件', $minFrac);
$case3 = fsd_case_get($db, $caseId3);
chk('全自動簽核案件直接完成(approved)', $case3['status'] === 'approved');
$rawResps = fsd_case_responses($db, $caseId3);
chk('原始資料含is_auto=1的紀錄', count(array_filter($rawResps, fn($x)=>!empty($x['is_auto']))) >= 1);
$adminView = fsd_sanitize_responses_for_viewer($rawResps, true);
chk('管理員視角看得到「系統自動簽核」', count(array_filter($adminView, fn($x)=>strpos((string)$x['reply_text'],'系統自動簽核')!==false)) >= 1);
$userView = fsd_sanitize_responses_for_viewer($rawResps, false);
chk('一般使用者視角完全看不到「系統自動簽核」字樣', count(array_filter($userView, fn($x)=>strpos((string)$x['reply_text'],'系統自動簽核')!==false)) === 0);
chk('一般使用者視角不含is_auto欄位', !isset($userView[0]['is_auto']));

echo "\n== 11. 刪除與復原(超級管理員硬刪不留紀錄／一般管理員+操作密碼軟刪可復原) ==\n";
$caseId4 = createAndSubmitCase($db, $tplId, $SUBMITTER, $SUBMITTER_NAME, '軟刪測試案件', $minFrac);
$softR = fsd_case_delete_soft($db, $caseId4, $U_B, $U_B_NAME);
chk('軟刪成功', $softR['ok'] === true);
$case4 = fsd_case_get($db, $caseId4);
chk('軟刪後狀態=void', $case4['status'] === 'void');
$log = $db->query("SELECT * FROM fsd_case_delete_log WHERE case_id=$caseId4 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
chk('軟刪留有刪除紀錄且記住原狀態(in_progress)', $log && $log['prior_status'] === 'in_progress');
$deletedList = fsd_case_deleted_list($db);
chk('已刪除清單查得到此案件', in_array($caseId4, array_column($deletedList,'id'), true));
$restoreR = fsd_case_restore($db, $caseId4, 1, '超級管理員');
chk('復原成功', $restoreR['ok'] === true && $restoreR['status'] === 'in_progress');
$case4 = fsd_case_get($db, $caseId4);
chk('復原後狀態回到in_progress', $case4['status'] === 'in_progress');

$caseId5 = createAndSubmitCase($db, $tplId, $SUBMITTER, $SUBMITTER_NAME, '硬刪測試案件', $minFrac);
$hardR = fsd_case_delete_hard($db, $caseId5);
chk('硬刪成功', $hardR['ok'] === true);
chk('硬刪後查無此案件', fsd_case_get($db, $caseId5) === null);
$logCount = $db->query("SELECT COUNT(*) FROM fsd_case_delete_log WHERE case_id=$caseId5")->fetchColumn();
chk('硬刪不留任何刪除紀錄', (int)$logCount === 0);

$draftForDelete = fsd_case_create_draft($db, $tplId, $SUBMITTER, $SUBMITTER_NAME, '草稿刪除測試', date('Y-m-d'), 'image', 'e2e_draftdel.png');
$draftDelR = fsd_case_delete_draft($db, $draftForDelete['id'], $SUBMITTER, false);
chk('草稿本人可直接刪除', $draftDelR['ok'] === true);
chk('草稿刪除後查無此案件', fsd_case_get($db, $draftForDelete['id']) === null);

echo "\n== 12. 決策階段線性多槽位(審核→核准鏈，2026-08-14使用者回報「兩個流程簽核卻只簽一處」bug回歸測試) ==\n";
$tplId4 = fsd_template_create($db, '決策鏈測試模板', 'image', 'e2e4.png', 1, 'CLI測試');
fsd_template_pages_save($db, $tplId4, [['page_no'=>1, 'width_pt'=>595, 'height_pt'=>842]]);
fsd_stages_save($db, $tplId4, [
    ['stage_type'=>'decision', 'name'=>'第1關', 'auto_sign'=>0, 'signers'=>[
        ['mode'=>'user', 'user_id'=>$U_A, 'label'=>'審核(部門主管)'],
        ['mode'=>'user', 'user_id'=>$U_B, 'label'=>'核准(最高決策者)'],
    ]],
]);
$stages4 = fsd_stage_list($db, $tplId4);
foreach ($stages4[0]['signers'] as $sg4) {
    fsd_field_save($db, $tplId4, ['stage_signer_id'=>$sg4['id'], 'box_type'=>'stamp', 'page_no'=>1, 'x'=>0.1, 'y'=>0.1, 'w'=>$minFrac['min_w']+0.02, 'h'=>$minFrac['min_h']+0.02]);
}
fsd_template_schema_publish($db, $tplId4, 'CLI測試');

echo "  -- 12a. 手動模式：第2位在第1位核准前不可決策，核准後才輪到 --\n";
$caseId6 = createAndSubmitCase($db, $tplId4, $SUBMITTER, $SUBMITTER_NAME, '決策鏈手動測試', $minFrac);
$case6 = fsd_case_get($db, $caseId6);
chk('案件送出後在第1關(決策階段)', $case6['status'] === 'in_progress' && (int)$case6['current_stage_seq'] === 1);
$respsBeforeB = fsd_case_responses($db, $caseId6);
chk('第1位(審核)已建立待簽核approval_record', eg_approval_latest($db,'form_signer',$caseId6,'stage_1')['status'] === 'pending');
$tryB = fsd_case_decision_respond($db, $caseId6, $U_B, $U_B_NAME, 'approved', '核准');
chk('第2位在輪到自己之前不可決策', $tryB['ok'] === false);
$decA = fsd_case_decision_respond($db, $caseId6, $U_A, $U_A_NAME, 'approved', '審核通過');
chk('第1位(審核)核准成功', $decA['ok'] === true && $decA['status'] === 'in_progress');
$case6 = fsd_case_get($db, $caseId6);
chk('案件仍在同一關(等第2位處理)', $case6['status'] === 'in_progress' && (int)$case6['current_stage_seq'] === 1);
$decB = fsd_case_decision_respond($db, $caseId6, $U_B, $U_B_NAME, 'approved', '核准通過');
chk('第2位(核准)核准成功後整個案件完成', $decB['ok'] === true && $decB['status'] === 'approved');
$responses6 = fsd_case_responses($db, $caseId6);
chk('兩位都留有回應紀錄(不是只簽一處)', count(array_filter($responses6, fn($x)=>$x['decision']==='approved')) === 2);

echo "  -- 12b. 手動模式：第1位駁回，第2位永遠不會被通知到 --\n";
$caseId7 = createAndSubmitCase($db, $tplId4, $SUBMITTER, $SUBMITTER_NAME, '決策鏈駁回測試', $minFrac);
$decAReject = fsd_case_decision_respond($db, $caseId7, $U_A, $U_A_NAME, 'rejected', '不通過');
chk('第1位駁回後案件立即終止', $decAReject['ok'] === true && $decAReject['status'] === 'rejected');
$case7 = fsd_case_get($db, $caseId7);
chk('案件狀態=rejected', $case7['status'] === 'rejected');
$responses7 = fsd_case_responses($db, $caseId7);
chk('第2位完全沒有回應紀錄(從未輪到)', count($responses7) === 1);

echo "  -- 12c. 自動簽核模式：兩位都要被自動簽過，不是只簽第一位就跳過 --\n";
fsd_stages_save($db, $tplId4, [
    ['id'=>$stages4[0]['id'], 'stage_type'=>'decision', 'name'=>'第1關', 'auto_sign'=>1, 'signers'=>[
        ['id'=>$stages4[0]['signers'][0]['id'], 'mode'=>'user', 'user_id'=>$U_A, 'label'=>'審核(部門主管)'],
        ['id'=>$stages4[0]['signers'][1]['id'], 'mode'=>'user', 'user_id'=>$U_B, 'label'=>'核准(最高決策者)'],
    ]],
]);
fsd_template_schema_publish($db, $tplId4, 'CLI測試');
$caseId8 = createAndSubmitCase($db, $tplId4, $SUBMITTER, $SUBMITTER_NAME, '決策鏈自動簽核測試', $minFrac);
$case8 = fsd_case_get($db, $caseId8);
chk('全自動簽核後案件直接完成', $case8['status'] === 'approved');
$responses8 = fsd_case_responses($db, $caseId8);
$autoApproved8 = array_filter($responses8, fn($x)=>$x['decision']==='approved' && !empty($x['is_auto']));
chk('兩位都被自動簽核過(修正前的bug是只簽一處)', count($autoApproved8) === 2);
$times8 = array_column($autoApproved8, 'responded_at');
sort($times8);
chk('兩筆自動簽核時間不同(依序遞增,不是同一秒疊在一起)', $times8[0] !== $times8[1]);

echo "\n== 13. 圖章框最小尺寸依綁定的圖章模板公分數計算(2026-08-14使用者要求「以圖章模板設定尺寸為主」) ==\n";
$stampTplRow = $db->query("SELECT id FROM stamp_template WHERE is_active=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($stampTplRow) {
    $stampTplId = (int)$stampTplRow['id'];
    fsd_template_set_stamp_tpl($db, $tplId4, $stampTplId, 'CLI測試');
    $tpl4 = fsd_template_get($db, $tplId4);
    chk('樣板已綁定圖章模板', $tpl4['stamp_tpl'] && (int)$tpl4['stamp_tpl']['id'] === $stampTplId);
    $minWithTpl = fsd_field_min_frac(['width_pt'=>595,'height_pt'=>842], $tpl4['stamp_tpl']['schema']);
    $minWithout = fsd_field_min_frac(['width_pt'=>595,'height_pt'=>842], null);
    chk('綁定模板後最小尺寸計算改用模板size(94px)而非預設91px(數字應不同)', abs($minWithTpl['min_w'] - $minWithout['min_w']) > 0.0001);
    // 用比模板要求還小的框驗證會被擋下
    $stages4b = fsd_stage_list($db, $tplId4);
    $tooSmallForTpl = fsd_field_save($db, $tplId4, ['stage_signer_id'=>$stages4b[0]['signers'][0]['id'], 'box_type'=>'stamp', 'page_no'=>1, 'x'=>0.1,'y'=>0.1,'w'=>$minWithout['min_w'],'h'=>$minWithout['min_h']]);
    chk('小於模板要求尺寸的框(但符合91px預設)仍被擋下', $tooSmallForTpl['ok'] === false);
    $tplOptions = fsd_stamp_tpl_options($db);
    chk('圖章模板選項清單非空', count($tplOptions) > 0);
} else {
    echo "  （系統無任何啟用中的圖章模板，跳過本節）\n";
}

echo "\n== 14. 填表人(filler)功能：來源解析/部門自動主管fallback/強制SoD/僅超管可回改(2026-08-14使用者明確要求) ==\n";
$tplId5 = fsd_template_create($db, '端到端測試模板5(填表人)', 'image', 'e2e_test5.png', 1, 'CLI測試');
fsd_template_pages_save($db, $tplId5, [['page_no'=>1, 'width_pt'=>595, 'height_pt'=>842]]);
$fillerRow = $db->query("SELECT m.user_id, m.department_id, u.user_cname FROM user_department_position_map m
    JOIN user u ON u.id=m.user_id WHERE m.is_main=1 AND m.user_id<>$SUBMITTER AND COALESCE(u.state,1) NOT IN (0,90) LIMIT 1")->fetch(PDO::FETCH_ASSOC);
fsd_stages_save($db, $tplId5, [
    ['stage_type'=>'decision', 'name'=>'填表人決策', 'auto_sign'=>0, 'signers'=>[
        ['mode'=>'filler', 'label'=>'填表人本人'],
    ]],
    ['stage_type'=>'decision', 'name'=>'部門自動主管(未指定部門)', 'auto_sign'=>0, 'signers'=>[
        ['mode'=>'dept_auto_manager', 'dept_id'=>0, 'label'=>'填表人部門主管'],
    ]],
]);
fsd_template_schema_publish($db, $tplId5, 'CLI測試');

$caseId9 = createAndSubmitCase($db, $tplId5, $SUBMITTER, $SUBMITTER_NAME, '填表人測試案', $minFrac);
$case9 = fsd_case_get($db, $caseId9);
chk('建立時filler預設=applicant(建立者本人)', (int)$case9['filler_id'] === $SUBMITTER && $case9['filler_name'] === $SUBMITTER_NAME);

$denyResult = fsd_case_set_filler($db, $caseId9, $SUBMITTER, $U_A);
chk('非超級管理員不可設定填表人', $denyResult['ok'] === false);

if ($fillerRow) {
    $setResult = fsd_case_set_filler($db, $caseId9, 1, (int)$fillerRow['user_id']);
    chk('超級管理員(uid=1)可設定填表人', $setResult['ok'] === true);
    $case9b = fsd_case_get($db, $caseId9);
    chk('filler_id已更新為指定人員', (int)$case9b['filler_id'] === (int)$fillerRow['user_id']);

    $schema9 = fsd_case_schema($db, $case9b);
    $resolvedFiller = fsd_resolve_signer($db, $schema9['stages'][0]['signers'][0], $case9b);
    chk('filler模式解析出的人＝填表人本人(非applicant)', $resolvedFiller && (int)$resolvedFiller['id'] === (int)$fillerRow['user_id']);

    $expectMgr = eg_org_dept_manager($db, (int)$fillerRow['department_id']);
    $resolvedDeptMgr = fsd_resolve_signer($db, $schema9['stages'][1]['signers'][0], $case9b);
    if ($expectMgr) {
        chk('部門自動主管未指定部門時,以填表人所屬部門自動判斷(非applicant部門)', $resolvedDeptMgr && (int)$resolvedDeptMgr['id'] === (int)$expectMgr['id']);
    } else {
        echo "  （填表人所屬部門查無主管，跳過此斷言）\n";
    }
} else {
    echo "  （查無可用測試人員的部門主檔，跳過超管回改/部門fallback斷言）\n";
}

// 2026-08-14使用者實測回報：設定填表人簽章卻被自動迴避擋住，明確要求「填表人跟固定人員本來就要可以是
// 文件建立者本人，不應該擋」——SoD只對系統自動解析出上級的來源(部門自動主管/上一階主管/最高決策者)有意義。
$caseId10 = createAndSubmitCase($db, $tplId5, $SUBMITTER, $SUBMITTER_NAME, '填表人=本人測試案', $minFrac);
$responses10 = fsd_case_responses($db, $caseId10);
chk('填表人=送出人本人時,0筆被強制SoD略過', count(array_filter($responses10, function($x){ return $x['decision']==='skipped_sod'; })) === 0);
$case10 = fsd_case_get($db, $caseId10);
chk('案件正常停在第1關等待填表人(=送出人)本人決策(未被跳過)', $case10['status']==='in_progress' && (int)$case10['current_stage_seq']===1);
// 決策階段(線性)在真人回應前不會有fsd_case_response placeholder列(不像意見階段開關就先插pending列)，
// 「待處理」狀態是靠approval_record，這裡驗證approval_record確實指向填表人(=送出人)本人，不是被略過。
$rec10 = eg_approval_latest($db, 'form_signer', $caseId10, 'stage_1');
chk('decision stage待簽核approval_record已建立且狀態pending(未被SoD跳過)', $rec10 !== null && $rec10['status'] === 'pending');
$rSelfDecide = fsd_case_decision_respond($db, $caseId10, $SUBMITTER, $SUBMITTER_NAME, 'approved', '本人身兼填表人與決策人');
chk('填表人(=送出人)本人可以正常決策自己那槽(不受SoD限制)', $rSelfDecide['ok'] === true);

// 對照組：系統自動解析模式(如top_approver)若剛好解析到送出人本人,仍應維持強制SoD略過(純函式測試,不受真人組織資料影響)
$topApprover = eg_org_user($db, 'top_approver');
if ($topApprover) {
    $fakeCase = ['applicant_id'=>(int)$topApprover['id'], 'applicant_name'=>$topApprover['user_cname'], 'filler_id'=>(int)$topApprover['id'], 'filler_name'=>$topApprover['user_cname']];
    $rAuto = fsd_resolve_signer_for_case($db, ['mode'=>'top_approver'], $fakeCase);
    chk('系統自動解析模式(top_approver)解析到送出人本人時,仍強制SoD略過', $rAuto['skipped_sod'] === true && $rAuto['user'] === null);
    $rFixedSelf = fsd_resolve_signer_for_case($db, ['mode'=>'user', 'user_id'=>(int)$topApprover['id']], $fakeCase);
    chk('固定人員模式(user)即使解析對象=送出人本人,仍不受SoD限制', $rFixedSelf['skipped_sod'] === false && $rFixedSelf['user'] !== null);
} else {
    echo "  （系統未設定top_approver，跳過自動解析模式SoD對照組斷言）\n";
}

echo "\n== 15. 案件進度摘要(順序/並列簽核狀態,供列表顯示,2026-08-14使用者明確要求) ==\n";
$schema6 = fsd_template_schema_at_version($db, $tplId4, (int)fsd_case_get($db, $caseId6)['template_version']);
$progress6 = fsd_case_progress_chips($db, fsd_case_get($db, $caseId6), $schema6, fsd_case_responses($db, $caseId6));
chk('決策鏈(2槽位)進度摘要階段數與樣板一致', count($progress6) === count($schema6['stages']));
$decisionStageProg = null;
foreach ($progress6 as $s) if ($s['stage_type'] === 'decision') { $decisionStageProg = $s; break; }
chk('決策階段的槽位數量正確(線性2位)', $decisionStageProg && count($decisionStageProg['signers']) === 2);
chk('已核准的槽位狀態標記為done', $decisionStageProg && $decisionStageProg['signers'][0]['status'] === 'done');
// 另建一案(尚未決策)：驗證決策階段(線性)目前輪到但尚無回應紀錄的槽位,進度摘要要標記pending(不是skipped也不是not_started)
$caseId11 = createAndSubmitCase($db, $tplId5, $SUBMITTER, $SUBMITTER_NAME, '進度摘要pending狀態測試案', $minFrac);
$progress11 = fsd_case_progress_chips($db, fsd_case_get($db, $caseId11), fsd_case_schema($db, fsd_case_get($db, $caseId11)), fsd_case_responses($db, $caseId11));
$fillerSlotProg = $progress11[0]['signers'][0] ?? null;
chk('進度摘要正確標記決策階段目前輪到的槽位狀態為pending(不是skipped)', $fillerSlotProg && $fillerSlotProg['status'] === 'pending');

echo "\n========================================\n";
echo "測試template_id=$tplId(核准案$caseId/駁回案$caseId2), template_id3=$tplId3(全自動案$caseId3), 軟刪復原案$caseId4\n";
echo "template_id4=$tplId4(決策鏈: 手動$caseId6/駁回$caseId7/自動$caseId8)\n";
echo "template_id5=$tplId5(填表人測試: $caseId9/SoD:$caseId10)\n";
echo $fail === 0 ? "全部通過\n" : "$fail 項失敗\n";
exit($fail === 0 ? 0 : 1);
