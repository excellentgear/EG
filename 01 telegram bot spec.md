# Telegram Bot 雙向通知功能 — AI 實作說明文件

## 專案背景

這是一個 PHP/MySQL 內網 ERP 系統，使用 MAMP 架設於區域網路，以 VS Code 開發。
目標是加入 Telegram Bot 推播功能，讓系統主動通知員工、接收回覆、並支援審批流程。

---

## 核心設計原則（請 AI 全程遵守）

1. **絕對不使用 Telegram 群組（Group Chat）**，所有推播一律為 Bot 對員工的私人訊息
2. **訊息內絕對不放任何 URL 或連結**，防止員工因信任 Bot 而點擊惡意連結
3. **不要猜測現有資料表的欄位名稱**，需要 join 現有表時請先列出需要哪些欄位等待確認
4. **不要修改現有頁面的 layout**，只在指定位置加入推播呼叫
5. **所有新增檔案請完整輸出**，不要省略任何部分
6. **curl 錯誤處理要完整**，失敗時 error_log 記錄，不要 die() 或 echo 錯誤到畫面
7. **Bot Token 只放在 `config/telegram_config.php`**，其他檔案不得硬寫 Token
8. **`poll_replies.php` 只能 CLI 執行**，不可從瀏覽器開啟

---

## 功能需求總覽

### 1. 推播通知（內網 → 手機）
- 系統事件觸發時（逾期工單、QC 異常、異常單建立），對指定員工發送私人訊息
- 支援純文字訊息
- 支援附件（PDF、圖片），直接附在 Telegram 訊息內，不使用連結
- 訊息格式使用 HTML parse_mode，支援粗體、等寬字、分隔線
- 用 emoji 取代顏色標示（🔴 逾期、🟡 待確認、🟢 完成、⚠️ 異常）

### 2. 接收回覆與按鈕確認（手機 → 內網）
- 使用 **Polling 方式**（非 Webhook），因內網無對外 IP
- PHP CLI 腳本 + cron job 每分鐘執行一次
- 同時處理兩種用戶互動：
  - **文字回覆**（普通訊息）
  - **按鈕點擊**（Inline Keyboard callback_query）

### 3. Inline Keyboard 按鈕
- 推播訊息可附帶按鈕，例如「✅ 已閱讀確認」「🔄 已回遷」
- 員工點按鈕後，Bot 自動更新原訊息，顯示目前各人確認狀態
- 按鈕點擊記錄寫入資料庫，與文字回覆統一處理

### 4. 主動轉播機制
- A 員工回覆或點按鈕後，系統自動發私訊給其他相關人員
- 轉播訊息格式：`📢 王小明 於 14:32 確認：「已回遷完成」（異常單 #1234）`
- 轉播訊息本身不帶按鈕，不觸發二次轉播（`is_relay=1` 標記）

### 5. 全員完成後通知主管
- 每張異常單有指定的必須回覆人員清單
- 每次收到回覆或按鈕點擊後，檢查該異常單是否所有必填人員都已確認
- 全員完成後自動推播給主管，附帶「✅ 已閱讀此異常單」按鈕
- 主管點按鈕後，異常單狀態更新為結案，所有相關人員收到轉播通知

### 6. 表格訊息處理
- 使用 `<pre>` 等寬區塊模擬表格，不使用連結跳頁
- 單則訊息上限 4096 字元，超過 3500 字元時自動切分為多則連續發送
- 優先只發異常或待處理項目，減少訊息長度

---

## 資料庫設計

請在現有 MySQL 中新增以下四張表：

### `telegram_users` — 員工與 Telegram 對應表

```sql
CREATE TABLE telegram_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_name VARCHAR(100) NOT NULL COMMENT '員工姓名',
    chat_id BIGINT NOT NULL UNIQUE COMMENT 'Telegram chat_id',
    is_active TINYINT(1) DEFAULT 1 COMMENT '1=啟用',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### `telegram_messages` — 推播與回覆紀錄表

```sql
CREATE TABLE telegram_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    direction ENUM('out', 'in') NOT NULL COMMENT 'out=系統推播, in=使用者回覆',
    chat_id BIGINT NOT NULL,
    employee_name VARCHAR(100) COMMENT '員工姓名',
    message_text TEXT COMMENT '訊息內容',
    file_path VARCHAR(500) COMMENT '附件路徑（如有）',
    telegram_message_id BIGINT COMMENT 'Telegram 原始 message_id（用於更新訊息）',
    related_record_id INT COMMENT '關聯的 ERP 記錄 ID（例如異常單ID）',
    is_relay TINYINT(1) DEFAULT 0 COMMENT '1=此筆為轉播訊息，不再觸發轉播',
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### `abnormal_notify_list` — 異常單應通知人員表

