<?php
/**
 * packing_notify.php — 包裝完成通知生管（PM）
 *
 * 建立 live_event (ref_type='PACKING') + live_event_target(user, mode='read')，走全站共用通知機制，
 * 不自行另刻彈窗或輪詢（見 stock_notify.php 同一套寫法）。目前尚無線上出貨模組，這裡只做到「通知生管
 * 這批已包裝完成、可以安排出貨」，待線上出貨功能做好後可直接沿用同一個通知點。
 *
 * 收件人＝module_code='stock'（生管/倉管）擁有完整 CRUD 或管理者權限者，與 stock.php 的
 * getNotifTargetUsers() 同一個定義，不另外發明一套「誰是生管」。
 */

if (!function_exists('pk_packing_notify_targets')) {
    function pk_packing_notify_targets(PDO $pdo): array {
        try {
            return $pdo->query("SELECT DISTINCT user_id FROM user_module_permissions
                                 WHERE module_code='stock'
                                   AND (permission='A' OR (permission LIKE '%C%' AND permission LIKE '%R%' AND permission LIKE '%U%' AND permission LIKE '%D%'))")
                        ->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) { return []; }
    }
}

if (!function_exists('pk_packing_notify_closed')) {
    /**
     * @param string $bom 製令號碼
     * @param string $partNo 料號
     * @param int $qty 本次完成數量（BOM總數，供通知內文參考）
     */
    function pk_packing_notify_closed(PDO $pdo, string $bom, string $partNo, int $qty, int $actorId = 0): ?int {
        $userIds = array_values(array_unique(array_filter(array_map('intval', pk_packing_notify_targets($pdo)),
            function ($u) use ($actorId) { return $u > 0 && $u !== $actorId; })));
        if (!$userIds) return null;

        $title = '包裝完成，可安排出貨：' . $bom;
        $msg = '製令 ' . $bom . ($partNo !== '' ? '（料號 ' . $partNo . '）' : '') . ' 已完成包裝檢驗，數量 ' . $qty . '，請安排出貨事宜。';

        $eventId = null;
        try {
            $pdo->prepare(
                "INSERT INTO live_event (eventdate, title, content, status, created_by, source, ref_type, ref_id, show_status_to_others)
                 VALUES (CURDATE(), ?, ?, 0, ?, '包裝製程排程', 'PACKING', NULL, 1)")
                ->execute([$title, $msg, ($actorId ?: null)]);
            $eventId = (int)$pdo->lastInsertId();

            $tg = $pdo->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'read')");
            foreach ($userIds as $u) { $tg->execute([$eventId, $u]); }
        } catch (Throwable $e) {
            return null;   // 通知建立失敗不阻斷主流程（包裝紀錄本身已經寫入）
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
