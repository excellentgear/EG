# 4G Web Push 審批流程功能 — AI 實作說明文件

## 專案背景

這是一個 PHP/MySQL 內網 ERP 系統，使用 MAMP 架設於區域網路。
此文件描述的是**備用方案**：當 Telegram Bot 推廣困難時，改用瀏覽器原生 Web Push 通知，搭配一個對外安全的**精簡回覆專用頁面**，讓員工用 4G 手機也能收到通知並回覆。

---

## 核心設計原則（請 AI 全程遵守）

1. **對外只暴露一個精簡的回覆專用頁面**，與完整 ERP 系統完全隔離
2. **回覆頁面只能做三件事**：看自己的通知、回覆、看其他人對同一則通知的回覆
3. **回覆頁面絕對不能存取 ERP 其他資料**，不能跳轉到其他 ERP 頁面
4. **傳輸必須使用 HTTPS**，不可用 HTTP
5. **不要猜測現有資料表的欄位名稱**，需要 join 現有表時請先列出需要哪些欄位等待確認
6. **不要修改現有 ERP 頁面**，只在指定位置加入推播呼叫
7. **所有新增檔案請完整輸出**，不要省略任何部分
8. **PHP 錯誤處理要完整**，失敗時 error_log 記錄，不要 die() 或 echo 錯誤到畫面

---

## 架構說明

```
【內網 ERP 伺服器】
  ├── 現有 ERP 系統（只有內網能連）
  └── reply/ 回覆專用頁面（可對外，但功能極度受限）
        ├── 只讀取 abnormal_replies 相關表
        ├── 完全隔離，無法存取其他 ERP 資料
        └── 必須 HTTPS + Token 驗證才能開啟

【員工手機 4G】
  接收 Web Push 通知
  → 點通知開啟 reply/ 頁面
  → 輸入回覆送出
  → 看到其他人的回覆

【內網 PHP 後端】
  每次有人回覆 → 檢查是否全員完成
  → 全員完成 → Web Push 推播給主管
  → 主管點「已閱讀確認」→ 異常單結案
  → 推播通知所有相關人員結案
```

---

## 資安設計

### 回覆頁面存取控制

每次系統發出通知時，為每位員工產生一個**一次性 Token**：

- Token 為 64 字元隨機字串（`bin2hex(random_bytes(32))`）
- 存入資料庫，綁定員工 ID + 異常單 ID
- 有效期限：72 小時
- 使用後**不失效**（員工可重複開啟查看，但不能查看其他異常單）
- Token 只出現在推播通知的開啟動作中，不以任何方式顯示在畫面上

員工點推播通知時，瀏覽器開啟：
```
https://your-domain/reply/?t=TOKEN
```

後端驗證 Token → 確認員工身份與異常單 → 只顯示該異常單的回覆頁面。

### HTTPS 要求

回覆頁面必須透過 HTTPS 對外，建議方案：
- **Cloudflare Tunnel**（不需固定 IP，免費，設定相對簡單）
- 自架 Nginx 反向代理 + Let's Encrypt 憑證

Web Push 本身也強制要求 HTTPS，無 HTTPS 則無法運作。

---

## 資料庫設計

請在現有 MySQL 中新增以下四張表：

### `push_subscriptions` — 員工推播訂閱資料表

```sql
CREATE TABLE push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL COMMENT '對應員工ID（請確認現有員工表的主鍵欄位名稱）',
    employee_name VARCHAR(100),
    endpoint TEXT NOT NULL COMMENT 'Web Push endpoint URL',
    p256dh VARCHAR(500) NOT NULL COMMENT '加密公鑰',
    auth VARCHAR(200) NOT NULL COMMENT 'Auth secret',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_endpoint (endpoint(255))
);
```

### `abnormal_notify_list` — 異常單應通知人員表

```sql
CREATE TABLE abnormal_notify_list (
    id INT AUTO_INCREMENT PRIMARY KEY,
    abnormal_id INT NOT NULL COMMENT '異常單ID',
    employee_id INT NOT NULL,
    employee_name VARCHAR(100),
    is_required TINYINT(1) DEFAULT 1 COMMENT '1=必須回覆才算全員完成',
    access_token VARCHAR(128) NOT NULL COMMENT '此員工存取此異常單的一次性Token',
    token_expires_at DATETIME NOT NULL COMMENT 'Token 有效期限',
    replied_at TIMESTAMP NULL,
    reply_content TEXT COMMENT '回覆內容',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### `abnormal_replies` — 回覆紀錄表（公開顯示用）

```sql
CREATE TABLE abnormal_replies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    abnormal_id INT NOT NULL,
    employee_name VARCHAR(100) NOT NULL,
    reply_text TEXT NOT NULL,
    action_type ENUM('text', 'confirm_read', 'confirm_return', 'supervisor_close')
        DEFAULT 'text' COMMENT '回覆類型',
    replied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### `abnormal_status` — 異常單整體狀態表

