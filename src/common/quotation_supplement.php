<?php
// quotation_supplement.php — 「已審核通過報價單」附件補件重審的通知（比照 quotation_approval.php）。
// 事實紀錄一律經 approval_lib.php 的 approval_record 共用表（module='quotation_attach'，entity_id=附件id）；
// 本檔只放補件專屬的通知（送簽核者/解除通知/結果回覆上傳者）。
// 只審核「是否允許此附件放進這張報價單」，不動整張報價單的簽核狀態。

require_once __DIR__ . '/approval_lib.php';
require_once __DIR__ . '/quotation_approval.php'; // 沿用 eg_quotation_signers()

if (!function_exists('eg_quot_supp_notify_request')) {
    /** 補件送審 → 通知所有具 quotation_sign 權限者（mode=sign，決定前不消失）。回傳 live_event id（失敗回 0）。 */
    function eg_quot_supp_notify_request(PDO $pdo, int $attId, string $quoteNo, string $partLabel, int $uploaderUid, string $uploaderName): int {
        $signers = eg_quotation_signers($pdo);
        if (empty($signers)) return 0;
        try {
            // 防重複：同一件補件附件只能有一則活著的待審通知
            $pdo->prepare("UPDATE live_event SET enddate = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                           WHERE ref_type='QUOTATION_SUPP' AND ref_id=? AND (enddate IS NULL OR enddate >= CURDATE())")
                ->execute([$attId]);
            $title   = '報價單附件補件待審：' . $quoteNo;
            $content = $uploaderName . ' 為已核准報價單 ' . $quoteNo . ' 補上附件'
                     . ($partLabel !== '' ? '（' . $partLabel . '）' : '') . '，請審核是否允許放入此報價單。';
            $pdo->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                          VALUES (CURDATE(), NULL, ?, ?, 0, ?, '報價單補件', 1, 'QUOTATION_SUPP', ?)")
                ->execute([$title, $content, $uploaderUid ?: null, $attId]);
            $eventId = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'sign')");
            foreach ($signers as $uid) $ins->execute([$eventId, $uid]);

            try {
                require_once __DIR__ . '/../push/push_send.php';
                $recipients = eg_push_event_recipients($pdo, $eventId);
                eg_push_send_to_users($pdo, $recipients, ['title' => $title, 'body' => mb_substr($content, 0, 480)]);
            } catch (Throwable $e) { /* 推播失敗不影響流程 */ }

            return $eventId;
        } catch (Throwable $e) {
            error_log('[quot_supp] notify_request failed: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('eg_quot_supp_close_notice')) {
    /** 任一簽核人完成核准/駁回 → 結束此補件的待審通知（比照 eg_quotation_close_approval_notice） */
    function eg_quot_supp_close_notice(PDO $pdo, int $attId, int $deciderUid): void {
        try {
            $st = $pdo->prepare("SELECT id FROM live_event WHERE ref_type='QUOTATION_SUPP' AND ref_id=? AND (enddate IS NULL OR enddate >= CURDATE())");
            $st->execute([$attId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $eid) {
                $eid = (int)$eid;
                $pdo->prepare("UPDATE live_event SET enddate = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id=?")->execute([$eid]);
                $rs = $pdo->prepare("SELECT id FROM live_event_response WHERE live_event_id=? AND user_id=?");
                $rs->execute([$eid, $deciderUid]);
                if ($rid = $rs->fetchColumn()) {
                    $pdo->prepare("UPDATE live_event_response SET read_at=COALESCE(read_at,NOW()), signed_at=COALESCE(signed_at,NOW()) WHERE id=?")->execute([$rid]);
                } else {
                    $pdo->prepare("INSERT INTO live_event_response (live_event_id, user_id, read_at, signed_at) VALUES (?,?,NOW(),NOW())")->execute([$eid, $deciderUid]);
                }
            }
        } catch (Throwable $e) {
            error_log('[quot_supp] close_notice failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('eg_quot_supp_notify_result')) {
    /** 補件核准/駁回結果通知上傳者（mode=read）。駁回時告知原因＋此附件已刪除。 */
    function eg_quot_supp_notify_result(PDO $pdo, int $attId, string $quoteNo, int $uploaderUid, string $fileLabel, string $deciderName, string $decision, ?string $reason): void {
        if (!$uploaderUid) return;
        try {
            if ($decision === 'approved') {
                $title   = '補件已通過：' . $quoteNo;
                $content = $deciderName . ' 已核准您為報價單 ' . $quoteNo . ' 補上的附件「' . $fileLabel . '」，已正式放入此報價單。';
            } else {
                $title   = '補件未通過：' . $quoteNo;
                $content = $deciderName . ' 未通過您為報價單 ' . $quoteNo . ' 補上的附件「' . $fileLabel . '」，原因：'
                         . ($reason ?: '（未填寫原因）') . '。此附件已刪除。';
            }
            $pdo->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                          VALUES (CURDATE(), NULL, ?, ?, 0, NULL, '報價單補件', 1, 'QUOTATION_SUPP_RESULT', ?)")
                ->execute([$title, $content, $attId]);
            $eventId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'read')")
                ->execute([$eventId, $uploaderUid]);

            try {
                require_once __DIR__ . '/../push/push_send.php';
                $recipients = eg_push_event_recipients($pdo, $eventId);
                eg_push_send_to_users($pdo, $recipients, ['title' => $title, 'body' => mb_substr($content, 0, 480)]);
            } catch (Throwable $e) { /* 推播失敗不影響流程 */ }
        } catch (Throwable $e) {
            error_log('[quot_supp] notify_result failed: ' . $e->getMessage());
        }
    }
}
