<?php
// Web Push 發送核心。
//   eg_push_send_to_users($db, $userIds, $payload)  依 User ID 撈訂閱憑證並發送推播
//   eg_push_event_recipients($db, $eventId)          解析某公告的對象 → 實際收件 user_id 清單
//   eg_push_for_event($db, $eventId)                 依公告內容組推播訊息並發送給其所有對象
//
// 使用函式庫：minishlink/web-push（VAPID 簽章、加密、送至 FCM/APNs 閘道）
// 需要 PHP 擴充：gmp、curl、openssl、mbstring（Apache php.ini 需啟用 gmp）

require_once __DIR__ . '/../../vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

if (!function_exists('eg_push_webpush')) {
    /** 建立設定好 VAPID 的 WebPush 實例 */
    function eg_push_webpush(): ?WebPush
    {
        $cfg = require __DIR__ . '/push_config.php';
        if (empty($cfg['publicKey']) || empty($cfg['privateKey'])) return null;
        // TLS 憑證驗證：MAMP 的 PHP 未設定 curl.cainfo，若不指定 CA 會出現 cURL error 60。
        // 這裡指向隨系統附帶的 cacert.pem，正常驗證 FCM/APNs 的憑證。
        // fail-closed：cacert.pem 遺失時「拒絕發送」而非退回不驗證（避免中間人風險）。
        $caFile = __DIR__ . '/cacert.pem';
        if (!is_file($caFile)) {
            error_log('[push] cacert.pem 遺失（' . $caFile . '），基於安全拒絕發送。請重新放置 CA bundle。');
            return null;
        }
        $clientOptions = ['verify' => $caFile];
        try {
            return new WebPush([
                'VAPID' => [
                    'subject'    => $cfg['subject'],
                    'publicKey'  => $cfg['publicKey'],
                    'privateKey' => $cfg['privateKey'],
                ],
            ], [], 10, $clientOptions); // 逾時 10 秒 + 指定 CA bundle
        } catch (\Throwable $e) {
            error_log('[push] WebPush init failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('eg_push_sub_ensure_schema')) {
    /** push_subscription 失效標記欄位（規格 6-5：404/410 設 is_active=0 而非刪除，供管理者辨識並通知重新綁定） */
    function eg_push_sub_ensure_schema(PDO $db): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $cols = $db->query("SHOW COLUMNS FROM push_subscription")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('is_active', $cols, true)) {
                $db->exec("ALTER TABLE push_subscription ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0=失效(404/410)'");
                $db->exec("ALTER TABLE push_subscription ADD COLUMN `deactivated_at` DATETIME NULL COMMENT '失效時間'");
                $db->exec("ALTER TABLE push_subscription ADD COLUMN `fail_reason` VARCHAR(100) NULL COMMENT '失效原因'");
            }
        } catch (Throwable $e) { error_log('[push] ensure sub schema failed: ' . $e->getMessage()); }
    }
}

if (!function_exists('eg_push_active_user_filter')) {
    /**
     * 員工狀態過濾（規格 6-1）：只有 在職(1)/最高權限(99) 可收推播；
     * 離職(0)、留職停薪(2)、育嬰留停(3)、特殊帳號(90) 一律排除。發送前必呼叫。
     *
     * 例外：共用帳號（is_shared_account=1）即使 state=90（現場共用帳號多半是 90）也必須放行，
     * 否則成員的通知轉送過去會被這道過濾整批丟掉（見 ai-rules/13）。
     */
    function eg_push_active_user_filter(PDO $db, array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (empty($userIds)) return [];
        $in = implode(',', $userIds);
        $cond = "state IN (1,99)";
        require_once __DIR__ . '/../common/shared_account_lib.php';
        if (eg_shared_ready($db)) $cond = "(state IN (1,99) OR is_shared_account = 1)";
        return array_map('intval', $db->query("SELECT id FROM user WHERE id IN ($in) AND $cond")->fetchAll(PDO::FETCH_COLUMN));
    }
}