```sql
CREATE TABLE abnormal_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    abnormal_id INT NOT NULL UNIQUE,
    status ENUM('pending', 'all_replied', 'supervisor_confirmed', 'closed')
        DEFAULT 'pending',
    all_replied_at TIMESTAMP NULL,
    supervisor_employee_id INT COMMENT '主管員工ID',
    supervisor_confirmed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

---

## 需要新增的 PHP 檔案

### `config/webpush_config.php` — 設定檔

```php
<?php
// VAPID 金鑰（用 web-push 套件產生，只需產生一次）
define('VAPID_PUBLIC_KEY',  'YOUR_VAPID_PUBLIC_KEY');
define('VAPID_PRIVATE_KEY', 'YOUR_VAPID_PRIVATE_KEY');
define('VAPID_SUBJECT',     'mailto:admin@yourcompany.com');

// 主管員工ID（全員完成後通知此人）
define('SUPERVISOR_EMPLOYEE_ID', 1);
```

---

### `webpush/send_push.php` — 推播函式庫

使用 `minishlink/web-push` 套件（透過 Composer 安裝）。

安裝指令：
```bash
composer require minishlink/web-push
```

提供以下函式：

#### `send_push_to_employee($employee_id, $title, $body, $token = null)`
- 查詢 `push_subscriptions` 取得該員工的訂閱資料
- 組合 payload：`{ title, body, token }`（token 供 Service Worker 組合開啟網址）
- 發送 Web Push
- 回傳：成功 true，失敗 false 並 error_log

#### `send_push_to_all($employee_ids_array, $title, $body, $tokens_map)`
- 批次推播給多位員工
- `$tokens_map`：`[employee_id => token]` 的對應陣列，每人 token 不同
- 內部迴圈呼叫 `send_push_to_employee`

---

### `webpush/notify_abnormal.php` — 異常單推播入口

供現有 ERP 頁面 include 後呼叫：

#### `notify_abnormal($abnormal_id, $title, $body, $notify_employees, $supervisor_employee_id)`

參數說明：
- `$abnormal_id`：異常單 ID
- `$title`：通知標題
- `$body`：通知內文
- `$notify_employees`：應通知員工陣列，格式：`[['employee_id'=>1, 'is_required'=>1], ...]`
- `$supervisor_employee_id`：主管員工 ID

執行步驟：
1. INSERT `abnormal_status`（status='pending'）
2. 對每位員工產生 Token，INSERT `abnormal_notify_list`
3. 呼叫 `send_push_to_all` 批次推播（payload 含各人專屬 token）
4. Service Worker 收到推播後，以 token 組合回覆頁面網址

呼叫範例：
```php
require_once __DIR__ . '/webpush/notify_abnormal.php';

notify_abnormal(
    abnormal_id: 1234,
    title: '⚠️ 異常單 #1234',
    body: '品檢發現 A 料件尺寸超差，請確認',
    notify_employees: [
        ['employee_id' => 1, 'is_required' => 1],
        ['employee_id' => 2, 'is_required' => 1],
        ['employee_id' => 3, 'is_required' => 0],
    ],
    supervisor_employee_id: 5
);
```

---

### `reply/index.php` — 回覆專用頁面（對外）

**此頁面是唯一對外開放的頁面，與 ERP 其他頁面完全隔離。**

#### 安全驗證流程（每次請求都必須執行）

```php
// 1. 取得 Token
$token = $_GET['t'] ?? $_POST['t'] ?? '';
if (empty($token)) { http_response_code(403); exit; }

// 2. 查詢資料庫驗證 Token
$row = ... // SELECT * FROM abnormal_notify_list
          // WHERE access_token = $token
          // AND token_expires_at > NOW()
if (!$row) { http_response_code(403); exit('連結已失效'); }

