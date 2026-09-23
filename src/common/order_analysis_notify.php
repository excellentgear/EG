<?php
/**
 * order_analysis_notify.php — 訂單金額移動平均監控的通知（唯一實作）
 * 建立：2026-09-22（使用者交辦）
 *
 * 做法與 comm_ctrl_notify.php 相同：**live_event（站內通知）＋ Web Push ＋ Telegram 並行**。
 * 一定要寫 live_event——沒綁手機或 Telegram 的人只靠推播收不到，
 * 而這種「訂單量掉下來了」的訊息漏掉一次就是一個月。
 *
 * 觸發條件（管理員可設定，見 oa_settings()）：
 *   前 N 個月訂單金額的移動平均，**連續 M 個月低於安全水平**
 *   （安全水平＝該年度訂單 KPI 的月受訂目標金額，或管理員自訂金額）。
 *
 * 一個月只評估一次：評估完把 end_ym 寫進 ma_last_eval 搶佔，
 * 避免每有人開一次頁面就重算重發（重發比漏發更難察覺）。
 *
 * **資料不足的月份一律不評估**：2026-03 以前幾乎沒有人填單價，那幾個月的訂單金額是 0，
 * 直接拿去比一定會發出假警報。判定在 oa_moving_avg() 裡，這裡只負責把原因寫進通知。
 */

require_once __DIR__ . '/order_analysis_lib.php';

if (!function_exists('oa_notify_body')) {

/** 通知內文（站內通知與 Telegram 共用同一份文字，不要各寫一份） */
function oa_notify_body(PDO $db, array $ma): array
{
    $f = function ($v) { return number_format((float)$v); };
    $last = $ma['last'];
    $thrTxt = $ma['mode'] === 'manual' ? '自訂安全水平' : '訂單 KPI 月受訂目標';

    $title = '訂單量警示：金額移動平均已連續 ' . $ma['streak'] . ' 個月低於安全水平（截至 ' . $last['ym'] . '）';

    $lines = [];
    $lines[] = '判定方式：取「前 ' . $ma['months'] . ' 個月訂單金額的移動平均」，連續 '
             . $ma['need'] . ' 個月低於安全水平即發出本通知。';
    $lines[] = '安全水平來源：' . $thrTxt
             . ($last['threshold'] !== null ? ('（' . $f($last['threshold']) . ' 元／月）') : '（該年度未設定）');
    $lines[] = '';
    $lines[] = '月份　　｜　當月訂單金額　｜　前' . $ma['months'] . '月移動平均　｜　安全水平　｜　判定';
    foreach (array_slice($ma['series'], -6) as $s) {
        $lines[] = $s['ym'] . '　｜ ' . $f($s['amount']) . '　｜ ' . $f($s['avg']) . '　｜ '
                 . ($s['threshold'] === null ? '未設定' : $f($s['threshold'])) . '　｜ '
                 . (!empty($s['unreliable']) ? '資料不足，不評估'
                     : (!empty($s['below']) ? '★ 低於安全水平' : '正常'));
    }
    $lines[] = '';
    $lines[] = '※ 訂單金額只算得出「有填單價」的訂單；當月有填單價的訂單佔比低於 '
             . $ma['min_coverage'] . '% 時，該月視為資料不足、不納入評估（避免發出假警報）。';
    $lines[] = '※ 完整報告（趨勢圖、客戶增減排名、受訂料號排名、自動分析）請開啟「訂單分析」頁面，'
             . '右上角可列印 A3 橫式報告。';

    return ['title' => $title, 'body' => implode("\n", $lines)];
}

/** 送出通知：站內通知＋Web Push＋Telegram。任一管道失敗只記 log，不影響其他管道 */
function oa_notify_send(PDO $db, array $ma, array $uids): int
{
    if (!$uids) return 0;
    $t = oa_notify_body($db, $ma);
    $eid = 0;

    // ── 站內通知（本模組必須有） ──
    try {
        // 上一期還掛著沒被讀掉的同類提醒先收掉，避免通知欄一直疊同一件事
        $db->prepare("UPDATE live_event SET enddate=DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                      WHERE ref_type='ORDER_MA_ALERT' AND (enddate IS NULL OR enddate>=CURDATE())")->execute();

        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source,
                        show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, 0, '訂單分析', 1, 'ORDER_MA_ALERT', 0)")
           ->execute([$t['title'], $t['body']]);
        $eid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode)
                             VALUES (?, 'user', ?, 'read')");
        foreach ($uids as $u) $ins->execute([$eid, (int)$u]);
    } catch (Throwable $e) {
        error_log('[order_ma] live_event failed: ' . $e->getMessage());
    }

    // ── Web Push ──
    try {
        require_once __DIR__ . '/../push/push_send.php';
        $to = ($eid && function_exists('eg_push_event_recipients')) ? eg_push_event_recipients($db, $eid) : $uids;
        eg_push_send_to_users($db, $to, [
            'title' => $t['title'],
            'body'  => mb_substr($t['body'], 0, 480),
            'url'   => '/EGsystem/views/Sales/Order_Analysis.php',
            'tag'   => 'order-ma-' . $ma['last']['ym'],
        ]);
    } catch (Throwable $e) {
        error_log('[order_ma] push failed: ' . $e->getMessage());
    }

    // ── Telegram（訊息內不放 URL，依 telegram spec） ──
    try {
        require_once __DIR__ . '/../../telegram/send_message.php';
        if (function_exists('tg_is_configured') && tg_is_configured()) {
            $in = implode(',', array_map('intval', $uids));
            $st = $db->query("SELECT chat_id FROM telegram_users WHERE is_active=1 AND user_id IN ($in)");
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $chatId) {
                tg_send_text($chatId, $t['title'] . "\n" . $t['body'], $db);
            }
        }
    } catch (Throwable $e) {
        error_log('[order_ma] telegram failed: ' . $e->getMessage());
    }

    return $eid;
}

/**
 * 每月評估一次；已經評估過這一期就直接跳過（搶佔在「評估」不是在「發送」，
 * 否則沒觸發的月份會每次開頁面都重算一次）。
 * @return array 診斷用（給測試與手動觸發看的）
 */
function oa_notify_process(PDO $db, bool $force = false): array
{
    $s = oa_settings($db);
    if (empty($s['ma_enabled']) && !$force) return ['ran' => 0, 'why' => '未啟用移動平均監控'];

    $ma = oa_moving_avg($db);
    $endYm = (string)$ma['end_ym'];
    $last  = oa_param_get($db, 'ma_last_eval', '');
    if (!$force && is_string($last) && $last === $endYm) return ['ran' => 0, 'why' => '本期（' . $endYm . '）已評估過'];

    oa_param_save($db, 'ma_last_eval', $endYm, 'system');

    if (empty($ma['hit'])) return ['ran' => 1, 'sent' => 0, 'end_ym' => $endYm, 'streak' => $ma['streak'], 'why' => '未達觸發條件'];
    $uids = array_values(array_filter(array_map('intval', (array)$s['ma_notify_users'])));
    if (!$uids) return ['ran' => 1, 'sent' => 0, 'end_ym' => $endYm, 'why' => '沒有設定收通知的人員'];

    $eid = oa_notify_send($db, $ma, $uids);
    return ['ran' => 1, 'sent' => count($uids), 'event_id' => $eid, 'end_ym' => $endYm, 'streak' => $ma['streak']];
}

}