if (!function_exists('eg_push_send_to_users')) {
    /**
     * 依 User ID 清單發送推播。**全站 Web Push 的唯一出口**，共用帳號轉送就在這裡展開
     * （各模組零修改自動受益，見 ai-rules/13）。
     *
     * @param PDO   $db
     * @param int[] $userIds  原收件者 user.id
     * @param array $payload  推播內容，如 ['title'=>..,'body'=>..,'url'=>..,'tag'=>..]
     * @param array $opts     ['event_id'=>int] 有公告來源時傳入：只有被「指名(target_type='user')」的
     *                        收件人才轉送到共用帳號，全體/部門/身分廣播不逐人轉送（避免現場平板洗版）。
     *                        不傳＝視同全部指名（模組直接指定收件人時的情況）。
     * @return array ['ok'=>bool,'sent'=>int,'failed'=>int,'removed'=>int]
     */
    function eg_push_send_to_users(PDO $db, array $userIds, array $payload, array $opts = []): array
    {
        try {
            require_once __DIR__ . '/../common/shared_account_lib.php';
            $fanoutOnly = isset($opts['event_id'])
                ? eg_shared_named_recipients($db, (int)$opts['event_id'])
                : null;
            $entries = eg_shared_fanout($db, $userIds, $fanoutOnly);
        } catch (\Throwable $e) {
            error_log('[push] shared fanout failed: ' . $e->getMessage());
            $entries = [];
        }
        if (empty($entries)) return eg_push_send_raw($db, $userIds, $payload);

        // 本人自己收的併成一批（原樣）；轉送到共用帳號的「每位成員各一則」（各自前綴姓名）。
        $selfIds = [];
        $byMember = [];   // for_uid => ['name'=>.., 'deliver'=>[uid,..]]
        foreach ($entries as $en) {
            if ($en['deliver_uid'] === $en['for_uid']) { $selfIds[] = $en['deliver_uid']; continue; }
            $f = $en['for_uid'];
            if (!isset($byMember[$f])) $byMember[$f] = ['name' => $en['for_name'], 'deliver' => []];
            $byMember[$f]['deliver'][] = $en['deliver_uid'];
        }

        $agg = ['ok' => true, 'sent' => 0, 'failed' => 0, 'removed' => 0];
        $merge = function (array $r) use (&$agg) {
            if (empty($r['ok'])) $agg['ok'] = false;
            $agg['sent']    += (int)($r['sent'] ?? 0);
            $agg['failed']  += (int)($r['failed'] ?? 0);
            $agg['removed'] += (int)($r['removed'] ?? 0);
        };

        if ($selfIds) $merge(eg_push_send_raw($db, $selfIds, $payload));
        foreach ($byMember as $forUid => $g) {
            $p = $payload;
            $p['title'] = eg_shared_prefix_title((string)($payload['title'] ?? ''),
                          ['deliver_uid' => 0, 'for_uid' => $forUid, 'for_name' => $g['name']]);
            // tag 必須帶 for_uid：同一 tag 的推播會互相覆蓋，多位成員的通知就只剩最後一則
            if (!empty($p['tag'])) $p['tag'] .= '-for' . (int)$forUid;
            $p['forUid'] = (int)$forUid;
            $merge(eg_push_send_raw($db, $g['deliver'], $p));
        }
        return $agg;
    }
}

if (!function_exists('eg_push_send_raw')) {
    /**
     * 實際發送（不做共用帳號轉送）。原 eg_push_send_to_users 的內容。
     * @return array ['ok'=>bool,'sent'=>int,'failed'=>int,'removed'=>int]
     */
    function eg_push_send_raw(PDO $db, array $userIds, array $payload): array
    {
        // 每次發送前過濾員工狀態（離職/留職停薪/育嬰留停/特殊帳號 不發）
        $userIds = eg_push_active_user_filter($db, $userIds);
        if (empty($userIds)) return ['ok' => true, 'sent' => 0, 'failed' => 0, 'removed' => 0];

        $webPush = eg_push_webpush();
        if (!$webPush) return ['ok' => false, 'sent' => 0, 'failed' => 0, 'removed' => 0, 'msg' => 'VAPID 未設定'];

        eg_push_sub_ensure_schema($db);
        $in = implode(',', $userIds);
        $subs = $db->query("SELECT id, endpoint, p256dh, auth FROM push_subscription WHERE is_active = 1 AND user_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($subs)) return ['ok' => true, 'sent' => 0, 'failed' => 0, 'removed' => 0];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $byEndpoint = [];
        foreach ($subs as $s) {
            $byEndpoint[$s['endpoint']] = $s['id'];
            try {
                $sub = Subscription::create([
                    'endpoint' => $s['endpoint'],
                    'keys'     => ['p256dh' => $s['p256dh'], 'auth' => $s['auth']],
                ]);
                $webPush->queueNotification($sub, $json);
            } catch (\Throwable $e) {
                error_log('[push] queue failed: ' . $e->getMessage());
            }
        }

        $sent = 0; $failed = 0; $removed = 0;
        // 端點失效（410 Gone / 404）→ 標記失效保留紀錄（不刪除），供管理介面辨識並通知該員工重新綁定（規格 6-5）
        $offStmt = $db->prepare("UPDATE push_subscription SET is_active = 0, deactivated_at = NOW(), fail_reason = ? WHERE id = ?");
        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getEndpoint();
            if ($report->isSuccess()) {
                $sent++;
            } else {
                $failed++;
                if ($report->isSubscriptionExpired() && isset($byEndpoint[$endpoint])) {
                    $offStmt->execute([mb_substr((string)$report->getReason(), 0, 100), $byEndpoint[$endpoint]]);
                    $removed++;
                } else {
                    error_log('[push] send failed: ' . $report->getReason());
                }
            }
        }
        if ($sent > 0) {
            // 更新最後成功時間
            $db->query("UPDATE push_subscription SET last_used_at = NOW() WHERE user_id IN ($in)");
        }
        return ['ok' => true, 'sent' => $sent, 'failed' => $failed, 'removed' => $removed];
    }
}

