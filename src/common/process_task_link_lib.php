<?php
/**
 * process_task_link_lib.php — 「製程中紀錄」↔「個人工作紀錄」連動（2026-10-19 使用者交辦）
 *
 * 製程中紀錄的某一條可「轉個人工作紀錄」：在 personal_task 自動建一筆（標題＝紀錄內容、
 * 綁定該訂單、可設期限與提醒），personal_task.src_item_id 指回 eng_log_item.id。
 * 兩邊完成狀態一律同步：
 *   ・製程中紀錄標記已處理／不處理 → 所有連結的個人工作紀錄設為已完成
 *   ・製程中紀錄退回待回覆       → 連結的個人工作紀錄（已完成者）恢復未完成
 *   ・個人工作紀錄按完成         → 必須填回覆（personal_task.php 跳窗），由 ptl_complete() 同時
 *                                  寫入回覆並把該條紀錄標記已處理（唯一實作，不要在別處另寫）
 * 同步的收斂點是 eng_log_lib.php 的 el_item_set_status()（所有改狀態的入口都經過它）。
 */

/** 補欄位（MySQL 9.4 沒有 ADD COLUMN IF NOT EXISTS，先查再加）；同一個請求只檢查一次 */
function ptl_ensure(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $have = [];
        foreach ($db->query("SHOW COLUMNS FROM personal_task")->fetchAll(PDO::FETCH_ASSOC) as $c) $have[$c['Field']] = 1;
        if (empty($have['src_item_id'])) {
            $db->exec("ALTER TABLE personal_task ADD COLUMN src_item_id INT NULL COMMENT '來源：製程中紀錄 eng_log_item.id（有值=與該紀錄完成狀態同步）' AFTER note");
        }
        if (empty($have['src_log_id'])) {
            $db->exec("ALTER TABLE personal_task ADD COLUMN src_log_id INT NULL COMMENT '來源：eng_log.id' AFTER src_item_id");
        }
    } catch (Throwable $e) { error_log('ptl_ensure: ' . $e->getMessage()); }
}

/** eng_log_item 狀態變動 → 同步連結的個人工作紀錄。沒有連結的紀錄零成本（一次查詢） */
function ptl_sync_item(PDO $db, int $itemId, string $status): void
{
    try {
        ptl_ensure($db);
        if ($status === 'resolved' || $status === 'dropped') {
            $db->prepare("UPDATE personal_task SET status=1, completed_at=NOW() WHERE src_item_id=? AND status<>1")->execute([$itemId]);
        } elseif ($status === 'waiting') {
            $db->prepare("UPDATE personal_task SET status=0, completed_at=NULL WHERE src_item_id=? AND status=1")->execute([$itemId]);
        }
        ptl_rebuild_steps($db, $itemId);
    } catch (Throwable $e) { error_log('ptl_sync_item: ' . $e->getMessage()); }
}

/**
 * 依該條紀錄的回覆歷程，重建所有連結任務的「進度」（唯一實作）：
 *   一般回覆 → 「回覆」；【追問】 → 「追問：…」；【PS】 → 「PS：…」（都是已到達，時間＝該回覆時間）
 *   還在等回覆（狀態待回覆）→ 最後多一個未到達的「回覆」。最終「完成」由任務本身的完成鈕處理。
 * 依序更新既有步驟列（保留 id、預定日與提醒設定），多的新增、少的刪除。
 */