$abnormal_id   = $row['abnormal_id'];
$employee_name = $row['employee_name'];
$already_replied = !is_null($row['replied_at']);
```

#### 頁面顯示內容

1. **異常單資訊區塊**（從異常單相關表查詢，**只查詢必要欄位**，不顯示其他 ERP 資料）
2. **其他人的回覆串**：查詢 `abnormal_replies` WHERE abnormal_id = $abnormal_id，依時間升冪顯示
3. **回覆輸入區**：文字輸入框 + 送出按鈕 + 快速按鈕（✅ 已閱讀確認、🔄 已回遷）
4. 若 `$already_replied = true`，仍可看到回覆串，但回覆框改為「您已於 XX:XX 回覆」提示

#### POST 回覆處理流程

```
接收 POST（含 token + reply_text 或 action_type）
→ 再次驗證 Token
→ INSERT abnormal_replies
→ UPDATE abnormal_notify_list SET replied_at = NOW(), reply_content = ...
→ 呼叫 check_all_replied($abnormal_id)
→ 回傳 JSON { success: true }（AJAX 送出）
```

#### `check_all_replied($abnormal_id)` 函式

```
查詢 abnormal_notify_list
WHERE abnormal_id = $abnormal_id
AND is_required = 1
AND replied_at IS NULL

若筆數為 0（全員完成）：
  UPDATE abnormal_status SET status='all_replied', all_replied_at=NOW()
  查詢主管的 push_subscriptions
  產生主管的 access_token（INSERT abnormal_notify_list，is_required=1）
  組合通知內文（列出所有人的確認時間）
  send_push_to_employee(主管, '請確認異常單 #1234', 內文, 主管token)
```

#### 頁面 UI 規格

- 極簡設計，只有必要元素，不顯示任何導航選單或其他系統連結
- 使用 AJAX 送出回覆，不整頁重新整理
- 送出後即時更新回覆串（顯示所有人的回覆，含剛剛自己的）
- 自動每 30 秒 fetch 一次最新回覆串，讓用戶看到其他人的新回覆
- 頁面樣式使用簡潔的 inline CSS，不引入外部框架或 CDN

---

### `reply/sw.js` — Service Worker

放在 `reply/` 目錄下（或網站根目錄，視 scope 設定）：

```javascript
self.addEventListener('push', function(event) {
    const data = event.data.json();
    const token = data.token || '';

    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: '/icons/icon-192.png',
            badge: '/icons/badge-72.png',
            data: { token: token }
        })
    );
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    const token = event.notification.data.token;
    event.waitUntil(
        clients.openWindow('/reply/?t=' + token)
    );
});
```

---

### `reply/subscribe.php` — 訂閱推播 API

員工首次開啟回覆頁面時，前端 JS 向此 API 送出訂閱資料：

- 接收 POST JSON：`{ employee_id, endpoint, p256dh, auth }`
- UPSERT `push_subscriptions`（endpoint 已存在則更新金鑰）
- 回傳 `{ success: true }`

**注意：** 此 API 也需要 Token 驗證，避免任意人員訂閱推播。

---

## 前端訂閱流程（在 `reply/index.php` 內的 JavaScript）

```javascript
// 註冊 Service Worker
const reg = await navigator.serviceWorker.register('/reply/sw.js');

// 訂閱推播
const sub = await reg.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: 'YOUR_VAPID_PUBLIC_KEY'
});

// 送出訂閱資料給後端
await fetch('/reply/subscribe.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        token: TOKEN,  // 從 URL 取得
        endpoint: sub.endpoint,
        p256dh: btoa(String.fromCharCode(...new Uint8Array(sub.getKey('p256dh')))),
        auth:   btoa(String.fromCharCode(...new Uint8Array(sub.getKey('auth'))))
    })
});
```

---

## 主管確認流程

主管收到推播後開啟回覆頁面，頁面顯示：
- 所有員工的回覆內容與時間
- 大型「✅ 確認已閱讀，結案此異常單」按鈕

主管點按鈕後：
1. INSERT `abnormal_replies`（action_type='supervisor_close'）
2. UPDATE `abnormal_status` SET status='closed', supervisor_confirmed_at=NOW()
3. 推播給所有相關員工：「異常單 #1234 已由主管於 XX:XX 確認結案」

---

## 與 Telegram 方案的差異

| 項目 | Telegram Bot 方案 | 4G Web Push 方案 |
|------|-----------------|-----------------|
| 員工需安裝 | Telegram App | 只需瀏覽器，加入主畫面即可 |
| 推廣難度 | 需員工有並使用 Telegram | 較低，手機瀏覽器即可 |
| iOS 支援 | ✅ 完整 | ⚠️ iOS 16.4+ 才支援 Web Push |
| 按鈕確認 | ✅ Inline Keyboard 原生 | 需自製網頁按鈕 |
| 資安複雜度 | 較低 | 較高（需 HTTPS、Token 驗證、隔離） |
| 附件傳送 | ✅ 直接附在訊息 | 只顯示異常單文字資訊 |
| 實作複雜度 | 中 | 高 |
