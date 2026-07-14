<?php
// Telegram 推播函式庫（公告推播測試用，spec 01 最小子集）。
//   tg_is_configured()                                   Token 是否已設定
//   tg_send_text($chat_id, $text, ...)                   發送 HTML 格式文字訊息
//   tg_broadcast($chat_ids, $text, ...)                  批次發送（每則間隔 100ms）
//   tg_get_updates($offset)                              取得 bot 收到的訊息（綁定 chat_id 用）
//
// 規範（依 01 telegram bot spec.md）：
//   - 一律 curl、timeout 10 秒；失敗只 error_log，不輸出到畫面、不 die()
//   - 訊息內不放任何 URL 或連結
//   - Token 只放 config/telegram_config.php

require_once __DIR__ . '/../config/telegram_config.php';

if (!function_exists('tg_is_configured')) {
    function tg_is_configured(): bool
    {
        return defined('TELEGRAM_BOT_TOKEN')
            && TELEGRAM_BOT_TOKEN !== ''
            && TELEGRAM_BOT_TOKEN !== 'YOUR_BOT_TOKEN_HERE';
    }
}

if (!function_exists('tg_api')) {
    /**
     * 呼叫 Telegram Bot API。
     * @param int $curlTimeout curl 逾時秒數（長輪詢 getUpdates 需大於其 timeout 參數）
     * @return array 解析後的 JSON；失敗回 ['ok' => false]
     */
    function tg_api(string $method, array $params = [], int $curlTimeout = 10): array
    {
        if (!tg_is_configured()) {
            error_log('[telegram] Bot Token 未設定（config/telegram_config.php）');
            return ['ok' => false, 'description' => 'token not configured'];
        }
        $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/' . $method;

        // CA bundle 沿用 Web Push 的做法（MAMP 未設 curl.cainfo，不指定會 cURL error 60）
        $caFile = __DIR__ . '/../src/push/cacert.pem';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $params,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $curlTimeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if (is_file($caFile)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caFile);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            error_log('[telegram] ' . $method . ' curl error: ' . curl_error($ch));
            curl_close($ch);
            return ['ok' => false, 'description' => 'curl error'];
        }
        curl_close($ch);

        $res = json_decode($raw, true);
        if (!is_array($res)) {
            error_log('[telegram] ' . $method . ' invalid response: ' . mb_substr($raw, 0, 300));
            return ['ok' => false, 'description' => 'invalid response'];
        }
        if (empty($res['ok'])) {
            error_log('[telegram] ' . $method . ' failed: ' . ($res['description'] ?? json_encode($res)));
        }
        return $res;
    }
}

