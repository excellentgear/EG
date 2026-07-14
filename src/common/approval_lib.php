<?php
// approval_lib.php — 跨模組共用簽核紀錄函式庫
// 資料表 approval_record：module+entity_id+level 命名空間；只記事實（誰送審/誰簽/何時/意見），
// 「誰有資格簽、單層或多層、OR-gate或AND-gate、自己簽自己免審」等業務規則一律留在各模組自己的程式，不寫在這裡。
// 每次送審都是新的一筆紀錄（保留完整歷史，不覆蓋舊列）；某 module+entity_id 的「目前狀態」＝最新一筆。

if (!function_exists('eg_approval_submit')) {
    /** 送出一筆新的待簽核紀錄，回傳 approval_record.id */
    function eg_approval_submit(PDO $pdo, string $module, int $entityId, string $level, int $submittedBy, string $submittedByName): int {
        $stmt = $pdo->prepare("INSERT INTO approval_record
            (module, entity_id, level, status, submitted_by, submitted_by_name, submitted_at)
            VALUES (?, ?, ?, 'pending', ?, ?, NOW())");
        $stmt->execute([$module, $entityId, $level, $submittedBy, $submittedByName]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('eg_approval_latest')) {
    /** 取得某 module+entity_id（可選 level）目前最新一筆簽核紀錄；查無則回傳 null */
    function eg_approval_latest(PDO $pdo, string $module, int $entityId, ?string $level = null): ?array {
        if ($level !== null) {
            $stmt = $pdo->prepare("SELECT * FROM approval_record WHERE module=? AND entity_id=? AND level=? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$module, $entityId, $level]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM approval_record WHERE module=? AND entity_id=? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$module, $entityId]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('eg_approval_set_live_event')) {
    /** 送審時建立的通知 live_event_id 補寫回該筆簽核紀錄，供之後 OR-gate 解除通知時查找 */
    function eg_approval_set_live_event(PDO $pdo, int $approvalId, int $liveEventId): void {
        $pdo->prepare("UPDATE approval_record SET live_event_id=? WHERE id=?")->execute([$liveEventId, $approvalId]);
    }
}

if (!function_exists('eg_approval_decide')) {
    /**
     * 核准或駁回一筆待簽核紀錄。用 transaction + WHERE status='pending' 搶簽，
     * 避免多人同時處理同一筆（OR-gate 場景下常見）。
     * $decision 只接受 'approved' 或 'rejected'。
     * 回傳 ['success'=>bool, 'message'=>string, 'record'=>array|null]；
     * 搶輸（已被別人簽過）時 success=false，record 帶目前實際狀態供呼叫端顯示「已被OOO審核過」。
     */
    function eg_approval_decide(PDO $pdo, int $approvalId, int $userId, string $userName, string $decision, ?string $note = null): array {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            return ['success' => false, 'message' => '無效的簽核決定', 'record' => null];
        }
        $wasInTransaction = $pdo->inTransaction();
        if (!$wasInTransaction) $pdo->beginTransaction();
        try {
            $sel = $pdo->prepare("SELECT * FROM approval_record WHERE id=? FOR UPDATE");
            $sel->execute([$approvalId]);
            $rec = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$rec) {
                if (!$wasInTransaction) $pdo->rollBack();
                return ['success' => false, 'message' => '找不到此簽核紀錄', 'record' => null];
            }
            if ($rec['status'] !== 'pending') {
                if (!$wasInTransaction) $pdo->rollBack();
                $verb = $rec['status'] === 'approved' ? '核准' : '駁回';
                return [
                    'success' => false,
                    'message' => '此筆已被 ' . ($rec['approver_name'] ?: '其他人') . " {$verb}過，不可重複處理",
                    'record' => $rec,
                ];
            }
            $upd = $pdo->prepare("UPDATE approval_record SET status=?, approver_id=?, approver_name=?, note=?, decided_at=NOW() WHERE id=? AND status='pending'");
            $upd->execute([$decision, $userId, $userName, $note, $approvalId]);
            if ($upd->rowCount() === 0) {
                // 極端情況：FOR UPDATE 鎖到了但同時被別人搶先 commit（理論上不會發生，防禦性處理）
                if (!$wasInTransaction) $pdo->rollBack();
                return ['success' => false, 'message' => '此筆剛被其他人處理，請重新整理後再試', 'record' => null];
            }
            if (!$wasInTransaction) $pdo->commit();
            // 重新查一次拿權威的 decided_at（DB端 NOW()），避免回傳值跟實際存的不一致
            $reSel = $pdo->prepare("SELECT * FROM approval_record WHERE id=?");
            $reSel->execute([$approvalId]);
            $rec = $reSel->fetch(PDO::FETCH_ASSOC) ?: $rec;
            return ['success' => true, 'message' => '處理成功', 'record' => $rec];
        } catch (Throwable $e) {
            if (!$wasInTransaction && $pdo->inTransaction()) $pdo->rollBack();
            return ['success' => false, 'message' => '簽核處理失敗：' . $e->getMessage(), 'record' => null];
        }
    }
}