```sql
CREATE TABLE abnormal_notify_list (
    id INT AUTO_INCREMENT PRIMARY KEY,
    abnormal_id INT NOT NULL COMMENT '異常單ID',
    chat_id BIGINT NOT NULL COMMENT '應通知員工的 chat_id',
    employee_name VARCHAR(100),
    is_required TINYINT(1) DEFAULT 1 COMMENT '1=必須回覆才算全員完成',
    telegram_message_id BIGINT COMMENT '發給此人的訊息ID（用於之後更新按鈕狀態）',
    replied_at TIMESTAMP NULL COMMENT '回覆或點按鈕的時間',
    reply_content VARCHAR(500) COMMENT '回覆內容或按鈕動作',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### `abnormal_status` — 異常單整體狀態表

```sql
CREATE TABLE abnormal_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    abnormal_id INT NOT NULL UNIQUE COMMENT '異常單ID',
    status ENUM('pending', 'all_replied', 'supervisor_confirmed', 'closed')
        DEFAULT 'pending',
    all_replied_at TIMESTAMP NULL COMMENT '全員完成時間',
    supervisor_chat_id BIGINT COMMENT '主管的 chat_id',
    supervisor_confirmed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

---

## 需要新增的 PHP 檔案

### `config/telegram_config.php` — 設定檔

```php
<?php
define('TELEGRAM_BOT_TOKEN', 'YOUR_BOT_TOKEN_HERE');

// 主管的 chat_id（全員回覆完後通知此人）
define('TELEGRAM_SUPERVISOR_CHAT_ID', 'SUPERVISOR_CHAT_ID_HERE');
```

---

### `telegram/send_message.php` — 推播函式庫

提供以下函式，供其他頁面 include 後呼叫：

#### `tg_send_text($chat_id, $text, $reply_markup = null, $related_id = null)`
- 向指定 chat_id 發送 HTML 格式純文字訊息
- `$reply_markup`：選填，傳入 Inline Keyboard 陣列
- 成功後將訊息寫入 `telegram_messages`（direction='out'）
- 回傳：`['ok' => true, 'message_id' => 123]` 或 `['ok' => false]`

Inline Keyboard 範例格式：
```php
$reply_markup = [
    'inline_keyboard' => [[
        ['text' => '✅ 已閱讀確認', 'callback_data' => 'confirm_read:1234'],
        ['text' => '🔄 已回遷',     'callback_data' => 'confirm_return:1234'],
    ]]
];
```

#### `tg_send_document($chat_id, $file_path, $caption = '', $related_id = null)`
- 發送本地端檔案（PDF/圖片），使用 multipart/form-data
- caption 使用 HTML parse_mode
- 成功後寫入 `telegram_messages`
- 回傳同上

#### `tg_edit_message($chat_id, $message_id, $new_text)`
- 更新已發送訊息的內容（例如：將按鈕狀態從「待確認」改為顯示確認名單）
- 使用 `editMessageText` API

#### `tg_broadcast($chat_ids_array, $text, $reply_markup = null)`
- 批次發送相同訊息給多個 chat_id
- 內部迴圈呼叫 `tg_send_text`
- 每次發送間隔 100ms（`usleep(100000)`）避免觸發 Telegram 速率限制

#### `tg_answer_callback($callback_query_id, $text = '')`
- 回應 callback_query，讓按鈕不再顯示 loading 狀態
- 使用 `answerCallbackQuery` API

**共用注意事項：**
- 所有 API 呼叫使用 PHP curl，不使用 file_get_contents
- curl timeout 設為 10 秒
- 失敗時 error_log 記錄完整錯誤，不輸出到畫面

---

### `telegram/poll_replies.php` — Polling 腳本（CLI 專用）

```php
<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
```

**執行邏輯：**

1. 讀取 `telegram/last_update_id.txt` 取得上次處理位置
2. 呼叫 `getUpdates?offset={last_update_id+1}&timeout=0`
3. 遍歷每筆 update，判斷類型：

**若為普通訊息（`update.message`）：**
- 取得 chat_id、text、from.first_name、date（轉為 datetime）
- 查詢 `telegram_users` 找員工名稱（找不到填 'unknown'）
- INSERT 進 `telegram_messages`（direction='in'，is_relay=0）
- 執行轉播：查詢 `telegram_users` 所有 is_active=1 且 chat_id != 發訊者的員工，逐一發私訊
- 轉播訊息寫入 `telegram_messages`（is_relay=1）
- 若訊息關聯到某異常單，呼叫 `check_all_replied($abnormal_id)`

