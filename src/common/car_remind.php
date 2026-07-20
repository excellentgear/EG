<?php
/**
 * car_remind.php — 異常矯正處理單 (CAR) 逾期提醒
 *
 * 卡在同一關卡超過設定工作天數（car_remind_working_days，預設 5）未往下走，
 * 依「目前狀態」通知對應人員；往前推進到下一關則重新計算（stage_since 前跳自動失效舊提醒基準）。
 *
 * 提醒對象與型態（使用者定案）：
 *   - 填寫階段(assigned/replying)：責任人 = 行動型(reply，比照「指派您回覆」，送出即清)；主管/最終決策者 = 已閱型(read)
 *   - 其餘階段：全部已閱型(read)，每滿一個週期自動再出現一次
 *
 * 由 car_remind_run.php (CLI) 呼叫。相依 car_lib.php / car_notify.php 的函式。
 */

if (!function_exists('car_remind_recipients')) {

/** 依單據目前狀態，回傳提醒收件人 ['reply'=>int[], 'read'=>int[]]（尚未去重，car_notify 會處理） */
function car_remind_recipients(PDO $pdo, array $o): array {
    $status = (string)($o['status'] ?? '');
    $created = (int)($o['created_by'] ?? 0);
    $assignee = (int)($o['assigned_to'] ?? 0) ?: (int)($o['resp_person_id'] ?? 0);
    $reply = []; $read = [];

    $primarySup = function () use ($pdo, $o) { return array_map('intval', car_primary_recipients($pdo, $o)); };
    $finalIds   = function () use ($pdo) { return array_map(function ($d) { return (int)$d['id']; }, car_final_deciders($pdo)); };
    $openerSup  = function () use ($pdo, $o) {
        if (empty($o['opener_dept_id'])) return [];
        return array_map(function ($s) { return (int)$s['id']; }, car_dept_supervisors($pdo, (int)$o['opener_dept_id']));
    };

    switch ($status) {
        case 'applying':        // 尚未審核 → 審核主管 + 開單人
            $read = array_merge($openerSup(), [$created]);
            break;
        case 'app_rejected':    // 開立被退回 → 只通知開單人
            $read = [$created];
            break;
        case 'open':            // 待指派 → 責任單位主管 + 開單人 + 最終決策者
            $read = array_merge($primarySup(), [$created], $finalIds());
            break;
        case 'assigned':
        case 'replying':        // 責任人未填完 → 責任人(行動型) + 責任單位主管 + 最終決策者(已閱)
            if ($assignee) $reply = [$assignee];
            $read = array_merge($primarySup(), $finalIds());
            break;
        case 'pending_primary': // 責任人填完、主管未簽 → 責任單位主管 + 最終決策者
            $read = array_merge($primarySup(), $finalIds());
            break;
        case 'pending_final':   // 主管已簽、最終未裁 → 最終決策者 + 開單人
            $read = array_merge($finalIds(), [$created]);
            break;
        default:                // closed / draft / rejected：不提醒
            break;
    }
    return ['reply' => $reply, 'read' => $read];
}

/**
 * 掃描所有卡關逾期的單據並發送提醒。
 * @return int 實際送出提醒的單據數
 */
function car_remind_scan_and_send(PDO $pdo): int {
    // 總開關（預設開）
    if (car_setting($pdo, 'car_remind_enabled', '1') === '0') return 0;
    $threshold = (int)(car_setting($pdo, 'car_remind_working_days', '5') ?: 5);
    if ($threshold < 1) $threshold = 1;
    $today = date('Y-m-d');

    $remindable = ['applying', 'app_rejected', 'open', 'assigned', 'replying', 'pending_primary', 'pending_final'];
    $in = "'" . implode("','", $remindable) . "'";
    $rows = $pdo->query("SELECT * FROM car_order WHERE status IN ($in)")->fetchAll(PDO::FETCH_ASSOC);

    $labels = car_labels()['status'];
    $sent = 0;
    foreach ($rows as $o) {
        // 起算基準：以 stage_since 為主，上次提醒後則從上次提醒起算（達門檻才再發，形成每 N 工作天一次）
        $anchor = $o['stage_since'] ?: $o['created_at'];
        if (!empty($o['last_remind_at']) && $o['last_remind_at'] > $anchor) $anchor = $o['last_remind_at'];
        if (!$anchor) continue;
        if (car_working_days_between($pdo, (string)$anchor, $today) < $threshold) continue;

        // 已在同一關卡累計幾個工作天（顯示用）
        $wdStage = car_working_days_between($pdo, (string)($o['stage_since'] ?: $o['created_at']), $today);
        $statusLabel = $labels[$o['status']] ?? $o['status'];

        $rcpt = car_remind_recipients($pdo, $o);
        if (!$rcpt['reply'] && !$rcpt['read']) continue;

        $title = car_notify_title('⏰', $o, "逾期提醒");
        $body  = car_notify_body($pdo, $o,
            "目前狀態：{$statusLabel}\n已停留：{$wdStage} 個工作天（超過 {$threshold} 個工作天未處理）\n請儘速處理。");

        $anySent = false;
        // 行動型（責任人）
        if ($rcpt['reply']) {
            $ev = car_notify($pdo, (int)$o['id'], $title, $body, $rcpt['reply'], 0, 'reply');
            if ($ev) $anySent = true;
        }
        // 已閱型（其餘關係人）——排除已在行動型名單者，避免同人重複
        $readIds = array_values(array_diff(array_map('intval', $rcpt['read']), array_map('intval', $rcpt['reply'])));
        if ($readIds) {
            $ev = car_notify($pdo, (int)$o['id'], $title, $body, $readIds, 0, 'read');
            if ($ev) $anySent = true;
        }

        if ($anySent) {
            $pdo->prepare("UPDATE car_order SET last_remind_at = NOW() WHERE id = ?")->execute([(int)$o['id']]);
            car_log($pdo, (int)$o['id'], 'remind', null, '系統', "逾期提醒（{$statusLabel}，已 {$wdStage} 個工作天）");
            $sent++;
        }
    }
    return $sent;
}

} // guard
