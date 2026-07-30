<?php
/**
 * dwg_notify.php — 圖面變更簽收通知（AS 2-PD-01-07）
 *
 * 建立 live_event (ref_type='DWG') + live_event_target(user/reply)，
 * 並嘗試 Web Push 與 Telegram（失敗靜默，不影響主流程）。
 * 用 mode='reply'＝行動型：簽收前會持續顯示在置頂未讀，避免「看過就消失、忘了簽」。
 *
 * 收件人展開/共用帳號轉送一律走既有收斂點（見 ai-rules/13-共用帳號通知與綁定.md），
 * 這裡只負責建立 live_event 與 target，不自行展開共用帳號。
 */

if (!function_exists('dwg_notify')) {

/**
 * @param int[] $userIds 需簽收的人員（自動去重、剔除 0 與發起人本人）
 * @return int|null live_event id
 */
function dwg_notify(PDO $pdo, int $changeId, string $title, string $content, array $userIds, int $actorId = 0, string $mode = 'reply'): ?int {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds),
        function ($u) use ($actorId) { return $u > 0 && $u !== $actorId; })));
    if (!$userIds) return null;

    $eventId = null;
    try {
        $pdo->prepare(
            "INSERT INTO live_event (eventdate, title, content, status, created_by, source, ref_type, ref_id, show_status_to_others)
             VALUES (CURDATE(), ?, ?, 0, ?, '圖面變更簽收單', 'DWG', ?, 1)")
            ->execute([$title, $content, ($actorId ?: null), $changeId]);
        $eventId = (int)$pdo->lastInsertId();

        if ($mode !== 'reply') $mode = 'read';
        $tg = $pdo->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, ?)");
        foreach ($userIds as $u) { $tg->execute([$eventId, $u, $mode]); }
    } catch (Throwable $e) {
        return null;   // 通知建立失敗不阻斷主流程（變更紀錄本身已經寫入）
    }

    try {
        $pushLib = __DIR__ . '/../push/push_send.php';
        if (is_file($pushLib)) {
            require_once $pushLib;
            if (function_exists('eg_push_event_notify')) eg_push_event_notify($pdo, $eventId, $userIds);
        }
    } catch (Throwable $e) {}

    try {
        $tgLib = __DIR__ . '/../../telegram/notify_event.php';
        if (is_file($tgLib)) {
            require_once $tgLib;
            if (function_exists('eg_telegram_for_event')) eg_telegram_for_event($pdo, $eventId);
        }
    } catch (Throwable $e) {}

    return $eventId;
}

/**
 * 某人已簽收 → 把他在這張變更單的行動型通知標記為已讀已簽，讓置頂未讀消失。
 */
function dwg_notify_done(PDO $pdo, int $changeId, int $userId): void {
    try {
        $q = $pdo->prepare(
            "SELECT e.id FROM live_event e
             JOIN live_event_target t ON t.live_event_id = e.id AND t.target_type='user' AND t.target_id = ?
             WHERE e.ref_type='DWG' AND e.ref_id = ?");
        $q->execute([$userId, $changeId]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $eid) {
            $chk = $pdo->prepare("SELECT id FROM live_event_response WHERE live_event_id=? AND user_id=? LIMIT 1");
            $chk->execute([(int)$eid, $userId]);
            if ($rid = $chk->fetchColumn()) {
                $pdo->prepare("UPDATE live_event_response SET read_at=COALESCE(read_at,NOW()), signed_at=COALESCE(signed_at,NOW()) WHERE id=?")
                    ->execute([$rid]);
            } else {
                $pdo->prepare("INSERT INTO live_event_response (live_event_id, user_id, read_at, signed_at) VALUES (?,?,NOW(),NOW())")
                    ->execute([(int)$eid, $userId]);
            }
        }
    } catch (Throwable $e) {}
}

}