if (!function_exists('tg_log_message')) {
    /** 將推播寫入 telegram_messages（失敗不影響發送流程）；$file_path 供附件發送紀錄 */
    function tg_log_message(?PDO $db, string $direction, $chat_id, string $text, $tg_message_id = null, $related_id = null, ?string $file_path = null): void
    {
        if (!$db) return;
        try {
            $name = null;
            $st = $db->prepare("SELECT employee_name FROM telegram_users WHERE chat_id = ?");
            $st->execute([$chat_id]);
            $name = $st->fetchColumn() ?: null;
            $db->prepare("INSERT INTO telegram_messages (direction, chat_id, employee_name, message_text, telegram_message_id, related_record_id, file_path)
                          VALUES (?,?,?,?,?,?,?)")
               ->execute([$direction, $chat_id, $name, $text, $tg_message_id, $related_id, $file_path]);
        } catch (\Throwable $e) {
            error_log('[telegram] log message failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('tg_send_document')) {
    /**
     * 發送檔案（sendDocument；附件兩段式發送用，Bot 上限 50MB）。
     * @param string $filePath    實體檔案絕對路徑（通常為加註後的暫存檔，發送完由呼叫端刪除）
     * @param string $displayName Telegram 上顯示的檔名（副檔名需與實體檔一致）
     * @param string $caption     說明文字（純文字，≤1024 字）
     * @return array ['ok'=>bool, 'message_id'=>int|null]
     */
    function tg_send_document($chat_id, string $filePath, string $displayName = '', string $caption = '', ?PDO $db = null, $related_id = null): array
    {
        if (!is_file($filePath)) return ['ok' => false, 'message_id' => null];
        $mime = 'application/octet-stream';
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'pdf') $mime = 'application/pdf';
        elseif (in_array($ext, ['jpg', 'jpeg'], true)) $mime = 'image/jpeg';
        elseif ($ext === 'png') $mime = 'image/png';
        if ($displayName === '') $displayName = basename($filePath);
        $params = [
            'chat_id'  => $chat_id,
            'document' => new CURLFile($filePath, $mime, $displayName),
        ];
        if ($caption !== '') $params['caption'] = mb_substr($caption, 0, 1000);
        // 上傳檔案較耗時：curl 逾時放寬到 120 秒
        $res = tg_api('sendDocument', $params, 120);
        $msgId = $res['result']['message_id'] ?? null;
        if (!empty($res['ok'])) {
            tg_log_message($db, 'out', $chat_id, '[附件] ' . $displayName . ($caption !== '' ? '｜' . $caption : ''), $msgId, $related_id, $filePath);
            return ['ok' => true, 'message_id' => $msgId];
        }
        return ['ok' => false, 'message_id' => null];
    }
}

if (!function_exists('tg_send_text')) {
    /**
     * 發送 HTML 格式文字訊息（單則上限 4096 字元，超過 3500 自動切分連續發送）。
     * @param PDO|null $db           傳入時會寫 telegram_messages 紀錄
     * @param array|null $reply_markup Inline Keyboard，如 ['inline_keyboard'=>[[['text'=>'✅ 已閱','callback_data'=>'read:12']]]]
     *                                 切分多則時按鈕只附在最後一則
     * @return array ['ok' => bool, 'message_id' => int|null]
     */
    function tg_send_text($chat_id, string $text, ?PDO $db = null, $related_id = null, ?array $reply_markup = null): array
    {
        $chunks = [];
        while (mb_strlen($text) > 3500) {
            $chunks[] = mb_substr($text, 0, 3500);
            $text = mb_substr($text, 3500);
        }
        $chunks[] = $text;

        $lastId = null;
        $lastIdx = count($chunks) - 1;
        foreach ($chunks as $i => $chunk) {
            $params = [
                'chat_id'    => $chat_id,
                'text'       => $chunk,
                'parse_mode' => 'HTML',
            ];
            if ($reply_markup && $i === $lastIdx) $params['reply_markup'] = json_encode($reply_markup);
            $res = tg_api('sendMessage', $params);
            if (empty($res['ok'])) return ['ok' => false, 'message_id' => null];
            $lastId = $res['result']['message_id'] ?? null;
            tg_log_message($db, 'out', $chat_id, $chunk, $lastId, $related_id);
        }
        return ['ok' => true, 'message_id' => $lastId];
    }
}

if (!function_exists('tg_answer_callback')) {
    /** 回應按鈕點擊（清除按鈕的 loading 狀態），$text 會以小提示顯示給點擊者 */
    function tg_answer_callback(string $callback_query_id, string $text = ''): array
    {
        $params = ['callback_query_id' => $callback_query_id];
        if ($text !== '') $params['text'] = $text;
        return tg_api('answerCallbackQuery', $params);
    }
}

if (!function_exists('tg_edit_message')) {
    /** 更新已發送訊息的內容（純文字，不帶 parse_mode 以免原訊息含特殊字元解析失敗）；同時移除按鈕 */
    function tg_edit_message($chat_id, $message_id, string $new_text): array
    {
        return tg_api('editMessageText', [
            'chat_id'    => $chat_id,
            'message_id' => $message_id,
            'text'       => $new_text,
        ]);
    }
}

if (!function_exists('tg_broadcast')) {
    /**
     * 批次發送相同訊息給多個 chat_id（每則間隔 100ms 避免觸發速率限制）。
     * @return array ['sent' => int, 'failed' => int]
     */
    function tg_broadcast(array $chat_ids, string $text, ?PDO $db = null, $related_id = null): array
    {
        $sent = 0; $failed = 0;
        foreach ($chat_ids as $cid) {
            $r = tg_send_text($cid, $text, $db, $related_id);
            if ($r['ok']) $sent++; else $failed++;
            usleep(100000);
        }
        return ['sent' => $sent, 'failed' => $failed];
    }
}

if (!function_exists('tg_get_updates')) {
    /**
     * 取得 bot 收到的訊息。
     * @param int $timeout 長輪詢秒數：0=立即回，>0 時 Telegram 會掛住連線直到有新訊息或逾時，
     *                     按鈕點擊可在 1 秒內收到（curl 逾時自動加 10 秒緩衝）
     */
    function tg_get_updates(int $offset = 0, int $timeout = 0): array
    {
        return tg_api('getUpdates', ['offset' => $offset, 'timeout' => $timeout], $timeout + 10);
    }
}