if (!function_exists('eg_push_event_recipients')) {
    /** 解析公告對象 → 收件 user.id 清單。僅 在職(1)/最高權限(99)；離職(0)/留職停薪(2)/育嬰留停(3)/特殊帳號(90) 不收推播（規格 6-1）。 */
    function eg_push_event_recipients(PDO $db, int $eventId): array
    {
        $rows = $db->prepare("SELECT target_type, target_id FROM live_event_target WHERE live_event_id = ?");
        $rows->execute([$eventId]);
        $targets = $rows->fetchAll(PDO::FETCH_ASSOC);
        if (empty($targets)) return [];

        $ids = [];
        $active = "state IN (1,99)";
        foreach ($targets as $t) {
            switch ($t['target_type']) {
                case 'all':
                    foreach ($db->query("SELECT id FROM user WHERE $active")->fetchAll(PDO::FETCH_COLUMN) as $u) $ids[(int)$u] = 1;
                    break 2; // 全體已涵蓋所有人，無需再看其他對象
                case 'status':
                    $st = $db->prepare("SELECT id FROM user WHERE $active AND (user_status = :s OR user_status2 = :s OR user_status3 = :s)");
                    $st->execute([':s' => (int)$t['target_id']]);
                    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) $ids[(int)$u] = 1;
                    break;
                case 'dept':
                    $st = $db->prepare("SELECT DISTINCT m.user_id FROM user_department_position_map m JOIN user u ON u.id = m.user_id WHERE u.$active AND m.department_id = ?");
                    $st->execute([(int)$t['target_id']]);
                    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) $ids[(int)$u] = 1;
                    break;
                case 'user':
                    $st = $db->prepare("SELECT id FROM user WHERE $active AND id = ?");
                    $st->execute([(int)$t['target_id']]);
                    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) $ids[(int)$u] = 1;
                    break;
            }
        }
        return array_keys($ids);
    }
}

if (!function_exists('eg_push_for_event')) {
    /**
     * 依公告內容組推播訊息並發送給其所有對象。發布/更新公告後呼叫。
     * 失敗只記 error_log、不中斷主流程。
     */
    function eg_push_for_event(PDO $db, int $eventId): array
    {
        try {
            $recipients = eg_push_event_recipients($db, $eventId);
            return eg_push_event_notify($db, $eventId, $recipients, false);
        } catch (\Throwable $e) {
            error_log('[push] eg_push_for_event failed: ' . $e->getMessage());
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }
}

if (!function_exists('eg_push_event_notify')) {
    /**
     * 對「指定的收件人清單」推播某公告。
     * @param bool $isUpdate  true＝公告更新通知（🔄）；false＝新公告通知（📢）
     */
    function eg_push_event_notify(PDO $db, int $eventId, array $userIds, bool $isUpdate = false): array
    {
        try {
            $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
            if (empty($userIds)) return ['ok' => true, 'sent' => 0, 'failed' => 0, 'removed' => 0];

            $ev = $db->prepare("SELECT id, title, content, source FROM live_event WHERE id = ?");
            $ev->execute([$eventId]);
            $event = $ev->fetch(PDO::FETCH_ASSOC);
            if (!$event) return ['ok' => false, 'msg' => 'event not found'];

            // 附件數（提示用；附件本體在內網，4G 需連公司網路/VPN 才開得了）
            $attCnt = (int)$db->query("SELECT COUNT(*) FROM live_event_file WHERE live_event_id = " . (int)$eventId)->fetchColumn();

            // 通知內文帶「完整公告內容」，讓使用者即使在 4G（未連內網）也能直接讀到全文。
            $body = trim((string)$event['content']);
            if ($body === '') $body = '（無內容）';
            $body = mb_substr($body, 0, 480);
            if ($attCnt > 0) $body .= "\n📎 含 {$attCnt} 個附件（需連公司網路或 VPN 才能開啟）";

            $prefix = $isUpdate ? '🔄 [公告更新] ' : '📢 ';
            $payload = [
                'title' => $prefix . ($event['source'] ? '[' . $event['source'] . '] ' : '') . $event['title'],
                'body'  => $body,
                'tag'   => 'live-event-' . $eventId,
                'url'   => '/EGsystem/views/liveEvent/mobile.php?event=' . (int)$eventId,
                'eventId' => (int)$eventId,
            ];
            // 帶 event_id：讓共用帳號轉送只作用在「指名(target_type='user')」的收件人
            return eg_push_send_to_users($db, $userIds, $payload, ['event_id' => (int)$eventId]);
        } catch (\Throwable $e) {
            error_log('[push] eg_push_event_notify failed: ' . $e->getMessage());
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }
}
