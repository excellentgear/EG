<?php
/**
 * stock_notify.php — 庫存領料單通知（新增／修改）
 *
 * 建立 live_event (ref_type='STOCK_REQ') + live_event_target(user, mode='read')，
 * 並嘗試 Web Push 與 Telegram（失敗靜默，不影響主流程）。
 * 取代原本 stock.php 自建的 stock_req_notifications 專屬表＋3秒輪詢彈窗
 * （多分頁/多裝置同時開著會重複彈出、且未走全站已讀機制，畫面雜亂）。
 *
 * 收件人展開/共用帳號轉送一律走既有收斂點（見 ai-rules/13-共用帳號通知與綁定.md），
 * 這裡只負責建立 live_event 與 target，不自行展開共用帳號。
 */

if (!function_exists('stock_req_notify')) {

/**
 * @param int[] $userIds 需通知的人員（自動去重、剔除 0 與操作者本人）
 * @return int|null live_event id
 */
function stock_req_notify(PDO $pdo, int $reqId, string $reqNo, string $type, string $message, array $userIds, int $actorId = 0): ?int {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds),
        function ($u) use ($actorId) { return $u > 0 && $u !== $actorId; })));
    if (!$userIds) return null;

    $titlePrefix = ['modified' => '領料單已修改：', 'deleted' => '領料單已刪除：'][$type] ?? '新領料單：';
    $title = $titlePrefix . $reqNo;

    $eventId = null;
    try {
        $pdo->prepare(
            "INSERT INTO live_event (eventdate, title, content, status, created_by, source, ref_type, ref_id, show_status_to_others)
             VALUES (CURDATE(), ?, ?, 0, ?, '庫存領料單', 'STOCK_REQ', ?, 1)")
            ->execute([$title, $message, ($actorId ?: null), $reqId]);
        $eventId = (int)$pdo->lastInsertId();

        // 資訊型通知：mode='read'，收件人開新分頁查看或直接按已閱都行，不強制簽核
        $tg = $pdo->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'read')");
        foreach ($userIds as $u) { $tg->execute([$eventId, $u]); }
    } catch (Throwable $e) {
        return null;   // 通知建立失敗不阻斷主流程（領料單本身已經寫入）
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

}
