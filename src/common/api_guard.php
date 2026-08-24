<?php
/**
 * api_guard.php — API 端點的「在職狀態」守門（一行掛載）
 *
 * 用法：在 src/store/*_API.php 的 session_start(); 之後加一行
 *   require_once __DIR__ . '/../common/api_guard.php';
 *
 * 為什麼需要它（2026-08-24 查出的既有漏洞）：
 *   頁面的在職守門 eg_guard_active_session() 掛在側欄 sideAndTopBarMenu.html，
 *   但 src/store 底下的 API 不渲染側欄，拿不到那層保護。
 *   user_active_lib.php 早就備妥了對應的 eg_require_active_user_api()，卻 51 支 API 一支都沒用，
 *   等於離職／留停者只要 session 還活著、又只打 API 不開頁面，照樣撈得到資料。
 *
 * 行為：
 *   - 未登入（$_SESSION['id'] 空）→ 放行，交給各 API 原本的權限邏輯處理，本檔不越權；
 *   - 已登入且為離職／留職停薪／育嬰留停（或預定離職日已過）→ 回 403 JSON 並中止；
 *   - 資料庫一時異常 → 放行不擋人（與 eg_guard_active_session 的「DB異常不擋人」一致，
 *     否則 DB 抖一下就會讓全公司所有 API 全部 403）。
 *
 * 成本：本檔會另開一條 DB 連線做一次 SELECT。之所以不沿用呼叫端的 $db，是因為守門必須發生在
 *   各 API 載入自己的設定/連線「之前」（那時 $db 還不存在），而把檢查往後挪就會出現可繞過的空窗。
 *   本機 MySQL 連線成本極低，換到的是「不可能忘記掛」的一致性。
 */

if (!function_exists('eg_api_guard_run')) {
    function eg_api_guard_run(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        if (session_status() === PHP_SESSION_NONE && !headers_sent()) session_start();
        // 沒登入就沒有「在職與否」可言，直接放行（各 API 自己會擋未登入）
        if (empty($_SESSION['id'])) return;

        try {
            require_once __DIR__ . '/user_active_lib.php';
            require_once __DIR__ . '/DBConnection.php';
            eg_require_active_user_api((new DBConnection())->getPDO());
        } catch (Throwable $e) {
            error_log('[api_guard] skipped: ' . $e->getMessage());   // 擋不成不可反過來擋死所有人
        }
    }
}

eg_api_guard_run();
