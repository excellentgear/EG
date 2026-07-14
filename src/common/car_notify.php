<?php
/**
 * car_notify.php — 異常矯正處理單 (CAR) 通知
 *
 * 建立 live_event (ref_type='CAR') + live_event_target(user/read)，
 * 並嘗試 Web Push（eg_push_event_notify）與 Telegram（eg_telegram_for_event），
 * 推播失敗不影響主流程（try/catch 靜默）。
 */

if (!function_exists('car_notify')) {

/**
 * 發送 CAR 通知給多位使用者。
 * @param int[] $userIds 收件人（自動去重、剔除 0 與 $actorId 本人）
 * @param string $mode 'read'=看過即消｜'reply'=行動型：完成動作(car_notify_done)前持續顯示於置頂欄未讀
 * @return int|null live_event id
 */
function car_notify(PDO $pdo, int $carId, string $title, string $content, array $userIds, int $actorId = 0, string $mode = 'read'): ?int {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds),
        function ($u) use ($actorId) { return $u > 0 && $u !== $actorId; })));
    if (!$userIds) return null;

    try {
        $pdo->prepare(
            "INSERT INTO live_event (eventdate, title, content, status, created_by, source, ref_type, ref_id, show_status_to_others)
             VALUES (CURDATE(), ?, ?, 0, ?, '異常矯正處理單', 'CAR', ?, 1)")
            ->execute([$title, $content, ($actorId ?: null), $carId]);
        $eventId = (int)$pdo->lastInsertId();

        if ($mode !== 'reply') $mode = 'read';
        $tg = $pdo->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, ?)");
        foreach ($userIds as $u) { $tg->execute([$eventId, $u, $mode]); }

        // 同一單、同收件人的「舊的一般型(read)通知」自動視為已讀——只保留最新一則進度通知。
        // 行動型(reply，如指派您回覆)不在此列，未完成前持續顯示。
        try {
            $old = $pdo->prepare(
                "SELECT e.id, t.target_id FROM live_event e
                 JOIN live_event_target t ON t.live_event_id = e.id AND t.target_type = 'user' AND t.mode = 'read'
                 WHERE e.ref_type = 'CAR' AND e.ref_id = ? AND e.id < ?");
            $old->execute([$carId, $eventId]);
            foreach ($old->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!in_array((int)$r['target_id'], $userIds, true)) continue;
                $chk = $pdo->prepare("SELECT id FROM live_event_response WHERE live_event_id = ? AND user_id = ? LIMIT 1");
                $chk->execute([(int)$r['id'], (int)$r['target_id']]);
                $rid = $chk->fetchColumn();
                if ($rid) $pdo->prepare("UPDATE live_event_response SET read_at = COALESCE(read_at, NOW()) WHERE id = ?")->execute([$rid]);
                else $pdo->prepare("INSERT INTO live_event_response (live_event_id, user_id, read_at) VALUES (?, ?, NOW())")
                         ->execute([(int)$r['id'], (int)$r['target_id']]);
            }
        } catch (Throwable $e) {}
    } catch (Throwable $e) {
        return null;   // 通知建立失敗不阻斷主流程
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

/**
 * 完成行動：把某單所有針對此使用者的 reply 型 CAR 通知標記完成（置頂欄未讀即消失）。
 * 於回覆送出(submit_reply)等節點呼叫。
 */
function car_notify_done(PDO $pdo, int $carId, int $userId): void {
    try {
        $evs = $pdo->prepare(
            "SELECT DISTINCT e.id FROM live_event e
             JOIN live_event_target t ON t.live_event_id = e.id
             WHERE e.ref_type = 'CAR' AND e.ref_id = ? AND t.mode = 'reply'
               AND t.target_type = 'user' AND t.target_id = ?");
        $evs->execute([$carId, $userId]);
        foreach ($evs->fetchAll(PDO::FETCH_COLUMN) as $eid) {
            $chk = $pdo->prepare("SELECT id FROM live_event_response WHERE live_event_id = ? AND user_id = ? LIMIT 1");
            $chk->execute([$eid, $userId]);
            $rid = $chk->fetchColumn();
            if ($rid) {
                $pdo->prepare("UPDATE live_event_response SET read_at=COALESCE(read_at,NOW()),
                                 signed_at=COALESCE(signed_at,NOW()), replied_at=COALESCE(replied_at,NOW()) WHERE id=?")
                    ->execute([$rid]);
            } else {
                $pdo->prepare("INSERT INTO live_event_response (live_event_id, user_id, read_at, signed_at, replied_at)
                               VALUES (?, ?, NOW(), NOW(), NOW())")->execute([$eid, $userId]);
            }
        }
    } catch (Throwable $e) {}
}

/** 某單的首要決策者候選收件人（責任部門主管；廠商責任→生管主管） */
function car_primary_recipients(PDO $pdo, array $o): array {
    $out = [];
    if (($o['resp_type'] ?? '') === 'maker') {
        foreach (car_pm_supervisors($pdo) as $s) $out[] = (int)$s['id'];
    } elseif (!empty($o['resp_dept_id'])) {
        foreach (car_dept_supervisors($pdo, (int)$o['resp_dept_id']) as $s) $out[] = (int)$s['id'];
    }
    return $out;
}

/** 管理課扣款判定收件人：指定判定人員(≤2)優先；未指定則課室成員 */
function car_deduct_recipients(PDO $pdo): array {
    $uids = json_decode(car_setting($pdo, 'car_admin_user_ids', '[]'), true);
    if (is_array($uids) && $uids) return array_map('intval', $uids);
    $out = [];
    $depts = json_decode(car_setting($pdo, 'car_admin_dept_ids', '[]'), true);
    if (is_array($depts) && $depts) {
        $in = implode(',', array_map('intval', $depts));
        $rows = $pdo->query("SELECT DISTINCT m.user_id FROM user_department_position_map m
                             JOIN user u ON u.id = m.user_id
                             WHERE m.department_id IN ($in) AND u.state IN (1,99)")->fetchAll(PDO::FETCH_COLUMN);
        $out = array_map('intval', $rows);
    }
    return $out;
}

/** 通知標題共用格式 */
function car_notify_title(string $emoji, array $o, string $suffix): string {
    $no = $o['car_no'] ?: '（申請中未配號）';
    return $emoji . ' 異常矯正處理單 ' . $no . ' ' . $suffix;
}

/** 通知內文共用格式（單號/責任單位/異常說明摘要） */
function car_notify_body(PDO $pdo, array $o, string $extra = ''): string {
    $lines = [];
    $lines[] = '單　　號：' . ($o['car_no'] ?: '（申請中，核准後配號）');
    if (!empty($o['resp_display'])) $lines[] = '責任單位：' . $o['resp_display'];
    if (!empty($o['drawing_no']))   $lines[] = '料　　號：' . $o['drawing_no'];
    $desc = trim((string)($o['abnormal_desc'] ?? ''));
    if ($desc !== '') $lines[] = '異常說明：' . mb_substr($desc, 0, 60);
    if ($extra !== '') $lines[] = $extra;
    $lines[] = '（請至 異常矯正處理單 頁面查看與處理）';
    return implode("\n", $lines);
}

} // guard
