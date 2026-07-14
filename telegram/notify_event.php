<?php
// 公告/通知 → Telegram 推播入口（與現行 Web Push 並行，互不影響）。
//   eg_telegram_event_notify($db, $eventId, $userIds, $isUpdate)  對指定收件人發 Telegram（依模式附按鈕）
//   eg_telegram_for_event($db, $eventId)                          發給公告所有對象
//   eg_telegram_event_modes($db, $eventId)                        解析各收件人的通知方式 user_id => read/sign/reply
//
// 收件人為 user.id 清單（沿用 Web Push 的 eg_push_event_recipients 解析結果），
// 只發給已在 telegram_users 綁定 chat_id 且 is_active=1 的人，未綁定者自動跳過。
// 依通知方式附 Inline Keyboard：已閱→「✅ 已閱確認」、回簽→「✍️ 回簽確認」、
// 回覆→「✍️ 回簽確認」＋提示可長按訊息選「回覆」輸入文字。
// 失敗只 error_log，不中斷公告發布流程。

require_once __DIR__ . '/send_message.php';

if (!function_exists('eg_telegram_event_modes')) {
    /** 解析公告對象 → user_id => 通知方式（同一人命中多個對象時取最重：reply > sign > read） */
    function eg_telegram_event_modes(PDO $db, int $eventId): array
    {
        $rows = $db->prepare("SELECT target_type, target_id, mode FROM live_event_target WHERE live_event_id = ?");
        $rows->execute([$eventId]);
        $weight = ['read' => 1, 'sign' => 2, 'reply' => 3];
        $map = [];
        $put = function ($uid, $mode) use (&$map, $weight) {
            $uid = (int)$uid;
            $mode = isset($weight[$mode]) ? $mode : 'read';
            if (!isset($map[$uid]) || $weight[$mode] > $weight[$map[$uid]]) $map[$uid] = $mode;
        };
        $active = "state IN (1,99)"; // 僅在職/最高權限；離職(0)/留職停薪(2)/育嬰留停(3)/特殊帳號(90) 不發（規格 6-1）
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $m = $t['mode'] ?? 'read';
            switch ($t['target_type']) {
                case 'all':
                    foreach ($db->query("SELECT id FROM user WHERE $active")->fetchAll(PDO::FETCH_COLUMN) as $u) $put($u, $m);
                    break;
                case 'status':
                    $st = $db->prepare("SELECT id FROM user WHERE $active AND (user_status = :s OR user_status2 = :s OR user_status3 = :s)");
                    $st->execute([':s' => (int)$t['target_id']]);
                    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) $put($u, $m);
                    break;
                case 'dept':
                    $st = $db->prepare("SELECT DISTINCT m.user_id FROM user_department_position_map m JOIN user u ON u.id = m.user_id WHERE u.$active AND m.department_id = ?");
                    $st->execute([(int)$t['target_id']]);
                    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) $put($u, $m);
                    break;
                case 'user':
                    $st = $db->prepare("SELECT id FROM user WHERE $active AND id = ?");
                    $st->execute([(int)$t['target_id']]);
                    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) $put($u, $m);
                    break;
            }
        }
        return $map;
    }
}

if (!function_exists('eg_telegram_event_attachments')) {
    /**
     * 取公告的附件按鈕清單（附件兩段式發送）。
     * 公告附件取 live_event_file；品質異常單通知（ref_type='QA'）另取 qa_abnormal_attachments。
     * 標籤 allow_telegram=1 → 一顆「📎 取得附件」按鈕（callback_data att:e:{id} / att:q:{id}）；
     * 不允許 → 計入 locked（訊息內提示請至內網查看）。
     * @return array ['buttons'=>array 每列一顆按鈕, 'locked'=>int]
     */
    function eg_telegram_event_attachments(PDO $db, array $event): array
    {
        $buttons = []; $locked = 0;
        try {
            require_once __DIR__ . '/../src/common/attachment_lib.php';
            eg_att_ensure_schema($db);
            $items = []; // ['k'=>'e'|'q','id'=>,'name'=>,'tag_id'=>]
            $st = $db->prepare("SELECT id, file_name, original_filename, tag_id FROM live_event_file WHERE live_event_id = ? ORDER BY id");
            $st->execute([(int)$event['id']]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $items[] = ['k' => 'e', 'id' => (int)$r['id'], 'name' => ($r['original_filename'] ?: $r['file_name']), 'tag_id' => $r['tag_id']];
            }
            if (($event['ref_type'] ?? '') === 'QA' && !empty($event['ref_id'])) {
                $st = $db->prepare("SELECT id, file_name, original_filename, tag_id FROM qa_abnormal_attachments WHERE abnormal_order_id = ? ORDER BY id");
                $st->execute([(int)$event['ref_id']]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $items[] = ['k' => 'q', 'id' => (int)$r['id'], 'name' => ($r['original_filename'] ?: $r['file_name']), 'tag_id' => $r['tag_id']];
                }
            }
            $defE = eg_att_default_tag_id($db, 'announcement');
            $defQ = eg_att_default_tag_id($db, 'abnormal');
            $tagCache = [];
            foreach ($items as $it) {
                $tagId = $it['tag_id'] !== null ? (int)$it['tag_id'] : ($it['k'] === 'e' ? $defE : $defQ);
                $allow = false;
                if ($tagId) {
                    if (!array_key_exists($tagId, $tagCache)) $tagCache[$tagId] = eg_att_tag_row($db, $tagId);
                    $t = $tagCache[$tagId];
                    $allow = $t && (int)$t['is_active'] === 1 && (int)$t['allow_telegram'] === 1;
                }
                if (!$allow) { $locked++; continue; }
                $label = '📎 取得附件：' . mb_substr($it['name'], 0, 30);
                $buttons[] = [['text' => $label, 'callback_data' => 'att:' . $it['k'] . ':' . $it['id']]];
            }
        } catch (\Throwable $e) {
            error_log('[telegram] event attachments failed: ' . $e->getMessage());
        }
        return ['buttons' => $buttons, 'locked' => $locked];
    }
}

