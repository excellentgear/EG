<?php
/**
 * 請假系統 2026-07-30 批次修正驗證：
 *   時間欄直接輸入（禁下拉）、半小時吸附、起訖顯示規則、審核前修改、待簽通知 mode=sign、
 *   狀態標示統一、範圍按鈕切換、人事預設全公司。
 * 測試單以 __test__ 命名不發真實推播；清理只刪本腳本 lastInsertId 建立的列。
 */
mb_internal_encoding('UTF-8');
if (PHP_SAPI !== 'cli') exit('CLI only');
require_once 'C:/MAMP/htdocs/EGsystem/src/common/leave_lib.php';
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4",
              "EG-TS2024", "excell30367593", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = 0; $fail = 0; $created = [];
function ok($c, $n, $note = '') { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $n\n"; } else { $fail++; echo "  [FAIL] $n $note\n"; } }

$src = file_get_contents('C:/MAMP/htdocs/EGsystem/views/ADM/leave_request.php');

echo "== 時間欄：直接輸入、禁用下拉（ai-rules/08 第二之二節）==\n";
ok(strpos($src, 'id="fTimeFrom" maxlength="5" placeholder="08:00"') !== false, '開始時間為 text 直接輸入');
ok(strpos($src, 'id="fTimeTo" maxlength="5" placeholder="17:00"') !== false, '結束時間為 text 直接輸入');
ok(strpos($src, 'type="time"') === false, '頁面已無 type="time"（不再出現瀏覽器時間下拉）');
ok(strpos($src, 'step="1800"') === false, '已移除 step=1800（改由 snapHalf 正規化）');
ok(strpos($src, 'function parseTime') !== false, '有 parseTime 寬容解析（09:00/0900/9）');
ok(strpos($src, 'function snapHalf') !== false, '有 snapHalf 半小時吸附');
ok(strpos($src, "on('input', '#fTimeFrom, #fTimeTo'") !== false, '打字中即時提示（不改寫內容）');
ok(strpos($src, "on('change blur', '#fTimeFrom, #fTimeTo'") !== false, '離開欄位才正規化');
ok(strpos($src, 'function checkTimeOrder') !== false, '同日結束不可早於開始的即時檢查');
ok(strpos($src, '不存在，須 0~23') !== false, '錯誤訊息說明原因（小時範圍）');

echo "== 起訖顯示規則 ==\n";
ok(strpos($src, 'function fmtPeriod') !== false, '有共用 fmtPeriod（列表/待簽/詳情共用）');
ok(strpos($src, 'function isFullDayLeave') !== false, '有整天判定');
ok(substr_count($src, 'fmtPeriod(') >= 4, 'fmtPeriod 至少被三處呼叫（含定義）', (string)substr_count($src, 'fmtPeriod('));
ok(strpos($src, "esc(String(o.start_datetime).substring(0,16))+'<br>~ '") === false, '列表不再兩行各印一次日期');

echo "== 狀態標示統一 ==\n";
ok(strpos($src, "EGStamp.badge('pending', 15)") === false, '列表標記欄不再放申請中章');
ok(strpos($src, "EGStamp.badge('sign', 15)") === false, '列表標記欄不再放簽章圖示');
ok(strpos($src, "+ stBadge('pending')") !== false, '待簽清單改用與狀態欄相同的 stBadge');

echo "== 範圍/狀態改按鈕切換 ==\n";
ok(strpos($src, 'id="scopeBtns"') !== false, '範圍為按鈕群組');
ok(strpos($src, 'id="statusBtns"') !== false, '狀態為按鈕群組');
ok(strpos($src, 'class="btn scope-btn"') !== false && strpos($src, 'class="btn status-btn"') !== false, '按鈕樣式類別存在');
ok(strpos($src, '.scope-btn.on,.status-btn.on{background:var(--amber)') !== false, '選中鈕為暖色深底白字（配色規範）');
ok(strpos($src, "\$VIEW_ALL ? 'all' : 'mine'") !== false, '人事/管理員預設範圍＝全公司');
ok(strpos($src, 'function syncFilterBtns') !== false, '按鈕選中狀態同步（則一選擇）');

echo "== 列底色依狀態（2026-07-30 使用者要求）==\n";
ok(strpos($src, '.lv-tbl tr.row-canceled > td{background:#FAE3E7;}') !== false, '已取消／撤回＝暖粉底');
ok(strpos($src, '.lv-tbl tr.row-pending  > td{background:#FDF4E3;}') !== false, '審核中＝淺琥珀底');
ok(strpos($src, '.lv-tbl tr.row-rejected > td{background:#FBE6DF;}') !== false, '已退回也有底色（淺赭）');
ok(strpos($src, "{pending:'row-pending', rejected:'row-rejected', canceled:'row-canceled',") !== false,
   '列依狀態套用 class');
ok(strpos($src, "cancel_pending:'row-cancelpend'") !== false, '撤回待簽核也有底色（2026-07-30 新狀態）');
ok(strpos($src, "cancel_pending:['st-cancelpend','撤回待簽核']") !== false, '撤回待簽核有狀態徽章');
ok(strpos($src, 'data-status="cancel_pending"') !== false, '狀態篩選含撤回待簽核');
ok(strpos($src, 'box-shadow:inset 3px 0 0 #DD5138') !== false, '左側色條加強區分（顏色非唯一資訊，狀態欄仍有文字）');

echo "== 簽核流程／簽章軌跡不重複顯示 ==\n";
ok(strpos($src, 'const hasExtraStep = recs.some(s => +s.step_no >= 98)') !== false, '有判斷是否有修改/撤回等額外動作');
ok(strpos($src, 'const hasRepeat = ') !== false, '有判斷同層是否多筆（退回後重簽）');
ok(strpos($src, 'if(recs.length && (hasExtraStep || hasRepeat || recs.length > decided))') !== false,
   '軌跡只在比流程多出資訊時才顯示（單層一次決行不重複）');

echo "== 待簽通知 mode=sign 與側欄路由 ==\n";
$lib = file_get_contents('C:/MAMP/htdocs/EGsystem/src/common/leave_lib.php');
ok(strpos($lib, "'sign', 'LEAVE_APPROVAL'") !== false, '待簽通知用 mode=sign + ref_type=LEAVE_APPROVAL');
ok(strpos($lib, 'function eg_leave_notify_done') !== false, '有 eg_leave_notify_done 讓通知簽完後消失');
ok(strpos($lib, 'eg_leave_notify_done($db, $requestId, $userId)') !== false, '簽核後結案該人的通知');
ok(strpos($lib, 'eg_leave_notify_done($db, $requestId, 0)') !== false, '整單決行/撤回時收回全部待簽通知');
$menu = file_get_contents('C:/MAMP/htdocs/EGsystem/views/partPage/sideAndTopBarMenu.html');
ok(strpos($menu, "refType === 'LEAVE_APPROVAL'") !== false, '側欄有 LEAVE_APPROVAL 路由');
ok(strpos($menu, 'leave_request.php?sign=') !== false, '路由帶單號到待我簽核');
ok(strpos($src, 'function focusSignTarget') !== false, '請假頁接 ?sign= 並定位該單');

echo "== 審核前修改 ==\n";
ok(strpos($lib, 'function eg_leave_can_edit') !== false, '有 eg_leave_can_edit');
ok(strpos($lib, 'function eg_leave_update') !== false, '有 eg_leave_update');
$api = file_get_contents('C:/MAMP/htdocs/EGsystem/src/store/Leave_API.php');
ok(strpos($api, "case 'update'") !== false, 'API 有 update action');
ok(strpos($api, "'can_edit' => \$edit['ok']") !== false, 'detail 回傳 can_edit');
ok(strpos($api, "'can_request_change'") !== false, 'detail 回傳 can_request_change');
ok(strpos($src, 'function startEdit') !== false && strpos($src, 'function requestChange') !== false, '前端有修改/申請修改');

echo "== 修改功能實跑 ==\n";
$UID = 113061801;   // 黃文孝（無代理候選，可送 agent=1 假別）
$d = date('Y-m-d', strtotime('+100 day'));
for ($i = 0; $i < 20 && !eg_leave_is_workday($db, $d); $i++) $d = date('Y-m-d', strtotime($d . ' +1 day'));
$r = eg_leave_submit($db, ['employee_id' => $UID, 'leave_type_id' => 2,
    'start_datetime' => "$d 09:00:00", 'end_datetime' => "$d 11:00:00",
    'reason' => '__test__修改前', 'agent_user_id' => 0, 'upload_token' => '']);
ok(!empty($r['ok']), '建立測試單', $r['msg'] ?? '');
$rid = (int)($r['id'] ?? 0);
if ($rid) $created[] = $rid;

if ($rid) {
    $row = $db->query("SELECT * FROM leave_request WHERE id=$rid")->fetch(PDO::FETCH_ASSOC);
    $ce = eg_leave_can_edit($db, $row, $UID);
    ok($ce['ok'], '尚無人簽核 → 可修改', $ce['reason']);

    $u = eg_leave_update($db, $rid, $UID, ['leave_type_id' => 2,
        'start_datetime' => "$d 13:00:00", 'end_datetime' => "$d 17:00:00",
        'reason' => '__test__修改後', 'agent_user_id' => 0]);
    ok(!empty($u['ok']), '修改成功', $u['msg'] ?? '');
    $row2 = $db->query("SELECT * FROM leave_request WHERE id=$rid")->fetch(PDO::FETCH_ASSOC);
    ok(substr($row2['start_datetime'], 11, 5) === '13:00', '起始時間已更新', $row2['start_datetime']);
    ok((float)$row2['total_hours'] == 4.0, '時數已重算為 4 小時', (string)$row2['total_hours']);
    ok($row2['reason'] === '__test__修改後', '原因已更新');
    $ev = $db->query("SELECT start FROM evenement WHERE id=" . (int)$row2['evenement_id'])->fetchColumn();
    ok($ev && substr($ev, 11, 5) === '13:00', '行事曆事件時間同步（沿用原事件不新建）', (string)$ev);
    $edited = (int)$db->query("SELECT COUNT(*) FROM leave_sign_record WHERE leave_request_id=$rid AND step_no=98")->fetchColumn();
    ok($edited === 1, '修改留下軌跡 step_no=98');

    // 有人簽核後不可再直接改
    $prev = eg_leave_preview_signers($db, $UID, 1);
    if (!empty($prev)) {
        eg_leave_sign($db, $rid, (int)$prev[0]['signer_id'], 'approved', '__test__同意');
        $row3 = $db->query("SELECT * FROM leave_request WHERE id=$rid")->fetch(PDO::FETCH_ASSOC);
        $ce2 = eg_leave_can_edit($db, $row3, $UID);
        ok(!$ce2['ok'], '已簽核/已核准後不可直接修改', $ce2['reason']);
        ok(strpos($ce2['reason'], '申請修改') !== false || strpos($ce2['reason'], '撤回') !== false,
           '並說明改用申請修改或撤回', $ce2['reason']);
    }
}

// 清理
foreach ($created as $id) {
    $ev = $db->query("SELECT evenement_id FROM leave_request WHERE id=$id")->fetchColumn();
    if ($ev) {
        $db->exec("DELETE FROM evenement_recipient_cache WHERE event_id=" . (int)$ev);
        $db->exec("DELETE FROM evenement_target WHERE event_id=" . (int)$ev);
        $db->exec("DELETE FROM evenement_actor WHERE event_id=" . (int)$ev);
        $db->exec("DELETE FROM evenement WHERE id=" . (int)$ev);
    }
    $db->exec("DELETE FROM leave_sign_record WHERE leave_request_id=$id");
    $db->exec("DELETE FROM leave_approval WHERE leave_request_id=$id");
    $db->exec("DELETE FROM leave_request WHERE id=$id");
}
foreach ($db->query("SELECT id FROM live_event WHERE ref_type IN ('LEAVE','LEAVE_APPROVAL')")->fetchAll(PDO::FETCH_COLUMN) as $e) {
    $db->exec("DELETE FROM live_event_response WHERE live_event_id=" . (int)$e);
    $db->exec("DELETE FROM live_event_target WHERE live_event_id=" . (int)$e);
    $db->exec("DELETE FROM live_event WHERE id=" . (int)$e);
}
$left = (int)$db->query("SELECT COUNT(*) FROM leave_request WHERE reason LIKE '\\_\\_test\\_\\_%'")->fetchColumn();
ok($left === 0, '測試資料已清除乾淨', (string)$left);

printf("\n結果：PASS %d / FAIL %d\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