function ptl_rebuild_steps(PDO $db, int $itemId): void
{
    try {
        ptl_ensure($db);
        $ts = $db->prepare("SELECT id FROM personal_task WHERE src_item_id=?");
        $ts->execute([$itemId]);
        $taskIds = array_map('intval', $ts->fetchAll(PDO::FETCH_COLUMN));
        if (!$taskIds) return;
        $stI = $db->prepare("SELECT status, updated_at FROM eng_log_item WHERE id=?");
        $stI->execute([$itemId]);
        $it = $stI->fetch(PDO::FETCH_ASSOC);
        if (!$it) return;
        $rp = $db->prepare("SELECT content, created_at FROM eng_log_reply WHERE item_id=? ORDER BY id");
        $rp->execute([$itemId]);
        $sum = function ($t, $pre) {
            $t = trim(preg_replace('/\s+/u', ' ', mb_substr($t, mb_strlen($pre))));
            return mb_substr($t, 0, 16) . (mb_strlen($t) > 16 ? '…' : '');
        };
        $want = [];   // [name, reached_at|null]
        foreach ($rp->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $c = trim((string)$r['content']);
            if (mb_strpos($c, '【追問】') === 0)      $name = '追問：' . $sum($c, '【追問】');
            elseif (mb_strpos($c, '【PS】') === 0)    $name = 'PS：' . $sum($c, '【PS】');
            else                                       $name = '回覆';
            $want[] = [rtrim($name, '：'), $r['created_at']];
        }
        if ($it['status'] === 'waiting') $want[] = ['回覆', null];
        elseif (!$want) $want[] = ['回覆', $it['updated_at']];
        foreach ($taskIds as $tid) {
            $old = $db->prepare("SELECT id FROM personal_task_step WHERE task_id=? ORDER BY sort_order, id");
            $old->execute([$tid]);
            $oldIds = array_map('intval', $old->fetchAll(PDO::FETCH_COLUMN));
            foreach ($want as $i => $w) {
                if (isset($oldIds[$i])) {
                    $db->prepare("UPDATE personal_task_step SET step_name=?, sort_order=?, reached_at=? WHERE id=?")
                       ->execute([$w[0], $i, $w[1], $oldIds[$i]]);
                } else {
                    $db->prepare("INSERT INTO personal_task_step (task_id, step_name, sort_order, reached_at) VALUES (?,?,?,?)")
                       ->execute([$tid, $w[0], $i, $w[1]]);
                }
            }
            for ($j = count($want); $j < count($oldIds); $j++) {
                $db->prepare("DELETE FROM personal_task_step_item WHERE step_id=?")->execute([$oldIds[$j]]);
                $db->prepare("DELETE FROM personal_task_step WHERE id=?")->execute([$oldIds[$j]]);
            }
        }
    } catch (Throwable $e) { error_log('ptl_rebuild_steps: ' . $e->getMessage()); }
}

/**
 * 把連結任務綁定到該訂單的料號（料號主檔 d_setting.d_id；綁定 bind_type='part'，與個人工作紀錄手動綁料號同一格式）。
 * 料號以 order_track.d_id_ID（int FK）為準；沒有才不綁（寧可不綁也不用文字猜）。可重複呼叫。
 */
function ptl_bind_part(PDO $db, int $taskId, int $logId): void
{
    try {
        $oid = el_case_order_id($db, $logId);
        if ($oid <= 0) return;
        $st = $db->prepare("SELECT d.d_id, d.D_Setting_Id FROM order_track o JOIN d_setting d ON d.d_id = o.d_id_ID WHERE o.Order_id=?");
        $st->execute([$oid]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) return;
        $ex = $db->prepare("SELECT 1 FROM personal_task_bind WHERE task_id=? AND bind_type='part' AND bind_id=?");
        $ex->execute([$taskId, (string)$p['d_id']]);
        if (!$ex->fetchColumn()) {
            $db->prepare("INSERT INTO personal_task_bind (task_id, bind_type, bind_id, bind_label, sort_order) VALUES (?,?,?,?,0)")
               ->execute([$taskId, 'part', (string)$p['d_id'], (string)$p['D_Setting_Id']]);
        }
        $db->prepare("UPDATE personal_task SET bind_type='part', bind_id=?, bind_label=? WHERE id=? AND (bind_type IS NULL OR bind_type='')")
           ->execute([(string)$p['d_id'], (string)$p['D_Setting_Id'], $taskId]);
    } catch (Throwable $e) { error_log('ptl_bind_part: ' . $e->getMessage()); }
}

/**
 * 這個人目前有沒有製程中紀錄的某項權限（與 NewOrder_Track.php 的 $proc_* 同一套規則，改一邊要改另一邊）：
 *   新制（被指派任一 ot_proc_*）→ 看對應功能碼；舊制 → 回覆／編輯以「新建/編輯訂單 ot_edit」為準。
 *   resolve（標記已處理）＝ot_design_note_resolve ＋（新制）回覆權限。全站管理員（all）一律可。
 * 個人工作紀錄的「完成／回覆／恢復」會寫進製程中紀錄，所以不能只因為這個人擁有那筆任務就放行：
 * 只有回覆權限的人轉出任務後，不可以靠任務完成鈕繞過「標記已處理」的權限。
 */