if (!function_exists('eg_telegram_event_notify')) {
    /**
     * @param int[] $userIds  收件者 user.id
     * @param bool  $isUpdate true＝公告更新通知（🔄）；false＝新公告通知（📢）
     * @return array ['ok'=>bool,'sent'=>int,'failed'=>int,'skipped'=>int]
     */
    function eg_telegram_event_notify(PDO $db, int $eventId, array $userIds, bool $isUpdate = false): array
    {
        try {
            if (!tg_is_configured()) return ['ok' => false, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'msg' => 'token 未設定'];

            $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
            if (empty($userIds)) return ['ok' => true, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

            // 只取有綁定 Telegram 的收件人（user_id => chat_id）
            $in = implode(',', $userIds);
            $bound = [];
            foreach ($db->query("SELECT user_id, chat_id FROM telegram_users WHERE is_active = 1 AND user_id IN ($in)") as $r) {
                $bound[(int)$r['user_id']] = $r['chat_id'];
            }
            $skipped = count($userIds) - count($bound);
            if (empty($bound)) return ['ok' => true, 'sent' => 0, 'failed' => 0, 'skipped' => $skipped];

            $ev = $db->prepare("SELECT id, event_no, title, content, source, eventdate, reply_deadline, ref_type, ref_id FROM live_event WHERE id = ?");
            $ev->execute([$eventId]);
            $event = $ev->fetch(PDO::FETCH_ASSOC);
            if (!$event) return ['ok' => false, 'msg' => 'event not found'];

            // 附件兩段式（規格 四之二）：標籤允許 Telegram 的附件各附一顆「📎 取得附件」按鈕；
            // 不允許者只提示「請至內網查看」。點按鈕才產生個人浮水印版並發檔（poll_core.php att: callback）。
            $att = eg_telegram_event_attachments($db, $event);

            // 各收件人通知方式；已完成者（更新通知時）不再附按鈕
            // 完成定義與網頁端一致：回覆模式須有 replied_at，回簽模式有 signed_at 即可
            $modes = eg_telegram_event_modes($db, $eventId);
            $resp = [];
            $inB = implode(',', array_keys($bound));
            foreach ($db->query("SELECT user_id, signed_at, replied_at FROM live_event_response WHERE live_event_id = " . (int)$eventId . " AND user_id IN ($inB)") as $r) {
                $resp[(int)$r['user_id']] = $r;
            }

            // 訊息本文（訊息內不放任何連結；HTML parse_mode，內容需跳脫）
            $head = $isUpdate ? '🔄 <b>[公告更新]</b> ' : '📢 ';
            $src  = $event['source'] ? '[' . htmlspecialchars($event['source']) . '] ' : '';
            $body = trim((string)$event['content']);
            if ($body === '') $body = '（無內容）';
            $body = mb_substr($body, 0, 2000);

            $base = $head . $src . '<b>' . htmlspecialchars($event['title']) . '</b>' . "\n"
                  . ($event['event_no'] ? '編號：' . htmlspecialchars($event['event_no']) . "\n" : '')
                  . '發布日期：' . htmlspecialchars((string)$event['eventdate']) . "\n"
                  . (!empty($event['reply_deadline']) ? '回覆/回簽期限：' . htmlspecialchars($event['reply_deadline']) . "\n" : '')
                  . "\n" . htmlspecialchars($body);
            if ($att['locked'] > 0) $base .= "\n\n📎 另有 " . $att['locked'] . " 個附件：請至內網查看";

            $sent = 0; $failed = 0;
            foreach ($bound as $uid => $chatId) {
                $mode = $modes[$uid] ?? 'read';
                $text = $base;
                $rows = []; // inline keyboard 列
                $done = isset($resp[$uid]) && ($mode === 'reply'
                    ? !empty($resp[$uid]['replied_at'])
                    : (!empty($resp[$uid]['signed_at']) || !empty($resp[$uid]['replied_at'])));
                if ($done) {
                    // 已完成者：不附回覆/回簽按鈕（附件按鈕照附）
                } elseif ($mode === 'reply') {
                    // 回覆模式不提供「只回簽」捷徑（與網頁端一致，須輸入回覆內容，送出時一併回簽）
                    $text .= "\n\n✍️ 此通知需要您回覆：點下方按鈕輸入回覆內容，或<b>長按此訊息選「回覆」</b>";
                    $rows[] = [['text' => '✍️ 輸入回覆', 'callback_data' => 'replyto:' . $eventId]];
                } elseif ($mode === 'sign') {
                    $text .= "\n\n✍️ 此通知需要您回簽，請點下方按鈕";
                    $rows[] = [['text' => '✍️ 回簽確認', 'callback_data' => 'sign:' . $eventId]];
                } else {
                    $rows[] = [['text' => '✅ 已閱確認', 'callback_data' => 'read:' . $eventId]];
                }
                foreach ($att['buttons'] as $btnRow) $rows[] = $btnRow; // 每個可外送附件一顆按鈕
                $markup = $rows ? ['inline_keyboard' => $rows] : null;
                $r = tg_send_text($chatId, $text, $db, $eventId, $markup);
                if ($r['ok']) $sent++; else $failed++;
                usleep(100000); // 避免觸發 Telegram 速率限制
            }
            return ['ok' => true, 'sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
        } catch (\Throwable $e) {
            error_log('[telegram] eg_telegram_event_notify failed: ' . $e->getMessage());
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }
}

if (!function_exists('eg_telegram_retract_event')) {
    /**
     * 收回某公告先前發出的 Telegram 通知訊息：
     * 把訊息內容改為「已修改／已刪除」短提示並自動移除按鈕（editMessageText 無時效限制，
     * deleteMessage 只能刪 48 小時內的訊息，故一律用改寫方式收回）。
     * 已收回過的訊息（[已收回] 標記）不重複處理。
     * @param bool $deleted true＝公告被刪除；false＝公告內容被修改（稍後會收到新訊息）
     */
    function eg_telegram_retract_event(PDO $db, int $eventId, bool $deleted = false): void
    {
        try {
            if (!tg_is_configured()) return;
            // 通知訊息（📢/🔄 開頭）；刪除時連「✍️ 輸入回覆」提示一併收回
            $like = $deleted ? "(message_text LIKE '📢%' OR message_text LIKE '🔄%' OR message_text LIKE '✍️%')"
                             : "(message_text LIKE '📢%' OR message_text LIKE '🔄%')";
            $rows = $db->prepare("SELECT id, chat_id, telegram_message_id FROM telegram_messages
                                  WHERE direction = 'out' AND related_record_id = ?
                                    AND telegram_message_id IS NOT NULL
                                    AND $like
                                    AND message_text NOT LIKE '[已收回]%'");
            $rows->execute([$eventId]);
            $list = $rows->fetchAll(PDO::FETCH_ASSOC);
            if (empty($list)) return;

            $newText = $deleted
                ? '🗑️ 此通知已被刪除，無需處理。'
                : '🔄 此通知內容已修改，本則作廢，請以最新收到的訊息為準。';
            $mark = $db->prepare("UPDATE telegram_messages SET message_text = CONCAT('[已收回] ', message_text) WHERE id = ?");
            foreach ($list as $r) {
                $res = tg_edit_message($r['chat_id'], $r['telegram_message_id'], $newText);
                if (!empty($res['ok'])) $mark->execute([$r['id']]);
                usleep(100000); // 避免觸發 Telegram 速率限制
            }
        } catch (\Throwable $e) {
            error_log('[telegram] retract event failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('eg_telegram_for_event')) {
    /** 發給公告所有對象（收件人解析沿用 Web Push 的 eg_push_event_recipients） */
    function eg_telegram_for_event(PDO $db, int $eventId): array
    {
        try {
            if (!function_exists('eg_push_event_recipients')) {
                require_once __DIR__ . '/../src/push/push_send.php';
            }
            $recipients = eg_push_event_recipients($db, $eventId);
            return eg_telegram_event_notify($db, $eventId, $recipients, false);
        } catch (\Throwable $e) {
            error_log('[telegram] eg_telegram_for_event failed: ' . $e->getMessage());
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }
}
