<?php
// Telegram 輪詢核心：收取按鈕點擊(callback_query)與文字回覆(message)並寫回系統。
//   tg_poll_process($db)  收一輪並處理，回 ['ok'=>bool,'processed'=>int,...]
//
// 觸發方式（做法A，免工作排程器）：
//   - src/common/telegram_tick.php 於一般頁面請求時背景啟動 poll_replies.php（>60秒一次）
//   - telegram/get_chat_id.php 工具頁開啟時也會先收一輪
// 併發保護：poll.lock flock；位置記錄：last_update_id.txt（處理過的 update 不重複處理）。

require_once __DIR__ . '/send_message.php';
require_once __DIR__ . '/respond.php';

if (!function_exists('tg_poll_process')) {
    /**
     * @param int $runSeconds 0＝收一輪就結束（工具頁用）；>0＝駐留長輪詢：
     *            以 getUpdates(timeout=25) 掛住連線，有按鈕點擊/回覆約 1 秒內處理，
     *            直到駐留時間結束才退出。駐留中每輪 touch last_poll.txt，
     *            讓 telegram_tick 不會重複啟動新的輪詢程序。
     */
    function tg_poll_process(PDO $db, int $runSeconds = 0): array
    {
        if (!tg_is_configured()) return ['ok' => false, 'processed' => 0, 'msg' => 'token 未設定'];

        // 併發鎖：搶不到代表另一個輪詢正在跑，直接跳過
        $lockFh = @fopen(__DIR__ . '/poll.lock', 'c');
        if (!$lockFh || !flock($lockFh, LOCK_EX | LOCK_NB)) {
            if ($lockFh) fclose($lockFh);
            return ['ok' => true, 'processed' => 0, 'locked' => true];
        }

        $offsetFile = __DIR__ . '/last_update_id.txt';
        $stateFile  = __DIR__ . '/last_poll.txt';
        $lastId = (int)@file_get_contents($offsetFile);
        $deadline = time() + max(0, $runSeconds);
        $processed = 0;
        $ok = true; $msg = '';

        do {
            // 駐留模式用長輪詢（Telegram 掛住連線至多 25 秒），單次模式立即回
            $timeout = $runSeconds > 0 ? min(25, max(1, $deadline - time())) : 0;
            $res = tg_get_updates($lastId + 1, $timeout);
            if (empty($res['ok'])) {
                $ok = false; $msg = $res['description'] ?? '取得訊息失敗';
                if ($runSeconds > 0) { sleep(5); @touch($stateFile); continue; } // 駐留模式：暫時性網路錯誤稍候重試
                break;
            }
            $ok = true;
            foreach (($res['result'] ?? []) as $u) {
                $lastId = max($lastId, (int)($u['update_id'] ?? 0));
                try {
                    if (isset($u['callback_query'])) { tg_poll_handle_callback($db, $u['callback_query']); $processed++; }
                    elseif (isset($u['message']))    { tg_poll_handle_message($db, $u['message']); $processed++; }
                } catch (\Throwable $e) {
                    error_log('[telegram] poll handle update failed: ' . $e->getMessage());
                }
            }
            @file_put_contents($offsetFile, (string)$lastId);
            @touch($stateFile); // 告知 tick：輪詢仍在線上，不必再啟動
            @touch(__DIR__ . '/poll_heartbeat.txt'); // 心跳（只有輪詢本體會寫；tick 佔位不算），供 ERP 頁面偵測服務異常
        } while ($runSeconds > 0 && time() < $deadline);

        flock($lockFh, LOCK_UN); fclose($lockFh);
        $out = ['ok' => $ok, 'processed' => $processed];
        if (!$ok && $msg !== '') $out['msg'] = $msg;
        return $out;
    }
}