function ptl_can(PDO $db, int $uid, string $need): bool
{
    try {
        require_once __DIR__ . '/role_features_helper.php';
        $f = rf_load_user_features_all($db, $uid);
        if (in_array('all', $f, true)) return true;
        $has = function ($c) use ($f) { return in_array($c, $f, true); };
        $new = false;
        foreach (['ot_proc_edit', 'ot_proc_delete', 'ot_proc_reply', 'ot_proc_ask', 'ot_proc_ps', 'ot_proc_view'] as $c) if ($has($c)) $new = true;
        $reply = $new ? $has('ot_proc_reply') : $has('ot_edit');
        if ($need === 'reply') return $reply;
        if ($need === 'resolve') return $has('ot_design_note_resolve') && $reply;
    } catch (Throwable $e) { error_log('ptl_can: ' . $e->getMessage()); }
    return false;
}

/**
 * 製程中紀錄「標示為已處理」只有建立這條主問題的人可以（2026-10-19 使用者指定）。
 * 非製程中紀錄一律放行（設計備註維持原規則）；舊資料 created_by 為 NULL（判不出是誰建立的）
 * 時不卡，仍由「標記已處理」角色權限決定，否則那些舊紀錄永遠沒人能結案。
 * @return string|null 錯誤訊息；null＝可以
 */
