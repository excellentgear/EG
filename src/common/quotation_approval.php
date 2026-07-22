<?php
// quotation_approval.php — 報價單主管簽核：業務規則 + 通知（比照 src/common/qa_notify.php 的作法）
// 事實紀錄一律經由 src/common/approval_lib.php 操作 approval_record 共用表；本檔只放報價單專屬的規則與通知。

require_once __DIR__ . '/approval_lib.php';

if (!function_exists('eg_quotation_current_user_name')) {
    /** 查使用者中文名（不可信任 $_SESSION['userName']，那是登入帳號不是中文名） */
    function eg_quotation_current_user_name(PDO $pdo, int $userId): string {
        $st = $pdo->prepare("SELECT user_cname FROM user WHERE id = ?");
        $st->execute([$userId]);
        $name = $st->fetchColumn();
        return $name !== false && $name !== null && $name !== '' ? (string)$name : (string)$userId;
    }
}

if (!function_exists('eg_quotation_user_can_sign')) {
    /** 此使用者目前是否具有「簽核報價單」(quotation_sign) 權限 */
    function eg_quotation_user_can_sign(PDO $pdo, int $userId): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM user_roles ur JOIN role_features rf ON rf.role_id = ur.role_id WHERE ur.user_id = ? AND rf.feature_code = 'quotation_sign'");
        $st->execute([$userId]);
        return (int)$st->fetchColumn() > 0;
    }
}

if (!function_exists('eg_quotation_signers')) {
    /** 目前所有具「簽核報價單」權限的使用者 id（仿 qa_notify.php eg_qa_supervisors） */
    function eg_quotation_signers(PDO $pdo): array {
        try {
            $rows = $pdo->query("SELECT DISTINCT ur.user_id FROM user_roles ur JOIN role_features rf ON rf.role_id = ur.role_id WHERE rf.feature_code = 'quotation_sign'")->fetchAll(PDO::FETCH_COLUMN);
            return array_values(array_unique(array_map('intval', $rows)));
        } catch (Throwable $e) { return []; }
    }
}

if (!function_exists('eg_quotation_missing_required_attach')) {
    /**
     * 伺服器端二次驗證必備附件完整性（不可只信前端送來的 is_draft）。
     * 邏輯比照 quotation_list_NEW.php 前端 getMissingRequiredAttach()/_catSetForPart()：
     * 一般類別＝明確連結或共用附件皆算；必備類別＝只有明確連結該料號才算，共用附件不算。
     * 回傳缺漏清單：[['product_id'=>..,'missing_cat_ids'=>[..]], ...]，空陣列＝完整無缺漏。
     */
    function eg_quotation_missing_required_attach(PDO $pdo, string $quoteNo, array $productIds): array {
        $productIds = array_values(array_unique(array_filter(array_map('strval', $productIds))));
        if (empty($productIds)) return [];

        $reqRaw = $pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group='QUOTATION' AND param_key='required_attach_cats'");
        $reqRaw->execute();
        $reqCats = json_decode((string)$reqRaw->fetchColumn(), true);
        $reqCats = is_array($reqCats) ? array_map('intval', $reqCats) : [];
        if (empty($reqCats)) return [];

        $pageRaw = $pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group='QUOTATION' AND param_key='page_attach_cats'");
        $pageRaw->execute();
        $pageCats = json_decode((string)$pageRaw->fetchColumn(), true);
        if (is_array($pageCats) && !empty($pageCats)) {
            $pageCats = array_map('intval', $pageCats);
            $reqCats  = array_values(array_intersect($reqCats, $pageCats));
        }
        if (empty($reqCats)) return [];

        // 必備附件檢查：計入本張單自己的暫存(temp，存檔當下)與正式(active)附件，排除補件待審(pending)與已否決(trash)
        $atts = $pdo->prepare("SELECT category_ids, linked_parts FROM quotation_attachments WHERE quote_no = ? AND status IN ('temp','active')");
        $atts->execute([$quoteNo]);
        $files = [];
        foreach ($atts->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $cats = array_values(array_filter(array_map('intval', explode(',', (string)$a['category_ids']))));
            $parts = null;
            if (!empty($a['linked_parts'])) {
                $decoded = json_decode($a['linked_parts'], true);
                if (is_array($decoded)) $parts = array_map('strval', $decoded);
            }
            $files[] = ['cats' => $cats, 'parts' => $parts];
        }

        $missing = [];
        foreach ($productIds as $pid) {
            $have = [];
            foreach ($files as $f) {
                $explicit = $f['parts'] !== null && in_array($pid, $f['parts'], true);
                $shared   = $f['parts'] === null;
                foreach ($f['cats'] as $c) {
                    $isReq = in_array($c, $reqCats, true);
                    if ($explicit || ($shared && !$isReq)) $have[$c] = true;
                }
            }
            $lack = array_values(array_diff($reqCats, array_keys($have)));
            if ($lack) $missing[] = ['product_id' => $pid, 'missing_cat_ids' => $lack];
        }
        return $missing;
    }
}

