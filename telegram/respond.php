<?php
// Telegram 端的已閱/回簽/回覆寫入（與網頁端 src/store/_eventRespond.php 同一套紀錄）。
//   eg_telegram_record_response($db, $eventId, $uid, $action, $content)
// 寫入 live_event_response / live_event_for_user，QA 通知同步回寫異常單流程。
// 差異：Telegram 端不支援附件回覆（附件請至系統頁面上傳）。

if (!function_exists('eg_telegram_record_response')) {
    /**
     * @param string $action  read | sign | reply
     * @param string $content 回覆內容（action=reply 時必填）
     * @return array ['ok'=>bool, 'msg'=>string]
     */
    function eg_telegram_record_response(PDO $db, int $eventId, int $uid, string $action, string $content = ''): array
    {
        try {
            if (!in_array($action, ['read', 'sign', 'reply'], true)) return ['ok' => false, 'msg' => '參數錯誤'];

            $ev = $db->prepare("SELECT * FROM live_event WHERE id = ?");
            $ev->execute([$eventId]);
            $event = $ev->fetch(PDO::FETCH_ASSOC);
            if (!$event) return ['ok' => false, 'msg' => '找不到公告'];

            // 期限檢查（與網頁端一致：已閱不受限，回簽/回覆過期擋下）
            $deadlinePassed = !empty($event['reply_deadline']) && $event['reply_deadline'] < date('Y-m-d');
            if ($deadlinePassed && ($action === 'sign' || $action === 'reply')) {
                return ['ok' => false, 'msg' => '已超過回覆/回簽期限（' . $event['reply_deadline'] . '）'];
            }
            if ($action === 'reply' && trim($content) === '') return ['ok' => false, 'msg' => '回覆內容不可為空'];

            // 取得/建立回應列
            $rs = $db->prepare("SELECT * FROM live_event_response WHERE live_event_id = ? AND user_id = ?");
            $rs->execute([$eventId, $uid]);
            $row = $rs->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $db->prepare("INSERT INTO live_event_response (live_event_id, user_id, read_at) VALUES (?,?,NOW())")->execute([$eventId, $uid]);
                $rid = (int)$db->lastInsertId();
            } else {
                $rid = (int)$row['id'];
                if (empty($row['read_at'])) $db->prepare("UPDATE live_event_response SET read_at=NOW() WHERE id=?")->execute([$rid]);
            }

            // 同步鈴鐺已讀（live_event_for_user）
            $chk = $db->prepare("SELECT id FROM live_event_for_user WHERE user_id = ? AND live_event_id = ? LIMIT 1");
            $chk->execute([$uid, $eventId]);
            $frId = $chk->fetchColumn();
            if ($frId) {
                $db->prepare("UPDATE live_event_for_user SET oready_read = 1, read_at = COALESCE(read_at, NOW()) WHERE id = ?")->execute([$frId]);
            } else {
                $db->prepare("INSERT INTO live_event_for_user (user_id, live_event_id, oready_read, read_at) VALUES (?,?,1,NOW())")->execute([$uid, $eventId]);
            }

            if ($action === 'sign' || $action === 'reply') {
                $db->prepare("UPDATE live_event_response SET signed_at = COALESCE(signed_at, NOW()) WHERE id = ?")->execute([$rid]);
            }
            if ($action === 'reply') {
                $db->prepare("UPDATE live_event_response SET reply_content = ?, replied_at = NOW() WHERE id = ?")->execute([trim($content), $rid]);
            }

            // QA 通知：回簽/回覆同步回寫異常單流程並通知開單人＋追蹤人員（與網頁端一致）
            if (($action === 'sign' || $action === 'reply')
                && ($event['ref_type'] ?? '') === 'QA' && (int)($event['ref_id'] ?? 0) > 0) {
                try {
                    require_once __DIR__ . '/../src/common/qa_notify.php';
                    $orderId = (int)$event['ref_id'];
                    $replyContent = $action === 'reply' ? trim($content) : '';
                    eg_qa_sync_flow_reply($db, $orderId, $uid, $replyContent !== '' ? $replyContent : null);
                    eg_qa_notify_reply($db, $orderId, $uid, $action, $replyContent);
                } catch (Throwable $e) {
                    error_log('[telegram] qa respond hook failed: ' . $e->getMessage());
                }
            }

            return ['ok' => true, 'msg' => ''];
        } catch (Throwable $e) {
            error_log('[telegram] record response failed: ' . $e->getMessage());
            return ['ok' => false, 'msg' => '系統錯誤'];
        }
    }
}
