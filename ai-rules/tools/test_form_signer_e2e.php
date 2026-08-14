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

// 測試人員(取自現有在職員工)：submitter=105030102(林雅婷)；stage1 slot=107022301(黃文德)、10(陳俊宏)、105030102(=submitter,測SoD)；
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
        ['mode'=>'user', 'user_id'=>$SUBMITTER, 'label'=>$SUBMITTER_NAME], // 測SoD自動略過
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

echo "\n== 4. 建立案件(直接送出開始跑第1關) ==\n";
$r = fsd_case_create($db, $tplId, $SUBMITTER, $SUBMITTER_NAME, '端到端測試案件', date('Y-m-d'));
chk('建立案件成功: ' . ($r['msg'] ?? ''), $r['ok'] === true);
$caseId = $r['id'] ?? 0;
$case = fsd_case_get($db, $caseId);
chk('案件狀態in_progress', $case['status'] === 'in_progress');
chk('目前在第1關', (int)$case['current_stage_seq'] === 1);

$resps = fsd_case_responses($db, $caseId);
$bySlot = [];
foreach ($resps as $rr) $bySlot[$rr['slot_key']] = $rr;
chk('第1關產生4筆回應紀錄(含1筆SoD略過)', count($resps) === 4);
$sodCount = count(array_filter($resps, fn($x) => $x['decision'] === 'skipped_sod'));
chk('恰好1筆SoD自動略過(送出人自己那槽)', $sodCount === 1);
$pendingCount = count(array_filter($resps, fn($x) => $x['decision'] === null));
chk('3筆待回應(扣除SoD略過)', $pendingCount === 3);

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
$r2 = fsd_case_create($db, $tplId, $SUBMITTER, $SUBMITTER_NAME, '駁回測試案件', date('Y-m-d'));
$caseId2 = $r2['id'];
fsd_case_advisory_respond($db, $caseId2, $U_A, $U_A_NAME, 'disagree', 'x');
fsd_case_advisory_respond($db, $caseId2, $U_B, $U_B_NAME, 'agree', 'x');
fsd_case_advisory_respond($db, $caseId2, $DEPT_MGR_EXPECT, '吳佳靜', 'agree', 'x');
$rReject = fsd_case_decision_respond($db, $caseId2, $U_B, $U_B_NAME, 'rejected', '不核准，退回');
chk('決策駁回成功', $rReject['ok'] === true && $rReject['status'] === 'rejected');
$case2 = fsd_case_get($db, $caseId2);
chk('案件狀態=rejected', $case2['status'] === 'rejected');

echo "\n== 9. AS文件編號動態module code ==\n";
chk('module code格式正確', fsd_asdoc_module($tplId) === 'fsd_tpl_' . $tplId);

echo "\n========================================\n";
echo "測試template_id=$tplId, case_id=$caseId(核准), case_id2=$caseId2(駁回)\n";
echo $fail === 0 ? "全部通過\n" : "$fail 項失敗\n";
exit($fail === 0 ? 0 : 1);
