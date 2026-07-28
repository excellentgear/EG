<?php
/**
 * bom_track_notify.php — BOM 追蹤通知
 *
 * 建立 live_event (ref_type='BOM_TRACK') + live_event_target(user/read)，
 * 比照 car_notify.php 的寫法；推播失敗不影響主流程（try/catch 靜默）。
 */

if (!function_exists('bom_track_notify')) {

/**
 * 找出某群組、對某筆BOM有開啟通知的收件人（群組整體開通知 或 該BOM單獨開通知，皆算）。
 */
function bom_track_resolve_recipients(PDO $pdo, int $groupId, string $bom): array {
    try {
        $st = $pdo->prepare("
            SELECT DISTINCT s.target_type, s.user_id AS target_id
            FROM bom_watch_notify_scope sc
            JOIN bom_watch_subscriber s ON s.scope_id = sc.scope_id
            WHERE sc.group_id = ?
              AND (sc.scope_type = 'group' OR (sc.scope_type = 'bom' AND sc.scope_bom = ?))
        ");
        $st->execute([$groupId, $bom]);
        $userIds = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['target_type'] === 'dept') {
                $q = $pdo->prepare("SELECT DISTINCT user_id FROM user_department_position_map WHERE department_id=?");
                $q->execute([(int)$r['target_id']]);
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $uid) $userIds[] = (int)$uid;
            } else {
                $userIds[] = (int)$r['target_id'];
            }
        }
        return array_values(array_unique(array_filter($userIds)));
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * 發送 BOM 追蹤通知給多位使用者。
 * @param int[] $userIds 收件人（自動去重、剔除 0 與 $actorId 本人）
 * @return int|null live_event id
 */
function bom_track_notify(PDO $pdo, string $bom, string $title, string $content, array $userIds, int $actorId = 0): ?int {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds),
        function ($u) use ($actorId) { return $u > 0 && $u !== $actorId; })));
    if (!$userIds) return null;

    try {
        // ref_id 保留 NULL：BOM 的主鍵是字串(bom.bom)，不是 live_event.ref_id 期望的 int，
        // 由 title/content 直接帶出 BOM 編號供收件人辨識即可。
        $pdo->prepare(
            "INSERT INTO live_event (eventdate, title, content, status, created_by, source, ref_type, show_status_to_others)
             VALUES (CURDATE(), ?, ?, 0, ?, 'BOM追蹤', 'BOM_TRACK', 1)")
            ->execute([$title, $content, ($actorId ?: null)]);
        $eventId = (int)$pdo->lastInsertId();

        $tg = $pdo->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'read')");
        foreach ($userIds as $u) { $tg->execute([$eventId, $u]); }
    } catch (Throwable $e) {
        return null; // 通知建立失敗不阻斷主流程
    }

    // Web Push（未設定 VAPID / 套件缺失時靜默略過）
    try {
        $pushLib = __DIR__ . '/../push/push_send.php';
        if (is_file($pushLib)) {
            require_once $pushLib;
            if (function_exists('eg_push_event_notify')) eg_push_event_notify($pdo, $eventId, $userIds);
        }
    } catch (Throwable $e) {}

    // Telegram（未綁定者自動跳過）
    try {
        $tgLib = __DIR__ . '/../../telegram/notify_event.php';
        if (is_file($tgLib)) {
            require_once $tgLib;
            if (function_exists('eg_telegram_for_event')) eg_telegram_for_event($pdo, $eventId);
        }
    } catch (Throwable $e) {}

    return $eventId;
}

} // guard