**若為按鈕點擊（`update.callback_query`）：**
- 取得 callback_query_id、chat_id、data（格式：`action:abnormal_id`）
- 呼叫 `tg_answer_callback($callback_query_id)` 先清除 loading
- 解析 data，例如 `confirm_read:1234` → action=confirm_read, abnormal_id=1234
- 更新 `abnormal_notify_list` 的 replied_at 與 reply_content
- 呼叫 `update_message_status($abnormal_id)` 更新所有人的訊息顯示確認狀態
- 執行轉播通知其他人
- 呼叫 `check_all_replied($abnormal_id)`

4. 更新 `last_update_id.txt`

**`check_all_replied($abnormal_id)` 函式邏輯：**
```
查詢 abnormal_notify_list
WHERE abnormal_id = $abnormal_id
AND is_required = 1
AND replied_at IS NULL

若筆數為 0（全員完成）：
  更新 abnormal_status.status = 'all_replied'
  更新 abnormal_status.all_replied_at = NOW()
  組合通知文字：列出所有人的確認時間與內容
  發送給主管（帶「✅ 已閱讀此異常單」按鈕）
```

**`update_message_status($abnormal_id)` 函式邏輯：**
```
查詢 abnormal_notify_list 所有人的回覆狀態
組合新的訊息文字，例如：
  ✅ 王小明 14:32 已確認
  ✅ 李大華 14:45 已回遷
  ⏳ 陳志明 未確認

對每個人的 telegram_message_id 呼叫 tg_edit_message 更新訊息內容
```

**cron job 設定（每分鐘執行）：**
```
* * * * * /usr/bin/php /path/to/project/telegram/poll_replies.php >> /tmp/tg_poll.log 2>&1
```

---

### `telegram/notify_abnormal.php` — 異常單推播入口

供現有 ERP 頁面 include 後呼叫，發起一張異常單的通知流程：

#### `notify_abnormal($abnormal_id, $message_text, $notify_chat_ids, $supervisor_chat_id, $file_path = null)`

參數說明：
- `$abnormal_id`：異常單 ID
- `$message_text`：推播訊息內文（HTML 格式）
- `$notify_chat_ids`：應通知員工的 chat_id 陣列（含 is_required 旗標）
- `$supervisor_chat_id`：主管 chat_id，全員完成後通知此人
- `$file_path`：選填，附件路徑

執行步驟：
1. INSERT `abnormal_status`（status='pending'）
2. 對每位員工發送訊息（帶 Inline Keyboard 按鈕）
3. 將回傳的 message_id 存入 `abnormal_notify_list`
4. INSERT `abnormal_notify_list` 每筆人員紀錄

呼叫範例：
```php
require_once __DIR__ . '/telegram/notify_abnormal.php';

notify_abnormal(
    abnormal_id: 1234,
    message_text: "⚠️ <b>異常單 #1234</b>\n品檢發現 A 料件尺寸超差\n請相關人員確認後點選下方按鈕",
    notify_chat_ids: [
        ['chat_id' => 111111, 'is_required' => 1],
        ['chat_id' => 222222, 'is_required' => 1],
        ['chat_id' => 333333, 'is_required' => 0], // 知會但不需回覆
    ],
    supervisor_chat_id: 999999,
    file_path: '/path/to/qc_report_1234.pdf'
);
```

---

### `telegram/replies_view.php` — 回覆查看頁面（內網用）

顯示近期推播與回覆紀錄，供內網電腦查看。

- 查詢 `telegram_messages` 最近 100 筆，依 sent_at 降冪
- 表格欄位：時間、方向（推播／回覆）、員工姓名、訊息內容、關聯異常單
- direction='out' 淡藍色背景，direction='in' 淡綠色背景，is_relay=1 淡灰色
- 另有異常單審批狀態區塊：查詢 `abnormal_status` 列出各單狀態
- 頁面樣式沿用現有 ERP 系統 CSS，不引入外部框架

---

## 訊息格式規範

### 一般推播範例
```
⚠️ <b>異常單 #1234</b>
品檢發現 A 料件尺寸超差
負責人：王小明
發生時間：2024-01-15 14:00

請點選下方按鈕確認
```

### 表格型訊息（超過 3500 字元時自動切分）
```
📋 <b>今日逾期工單（共 5 筆）</b>

<pre>
工單     負責人   逾期天數
#1231   王小明     3天
#1234   李大華     1天
#1238   陳志明     5天
</pre>

🔴 請優先處理逾期 3 天以上項目
```

### 全員完成通知主管
```
✅ <b>異常單 #1234 所有人員已確認</b>

王小明  14:32  已閱讀確認
李大華  14:45  已回遷完成
陳志明  15:01  已閱讀確認

請主管點選下方按鈕完成結案
```