if (!function_exists('eg_quotation_notify_approval')) {
    /** 建立審核通知（送給所有具 quotation_sign 權限者，mode=sign 動作完成前不消失）。回傳 live_event id（失敗回 0）。 */
    function eg_quotation_notify_approval(PDO $pdo, int $quoteId, string $quoteNo, int $submittedByUid, string $submittedByName): int {
        $signers = eg_quotation_signers($pdo);
        if (empty($signers)) return 0;
        try {
            // 防重複：同一張單只能有一則活著的待簽核通知——發新通知前先結束舊的
            // （歷史上曾因重複送審累積多則，簽核者置頂欄看到同單多則通知）
            $pdo->prepare("UPDATE live_event SET enddate = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                           WHERE ref_type='QUOTATION_APPROVAL' AND ref_id=? AND (enddate IS NULL OR enddate >= CURDATE())")
                ->execute([$quoteId]);
            $title   = '報價單待簽核：' . $quoteNo;
            $content = $submittedByName . ' 送出報價單 ' . $quoteNo . '，請簽核。';
            $pdo->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                          VALUES (CURDATE(), NULL, ?, ?, 0, ?, '報價單簽核', 1, 'QUOTATION_APPROVAL', ?)")
                ->execute([$title, $content, $submittedByUid, $quoteId]);
            $eventId = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'sign')");
            foreach ($signers as $uid) $ins->execute([$eventId, $uid]);

            try {
                require_once __DIR__ . '/../push/push_send.php';
                $recipients = eg_push_event_recipients($pdo, $eventId);
                eg_push_send_to_users($pdo, $recipients, ['title' => $title, 'body' => mb_substr($content, 0, 480)]);
            } catch (Throwable $e) { /* 推播失敗不影響簽核流程 */ }

            return $eventId;
        } catch (Throwable $e) {
            error_log('[quotation_approval] notify_approval failed: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('eg_quotation_close_approval_notice')) {
    /** 任一簽核人完成核准/駁回 → 結束此張報價單的待簽核通知（比照 qa_notify.php eg_qa_close_decision_events） */
    function eg_quotation_close_approval_notice(PDO $pdo, int $quoteId, int $deciderUid): void {
        try {
            $st = $pdo->prepare("SELECT id FROM live_event WHERE ref_type='QUOTATION_APPROVAL' AND ref_id=? AND (enddate IS NULL OR enddate >= CURDATE())");
            $st->execute([$quoteId]);
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
            error_log('[quotation_approval] close_approval_notice failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('eg_quotation_notify_result')) {
    /** 核准/駁回結果通知原送審人（mode=read，一般已閱型通知） */
    function eg_quotation_notify_result(PDO $pdo, int $quoteId, string $quoteNo, int $submittedByUid, string $deciderName, string $decision, ?string $note): void {
        if (!$submittedByUid) return;
        try {
            if ($decision === 'approved') {
                $title   = '報價單已核准：' . $quoteNo;
                $content = $deciderName . ' 已核准報價單 ' . $quoteNo . ($note ? '（意見：' . $note . '）' : '');
            } else {
                $title   = '報價單被駁回：' . $quoteNo;
                $content = $deciderName . ' 駁回了報價單 ' . $quoteNo . '，原因：' . ($note ?: '（未填寫原因）') . '，請修改後重新送出審核。';
            }
            $pdo->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                          VALUES (CURDATE(), NULL, ?, ?, 0, NULL, '報價單簽核', 1, 'QUOTATION_APPROVAL_RESULT', ?)")
                ->execute([$title, $content, $quoteId]);
            $eventId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'read')")
                ->execute([$eventId, $submittedByUid]);

            try {
                require_once __DIR__ . '/../push/push_send.php';
                $recipients = eg_push_event_recipients($pdo, $eventId);
                eg_push_send_to_users($pdo, $recipients, ['title' => $title, 'body' => mb_substr($content, 0, 480)]);
            } catch (Throwable $e) { /* 推播失敗不影響簽核流程 */ }
        } catch (Throwable $e) {
            error_log('[quotation_approval] notify_result failed: ' . $e->getMessage());
        }
    }
}