if (!function_exists('tg_poll_find_binding')) {
    /** chat_id → ['user_id'=>int,'employee_name'=>string] 或 null（未綁定/停用） */
    function tg_poll_find_binding(PDO $db, $chatId): ?array
    {
        $st = $db->prepare("SELECT user_id, employee_name FROM telegram_users WHERE chat_id = ? AND is_active = 1 AND user_id IS NOT NULL");
        $st->execute([$chatId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
}

if (!function_exists('tg_poll_log_in')) {
    /** 收到的訊息寫入 telegram_messages（direction='in'） */
    function tg_poll_log_in(PDO $db, $chatId, string $name, string $text, $tgMsgId = null, $relatedId = null): void
    {
        try {
            $db->prepare("INSERT INTO telegram_messages (direction, chat_id, employee_name, message_text, telegram_message_id, related_record_id)
                          VALUES ('in',?,?,?,?,?)")
               ->execute([$chatId, $name, $text, $tgMsgId, $relatedId]);
        } catch (\Throwable $e) {
            error_log('[telegram] log in-message failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('tg_poll_handle_callback')) {
    /** 按鈕點擊：read:{eventId} / sign:{eventId} → 寫回已閱/回簽，並更新原訊息移除按鈕 */
    function tg_poll_handle_callback(PDO $db, array $cq): void
    {
        $cqId    = $cq['id'] ?? '';
        $chatId  = $cq['message']['chat']['id'] ?? ($cq['from']['id'] ?? null);
        $msgId   = $cq['message']['message_id'] ?? null;
        $origTxt = $cq['message']['text'] ?? '';
        $data    = (string)($cq['data'] ?? '');

        $bind = $chatId !== null ? tg_poll_find_binding($db, $chatId) : null;
        if (!$bind) {
            if ($cqId) tg_answer_callback($cqId, '此帳號尚未綁定系統，請聯絡管理員');
            return;
        }

        // 員工當下狀態檢查（規格 6-1/6-3：舊按鈕永久存在，點擊時須重新驗證）：僅在職(1)/最高權限(99) 可操作
        try {
            $stt = $db->prepare("SELECT state FROM `user` WHERE id = ?");
            $stt->execute([(int)$bind['user_id']]);
            if (!in_array((int)$stt->fetchColumn(), [1, 99], true)) {
                if ($cqId) tg_answer_callback($cqId, '您的帳號狀態已變更，此功能已不開放');
                error_log('[telegram] callback blocked by user state: user_id=' . $bind['user_id']);
                return;
            }
        } catch (\Throwable $e) { error_log('[telegram] state check failed: ' . $e->getMessage()); }

        // 附件按鈕（att:e:{id}=公告附件 / att:q:{id}=異常單附件）：兩段式發送
        if (strpos($data, 'att:') === 0) {
            tg_poll_handle_attachment($db, $cq, $bind);
            return;
        }

        $parts = explode(':', $data, 2);
        $act = $parts[0] ?? '';
        $eid = (int)($parts[1] ?? 0);
        if (!in_array($act, ['read', 'sign', 'replyto'], true) || $eid <= 0) {
            if ($cqId) tg_answer_callback($cqId, '無效的操作');
            return;
        }

        $uid = (int)$bind['user_id'];
        require_once __DIR__ . '/notify_event.php';

        // 「輸入回覆」按鈕：發 ForceReply 提示訊息，使用者直接輸入即可對應到本通知
        // 已回覆過仍可再按 → 新內容會「更新」原回覆（後端 respond.php 本就覆寫並同步 QA 流程）
        if ($act === 'replyto') {
            $t = $db->prepare("SELECT title FROM live_event WHERE id = ?");
            $t->execute([$eid]);
            $title = (string)$t->fetchColumn();
            $st = $db->prepare("SELECT replied_at FROM live_event_response WHERE live_event_id = ? AND user_id = ?");
            $st->execute([$eid, $uid]);
            $already = !empty($st->fetch(PDO::FETCH_ASSOC)['replied_at'] ?? null);
            if ($cqId) tg_answer_callback($cqId);
            // ForceReply 提示訊息帶 related_record_id，使用者的回覆經 reply_to 對應回本通知
            // 注意：訊息開頭「✍️ 請輸入對」是 tg_poll_handle_message 對應回覆用的比對前綴，備註只能加在句尾
            tg_send_text($chatId, '✍️ 請輸入對「' . htmlspecialchars(mb_substr($title, 0, 50)) . '」的回覆內容'
                . ($already ? "（您已回覆過，送出後將<b>更新</b>原回覆）" : '') . '：'
                . "\n📎 Telegram 無法上傳附件；如需附加檔案，請經由內網開啟系統上傳。", $db, $eid,
                ['force_reply' => true, 'input_field_placeholder' => '輸入回覆內容']);
            return;
        }

        // 回覆模式不可只回簽（與網頁端一致）；擋下舊訊息上殘留的回簽按鈕
        if ($act === 'sign') {
            $modes = eg_telegram_event_modes($db, $eid);
            if (($modes[$uid] ?? '') === 'reply') {
                if ($cqId) tg_answer_callback($cqId, '此通知需要回覆內容：請長按通知訊息選「回覆」輸入文字');
                return;
            }
        }

        $r = eg_telegram_record_response($db, $eid, $uid, $act);
        $label = $act === 'sign' ? '回簽確認' : '已閱確認';
        tg_poll_log_in($db, $chatId, $bind['employee_name'], '[按鈕] ' . $label, $msgId, $eid);

        if ($cqId) tg_answer_callback($cqId, $r['ok'] ? '✅ ' . $label . '已記錄' : ($r['msg'] ?: '記錄失敗'));
        if ($r['ok'] && $msgId && $origTxt !== '') {
            // 更新原訊息：附上完成狀態並移除按鈕（避免重複點擊）
            tg_edit_message($chatId, $msgId, $origTxt . "\n────────────\n✅ 已於 " . date('m-d H:i') . ' ' . $label);
        }
    }
}

if (!function_exists('tg_poll_handle_attachment')) {
    /**
     * 附件按鈕 callback（規格 四之二 兩段式）：
     *   1. 三重驗證（員工狀態已於呼叫端檢查；綁定有效；標籤目前仍允許 Telegram）
     *   2. 寫 attachment_download_logs（channel='telegram'；重複點擊每次都記一筆）
     *   3. 當場產生「印著此員工代碼＋按下時間＋單號」的加註版本（免浮水印標籤僅角落標註層）
     *   4. sendDocument 發送，發送完刪除暫存檔
     * 任一驗證不過：回覆「附件已不開放」並寫 log。未加註成功的儲存檔一律不外送。
     */
    function tg_poll_handle_attachment(PDO $db, array $cq, array $bind): void
    {
        $cqId   = $cq['id'] ?? '';
        $chatId = $cq['message']['chat']['id'] ?? ($cq['from']['id'] ?? null);
        $data   = (string)($cq['data'] ?? '');
        $uid    = (int)$bind['user_id'];

        $p = explode(':', $data, 3); // att:{e|q}:{id}
        $kind  = $p[1] ?? '';
        $attId = (int)($p[2] ?? 0);
        if (!in_array($kind, ['e', 'q'], true) || $attId <= 0) {
            if ($cqId) tg_answer_callback($cqId, '無效的附件');
            return;
        }

        require_once __DIR__ . '/../src/common/attachment_lib.php';
        eg_att_ensure_schema($db);
        $scope = $kind === 'e' ? 'announcement' : 'abnormal';

        // 取附件與所屬單號
        if ($kind === 'e') {
            $st = $db->prepare("SELECT f.*, le.id AS event_id, le.event_no AS doc_no FROM live_event_file f JOIN live_event le ON le.id = f.live_event_id WHERE f.id = ?");
        } else {
            $st = $db->prepare("SELECT f.*, o.abnormal_order_no AS doc_no, o.notify_event_id AS event_id FROM qa_abnormal_attachments f JOIN qa_abnormal_order o ON o.id = f.abnormal_order_id WHERE f.id = ?");
        }
        $st->execute([$attId]);
        $att = $st->fetch(PDO::FETCH_ASSOC);
        if (!$att || empty($att['file_path']) || !is_file($att['file_path'])) {
            if ($cqId) tg_answer_callback($cqId, '附件已不存在，請至內網查看');
            return;
        }

        // 驗證：附件標籤「目前」是否仍允許 Telegram（事後改標籤只影響未來，舊按鈕在此擋下）
        $tagId = $att['tag_id'] !== null ? (int)$att['tag_id'] : eg_att_default_tag_id($db, $scope);
        $tag = $tagId ? eg_att_tag_row($db, $tagId) : null;
        if (!$tag || (int)$tag['is_active'] !== 1 || (int)$tag['allow_telegram'] !== 1) {
            if ($cqId) tg_answer_callback($cqId, '附件已不開放，請至內網查看');
            error_log('[telegram] attachment blocked by tag: att=' . $scope . '#' . $attId . ' user=' . $uid);
            return;
        }
        if (@filesize($att['file_path']) > 49 * 1024 * 1024) {
            if ($cqId) tg_answer_callback($cqId, '附件過大，無法透過 Telegram 傳送，請至內網查看');
            return;
        }

        // 下載/開啟紀錄（每次向 Bot 索取都記一筆；浮水印時間即此刻）
        try {
            $db->prepare("INSERT INTO attachment_download_logs (scope, attachment_id, user_id, channel, ip) VALUES (?,?,?,?,?)")
               ->execute([$scope, $attId, $uid, 'telegram', null]);
        } catch (\Throwable $e) { error_log('[telegram] download log failed: ' . $e->getMessage()); }

        if ($cqId) tg_answer_callback($cqId, '📎 附件處理中，請稍候…');

        // 產生個人加註版：角落標註（標籤＋備注）一律有；溯源浮水印依標籤 require_watermark
        $uploaderName = '';
        $upBy = (int)($att['uploaded_by'] ?? $att['created_by'] ?? 0);
        if ($upBy) {
            try { $q = $db->prepare("SELECT user_cname FROM `user` WHERE id=?"); $q->execute([$upBy]); $uploaderName = (string)$q->fetchColumn(); } catch (\Throwable $e) {}
        }
        $now = eg_att_now(); // 台北時區（CLI 預設 UTC 會差 8 小時）
        $wm = ((int)$tag['require_watermark'] === 1)
            ? ('EG' . $uid . ' ' . $bind['employee_name'] . ' ' . $now . ' ' . ($att['doc_no'] ?: ''))
            : null;
        $ext = strtolower(pathinfo($att['file_path'], PATHINFO_EXTENSION));
        $tmp = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'tgatt_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $ok = eg_att_annotate_file($att['file_path'], $tmp, [
            'tag_name' => $tag['name'],
            'note'     => (string)($att['description'] ?? ''),
            'note_by'  => $uploaderName,
            'note_at'  => mb_substr((string)($att['uploaded_at'] ?? $att['created_at'] ?? ''), 0, 16),
            'watermark'=> $wm,
        ]);
        if (!$ok) {
            // 未加註成功的儲存檔一律不外送（規格 3-3）
            @unlink($tmp);
            tg_send_text($chatId, '⚠️ 附件處理失敗，暫時無法透過 Telegram 傳送，請至內網查看。', $db, (int)($att['event_id'] ?: 0) ?: null);
            error_log('[telegram] annotate failed, refuse to send: ' . $att['file_path']);
            return;
        }

        // 顯示檔名：以原始檔名為底、副檔名對齊實體檔（office 已轉 PDF）
        $baseName = pathinfo(($att['original_filename'] ?: $att['file_name']), PATHINFO_FILENAME);
        $displayName = $baseName . '.' . $ext;
        $caption = ($att['doc_no'] ? '單號 ' . $att['doc_no'] . '｜' : '') . '標籤：' . $tag['name'] . '｜' . $bind['employee_name'] . ' ' . $now . ' 開啟';
        $r = tg_send_document($chatId, $tmp, $displayName, $caption, $db, (int)($att['event_id'] ?: 0) ?: null);
        @unlink($tmp); // 加註後的暫存檔用完即刪
        if (empty($r['ok'])) {
            tg_send_text($chatId, '⚠️ 附件傳送失敗，請稍後再試或至內網查看。', $db, (int)($att['event_id'] ?: 0) ?: null);
        }
        tg_poll_log_in($db, $chatId, $bind['employee_name'], '[按鈕] 索取附件 ' . $scope . '#' . $attId, $cq['message']['message_id'] ?? null, (int)($att['event_id'] ?: 0) ?: null);
    }
}

if (!function_exists('tg_poll_handle_message')) {
    /** 文字訊息：優先用「長按回覆」對應到原通知；否則若剛好只有一筆待回覆通知則自動對應 */
    function tg_poll_handle_message(PDO $db, array $m): void
    {
        if (($m['chat']['type'] ?? '') !== 'private') return; // 一律只處理私訊（spec：不用群組）
        $chatId = $m['chat']['id'] ?? null;
        if ($chatId === null) return;
        $text   = trim((string)($m['text'] ?? ''));
        $tgMsgId = $m['message_id'] ?? null;
        $tgName = trim(($m['from']['first_name'] ?? '') . ' ' . ($m['from']['last_name'] ?? ''));

        $bind = tg_poll_find_binding($db, $chatId);
        $name = $bind ? $bind['employee_name'] : ($tgName !== '' ? $tgName . '（未綁定）' : '（未綁定）');

        // Telegram 不支援上傳附件（2026-07-07）：已綁定者傳照片/檔案 → 明確告知改由內網上傳
        //（原本會被當「(非文字訊息)」默默略過，使用者以為有送成功）
        $hasAttachment = false;
        foreach (['photo', 'document', 'video', 'audio', 'voice', 'video_note', 'animation', 'sticker'] as $k) {
            if (!empty($m[$k])) { $hasAttachment = true; break; }
        }
        if ($bind && $hasAttachment) {
            tg_send_text($chatId, '📎 Telegram 無法上傳附件，此檔案<b>未被系統接收</b>。附件請經由內網開啟系統，於公告的回覆區上傳。', $db);
            $caption = trim((string)($m['caption'] ?? ''));
            if ($caption === '') {
                tg_poll_log_in($db, $chatId, $name, '(附件訊息，已告知改由內網上傳)', $tgMsgId);
                return;
            }
            // 附件帶文字說明 → 文字部分照常走回覆流程（後續會收到「回覆已記錄/更新」或對應提示）
            $text = $caption;
        }

        // /start 或空訊息：僅記錄（供綁定工具頁查 chat_id 用）
        if (!$bind || $text === '' || $text === '/start') {
            tg_poll_log_in($db, $chatId, $name, $text !== '' ? $text : '(非文字訊息)', $tgMsgId);
            return;
        }

        $uid = (int)$bind['user_id'];

        // 1) 長按回覆：由被回覆的那則推播找到對應公告
        $eid = 0;
        if (!empty($m['reply_to_message']['message_id'])) {
            $st = $db->prepare("SELECT related_record_id FROM telegram_messages
                                WHERE direction='out' AND chat_id = ? AND telegram_message_id = ?
                                ORDER BY id DESC LIMIT 1");
            $st->execute([$chatId, $m['reply_to_message']['message_id']]);
            $eid = (int)$st->fetchColumn();
        }

        // 2) 未指定：以「最近 30 分鐘內按過的『輸入回覆』按鈕」對應——
        //    有些手機輸入時不會帶 reply_to，導致舊邏輯誤配到更早的待回覆公告（2026-07-07 修正）
        if ($eid <= 0) {
            $st = $db->prepare("SELECT related_record_id FROM telegram_messages
                                WHERE direction = 'out' AND chat_id = ? AND related_record_id IS NOT NULL
                                  AND message_text LIKE '✍️ 請輸入對%'
                                  AND sent_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                                ORDER BY id DESC LIMIT 1");
            $st->execute([$chatId]);
            $eid = (int)$st->fetchColumn();
        }

        // 3) 仍未指定：找此人「待回覆」的通知，恰好一筆才自動對應
        if ($eid <= 0) {
            $pending = tg_poll_pending_reply_events($db, $uid);
            if (count($pending) === 1) $eid = (int)$pending[0];
            else {
                tg_poll_log_in($db, $chatId, $name, $text, $tgMsgId);
                $hint = empty($pending)
                    ? '目前沒有需要您回覆的通知。若要回覆特定通知，請點該則通知的「✍️ 輸入回覆」按鈕，或長按該則通知訊息選「回覆」後輸入內容。'
                    : '您有 ' . count($pending) . ' 筆待回覆通知，請點要回覆的那則通知的「✍️ 輸入回覆」按鈕後再輸入內容。';
                tg_send_text($chatId, $hint, $db);
                return;
            }
        }

        // 是否為更新既有回覆（顯示訊息區分「已記錄 / 已更新」）
        $wasReplied = false;
        try {
            $q = $db->prepare("SELECT replied_at FROM live_event_response WHERE live_event_id = ? AND user_id = ?");
            $q->execute([$eid, $uid]);
            $wasReplied = !empty($q->fetch(PDO::FETCH_ASSOC)['replied_at'] ?? null);
        } catch (\Throwable $e) {}

        tg_poll_log_in($db, $chatId, $name, $text, $tgMsgId, $eid);
        $r = eg_telegram_record_response($db, $eid, $uid, 'reply', $text);
        if ($r['ok']) {
            $t = $db->prepare("SELECT title FROM live_event WHERE id = ?");
            $t->execute([$eid]);
            $title = (string)$t->fetchColumn();
            tg_send_text($chatId, ($wasReplied ? '✅ 回覆已更新：「' : '✅ 回覆已記錄：「') . htmlspecialchars(mb_substr($title, 0, 50)) . '」（同時完成回簽）', $db, $eid);
        } else {
            tg_send_text($chatId, '⚠️ 回覆記錄失敗：' . htmlspecialchars($r['msg'] ?: '系統錯誤'), $db, $eid);
        }
    }
}

if (!function_exists('tg_poll_pending_reply_events')) {
    /** 此人「通知方式=回覆」且尚未回覆、未過期的通知（近60天） */
    function tg_poll_pending_reply_events(PDO $db, int $uid): array
    {
        require_once __DIR__ . '/notify_event.php';
        $ids = [];
        $events = $db->query("SELECT DISTINCT le.id FROM live_event le
                              JOIN live_event_target t ON t.live_event_id = le.id
                              WHERE t.mode = 'reply'
                                AND le.eventdate >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                                AND (le.reply_deadline IS NULL OR le.reply_deadline >= CURDATE())
                              ORDER BY le.id DESC LIMIT 30")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($events as $eid) {
            $modes = eg_telegram_event_modes($db, (int)$eid);
            if (($modes[$uid] ?? '') !== 'reply') continue;
            $st = $db->prepare("SELECT replied_at FROM live_event_response WHERE live_event_id = ? AND user_id = ?");
            $st->execute([(int)$eid, $uid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || empty($row['replied_at'])) $ids[] = (int)$eid;
        }
        return $ids;
    }
}
