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

// === session 被垃圾回收誤刪的防護（2026-08-21）===
// 症狀：使用者表單填到一半按儲存，跳「連線憑證失效，請重新整理頁面後再試 (CSRF)」。
// 根因不是 CSRF 寫錯，是 session 檔已經被 PHP 的 GC 刪掉＝人其實已經被登出：
//   ① Apache 的 php.ini 是 session.gc_maxlifetime=1440（24 分）。本檔雖然 ini_set 成 12 小時，
//      但站上有二十幾支 API（Part_Attachment_API、Quotation_API、Roles_API…）是自己
//      session_start() 而沒有載入本檔的，那些請求跑的就是 24 分鐘。
//   ② PHP files handler 預設 lazy_write：session 內容沒變就不重寫檔案，檔案 mtime 會停在
//      「最後一次資料有變」的時間。所以一直在翻頁的人，session 檔的 mtime 也可能是 30 分鐘前。
// ①＋② 一起發生時，GC（1/1000 機率）會把「還在使用中」的 session 檔一併刪除。
// 解法：每次請求把 session 檔的 mtime 推到現在（只動時間、不重寫內容，620KB 的大 session 也不會有寫入成本）。
if (session_status() === PHP_SESSION_ACTIVE) {
    $__sp = (string)session_save_path();
    // save_path 可能是 "N;/path" 或 "N;MODE;/path" 的分層格式，取最後一段才是真正的目錄
    if ($__sp !== '' && ($__pos = strrpos($__sp, ';')) !== false) $__sp = substr($__sp, $__pos + 1);
    if ($__sp !== '') {
        $__sf = rtrim($__sp, "\/") . DIRECTORY_SEPARATOR . 'sess_' . session_id();
        if (is_file($__sf)) @touch($__sf);
    }
    unset($__sp, $__sf, $__pos);
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

// === 報價單暫存/垃圾附件自動清除 順路觸發（2026-07-22 新增；做法同上，免工作排程器）===
// 距上次清除超過 3600 秒才執行，永久刪除逾期未存檔(temp)與補件被否決(trash)的附件（實體檔＋DB列）
try {
    require_once __DIR__ . '/quotation_attach_tick.php';
    eg_quotation_attach_tick($db);
} catch (Throwable $e) {
    error_log('[quot_attach] tick hook failed: ' . $e->getMessage());
}

// === 資料庫自動備份 順路觸發（2026-07-22 新增；做法同上，免工作排程器）===
// 距上次檢查超過 600 秒才背景啟動備份工人；是否真的執行備份由工人依 interval_days 判斷
try {
    require_once __DIR__ . '/db_backup_tick.php';
    eg_db_backup_tick();
} catch (Throwable $e) {
    error_log('[db_backup] tick hook failed: ' . $e->getMessage());
}

// === 預定離職日到期自動轉離職 順路觸發（2026-07-30 新增；做法同上，免工作排程器）===
// 距上次檢查超過 3600 秒才掃描；封鎖本身在判斷時就生效，這支只負責把 state/歷程/稽核補寫進資料
try {
    require_once __DIR__ . '/user_leave_tick.php';
    eg_user_leave_tick($db);
} catch (Throwable $e) {
    error_log('[user_leave] tick hook failed: ' . $e->getMessage());
}

// === 移機快速備份 順路觸發（2026-07-24 新增；做法同上，免工作排程器）===
// 距上次檢查超過 3600 秒才背景啟動；是否執行由工人依 migbk_interval_days 判斷(0=未啟用)
try {
    require_once __DIR__ . '/mig_backup_tick.php';
    eg_migbk_tick();
} catch (Throwable $e) {
    error_log('[mig_backup] tick hook failed: ' . $e->getMessage());
}
