<?php
// === session 設定（放最前面，且避免重複啟動） ===

// 如果 session 尚未啟動，才進行設定與啟動
if (session_status() === PHP_SESSION_NONE) {

    // 伺服器端 session 保存時間（瀏覽器開著就保持登入）
    $lifetime = 12 * 60 * 60; // 12 小時，可自行調整

    // 伺服器端：session 過期時間
    ini_set('session.gc_maxlifetime', $lifetime);

    // 瀏覽器端 cookie：關閉瀏覽器即失效
    ini_set('session.cookie_lifetime', 0);

    // 重設 cookie 參數
    session_set_cookie_params([
        'lifetime' => 0,    // 0 = 關閉瀏覽器自動登出
        'httponly' => true,
        'secure' => false,  // 若你用 https 要改 true
        'samesite' => 'Lax'
    ]);

    session_start();
}



// === MySQL 設定 ===
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306", "EG-TS2024", "excell30367593");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// utf8mb4：utf8(mb3) 的超集。utf8mb3 塞不下 4-byte 字元（📋🔔 等 emoji），
// 寫入會變 '?'、讀取正確資料也會顯示成 '?'（與 DBConnection.php 一致）
$db->exec("set names utf8mb4");

// === MySQL SQL MODE 設定 ===
try {
    $db->exec("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
} catch (PDOException $e) {
    die("設定 SQL 模式失敗: " . $e->getMessage());
}

// === Telegram 順路輪詢（2026-07-07 恢復啟用；原 2026-07-06 暫停測試）===
// 頁面請求時若距上次收訊超過 60 秒，背景啟動輪詢腳本收取 Telegram 按鈕/文字回覆
// （免工作排程器；背景啟動立即返回，不阻塞頁面；失敗不影響頁面）
try {
    require_once __DIR__ . '/telegram_tick.php';
    eg_telegram_tick();
} catch (Throwable $e) {
    error_log('[telegram] tick hook failed: ' . $e->getMessage());
}

// === 個人工作紀錄提醒 順路觸發（2026-07-16 新增；做法同上，免工作排程器）===
// 距上次檢查超過 120 秒才背景啟動提醒腳本，掃描到期的期限/進度提醒並推播（Web Push + Telegram）
try {
    require_once __DIR__ . '/personal_task_tick.php';
    eg_personal_task_tick();
} catch (Throwable $e) {
    error_log('[ptask] tick hook failed: ' . $e->getMessage());
}

// === 矯正單(CAR)逾期提醒 順路觸發（2026-07-20 新增；做法同上，免工作排程器）===
// 距上次檢查超過 900 秒才背景啟動掃描，卡關超過設定工作天數的單據依狀態通知相關人員
try {
    require_once __DIR__ . '/car_remind_tick.php';
    eg_car_remind_tick();
} catch (Throwable $e) {
    error_log('[car_remind] tick hook failed: ' . $e->getMessage());
}