function ptl_resolve_denied(PDO $db, int $logId, int $itemId, int $uid): ?string
{
    try {
        $st = $db->prepare("SELECT l.log_type, i.created_by FROM eng_log_item i JOIN eng_log l ON l.id=i.log_id WHERE i.id=? AND i.log_id=?");
        $st->execute([$itemId, $logId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || $r['log_type'] !== 'order_process') return null;
        if ($r['created_by'] === null || $r['created_by'] === '' || (int)$r['created_by'] === $uid) return null;
        return '只有建立這一條主問題的人，才能標示為已處理';
    } catch (Throwable $e) { return null; }
}

/** 任務上直接「回覆」（不完成）：只寫回覆，進度由 el_reply_add 內的同步重建 */
function ptl_reply(PDO $db, int $uid, int $taskId, array $in): void
{
    ptl_ensure($db);
    $st = $db->prepare("SELECT src_item_id, src_log_id FROM personal_task WHERE id=? AND user_id=?");
    $st->execute([$taskId, $uid]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t || empty($t['src_item_id'])) throw new Exception('這筆不是由製程中紀錄建立的');
    if (!ptl_can($db, $uid, 'reply')) throw new Exception('您目前沒有回覆製程中紀錄的權限');
    $nowL = (string)$db->query("SELECT NOW()")->fetchColumn();
    $db->beginTransaction();
    try {
        $in['reply_to'] = 0;   // 任務上的回覆一律是回覆主問題
        el_reply_add($db, (int)$t['src_log_id'], [(int)$t['src_item_id']], $in, $uid, $nowL, substr($nowL, 0, 10));
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    ptl_rebuild_steps($db, (int)$t['src_item_id']);
}

/**
 * 由製程中紀錄的一條建立個人工作紀錄。回傳 ['task_id'=>int,'existed'=>bool]。
 * 同一個人對同一條紀錄只建一筆（再按回傳既有的）。
 */
function ptl_create(PDO $db, int $uid, int $logId, int $itemId, string $deadline, $remindMin, string $extraNote = ''): array
{
    ptl_ensure($db);
    $st = $db->prepare("SELECT i.id, i.question, i.status, i.voided_at, i.is_note, i.src_rule_id, l.log_type
                        FROM eng_log_item i JOIN eng_log l ON l.id=i.log_id WHERE i.id=? AND i.log_id=?");
    $st->execute([$itemId, $logId]);
    $it = $st->fetch(PDO::FETCH_ASSOC);
    if (!$it) throw new Exception('查無此紀錄，請重新整理');
    if ($it['log_type'] !== 'order_process') throw new Exception('只有製程中紀錄可以轉個人工作紀錄');
    if ($it['voided_at'] !== null) throw new Exception('已作廢的紀錄不能轉個人工作紀錄');
    if (!empty($it['src_rule_id'])) throw new Exception('系統提醒不能轉個人工作紀錄');
    // 已處理(resolved)也可以轉：回覆完成後仍可能要改圖等後續工作（2026-11-17）；不處理(dropped)才不需要
    if ($it['status'] === 'dropped') throw new Exception('這一條已標記不處理，不需要再轉個人工作紀錄');

    $ex = $db->prepare("SELECT id FROM personal_task WHERE src_item_id=? AND user_id=? LIMIT 1");
    $ex->execute([$itemId, $uid]);
    $exId = (int)$ex->fetchColumn();
    if ($exId > 0) return ['task_id' => $exId, 'existed' => true];

    $dl = trim($deadline);
    if ($dl !== '') {
        $dl = str_replace('T', ' ', $dl);
        if (strlen($dl) === 10) $dl .= ' 00:00:00';
        elseif (strlen($dl) === 16) $dl .= ':00';
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $dl) || strtotime($dl) === false) throw new Exception('期限格式不正確');
    } else { $dl = null; }
    $rm = ($remindMin === null || $remindMin === '') ? null : (int)$remindMin;
    if ($rm !== null && ($rm < 0 || $rm > 60 * 24 * 60)) throw new Exception('提醒時間不正確');
    if ($rm !== null && $dl === null) throw new Exception('要設定提醒須先設定期限');

    $oid = el_case_order_id($db, $logId);
    $q = trim((string)$it['question']);
    $title = '【製程中紀錄】' . mb_substr(preg_replace('/\s+/u', ' ', $q), 0, 150);

    $db->beginTransaction();
    try {
        $ordTxt = '';
        if ($oid > 0) {   // 不綁訂單（綁了會長出訂單生命週期進度條）；訂單資訊寫在備註
            $o = $db->prepare("SELECT Order_oo, d_id FROM order_track WHERE Order_id=?");
            $o->execute([$oid]);
            $r = $o->fetch(PDO::FETCH_ASSOC);
            if ($r) $ordTxt = '訂單：' . $r['Order_oo'] . '（' . $r['d_id'] . '）' . "
";
        }
        $db->prepare("INSERT INTO personal_task (user_id, title, status, received_at, deadline, remind_before_minutes, remind_sent, note, src_item_id, src_log_id)
                      VALUES (?,?,0,CURDATE(),?,?,0,?,?,?)")
           ->execute([$uid, $title, $dl, $rm, $ordTxt . $q . (trim($extraNote) !== '' ? "

【備註】" . trim($extraNote) : ''), $itemId, $logId]);
        $tid = (int)$db->lastInsertId();
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    ptl_bind_part($db, $tid, $logId);
    ptl_rebuild_steps($db, $itemId);
    return ['task_id' => $tid, 'existed' => false];
}

/**
 * 個人工作紀錄按完成（來源是製程中紀錄的）：寫入回覆＋把該條紀錄標記已處理＋任務設為完成。
 * 呼叫端負責登入與「是這個人的任務」檢查；$in 鍵：content/replied_on/reply_by/channel
 */
function ptl_complete(PDO $db, int $uid, int $taskId, array $in): void
{
    ptl_ensure($db);
    $st = $db->prepare("SELECT src_item_id, src_log_id FROM personal_task WHERE id=? AND user_id=?");
    $st->execute([$taskId, $uid]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t || empty($t['src_item_id'])) throw new Exception('這筆不是由製程中紀錄建立的');
    if (!ptl_can($db, $uid, 'resolve')) throw new Exception('完成會同時把製程中紀錄標記為「已處理」，您沒有這個權限；請改用進度的「回覆」，或請有權限的人處理');
    $itemId = (int)$t['src_item_id'];
    $logId  = (int)$t['src_log_id'];
    if ($m = ptl_resolve_denied($db, $logId, $itemId, $uid)) throw new Exception($m);
    $ck = $db->prepare("SELECT voided_at FROM eng_log_item WHERE id=? AND log_id=?");
    $ck->execute([$itemId, $logId]);
    $row = $ck->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('來源的製程中紀錄已不存在');
    $nowL = (string)$db->query("SELECT NOW()")->fetchColumn();
    $todayL = substr($nowL, 0, 10);
    $db->beginTransaction();
    try {
        if ($row['voided_at'] === null) {
            $in['reply_to'] = 0;
            el_reply_add($db, $logId, [$itemId], $in, $uid, $nowL, $todayL);
            el_item_set_status($db, $logId, $itemId, 'resolved', '', $nowL);   // 內含同步：所有連結的任務一併完成
            el_order_case_sync_status($db, $logId, $nowL);
        }
        $db->prepare("UPDATE personal_task SET status=1, completed_at=NOW() WHERE id=? AND user_id=?")->execute([$taskId, $uid]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
