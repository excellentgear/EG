<?php
/**
 * page_visit_logger.php — 頁面使用統計記錄器
 *
 * 由 views/partPage/sideAndTopBarMenu.html 最上方 @include_once 掛載，
 * 對每次「頁面開啟」在 page_visit_stats 以（頁 × 日 × 人）彙總 +1。
 * AJAX 請求不渲染側欄，自然不會被記錄（設計上只記頁面開啟）。
 *
 * 硬性要求：整段完全靜默——任何 DB／程式錯誤都不可影響頁面顯示，
 * 記錄功能壞了寧可不記。故所有操作皆包 try/catch(Throwable)，
 * 且不用 DBConnection（其連線失敗與 SQL 錯誤會 echo/die）。
 */

if (!function_exists('eg_page_visit_log')) {
    /**
     * @param PDO|null $pdo 沿用呼叫端既有連線；拿不到才自建（失敗靜默）
     */
    function eg_page_visit_log($pdo = null): void
    {
        static $done = false;          // 同一 request 只記一次
        if ($done) return;
        $done = true;

        try {
            // 未登入不記
            $uid = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
            if ($uid <= 0) return;

            // 頁面路徑：SCRIPT_NAME 去掉 /EGsystem 前綴、統一斜線方向
            $path = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
            if ($path === '') return;
            if (stripos($path, '/EGsystem/') === 0) {
                $path = substr($path, strlen('/EGsystem'));
            }
            if (function_exists('mb_substr')) {
                $path = mb_substr($path, 0, 191);
            } else {
                $path = substr($path, 0, 191);
            }

            if (!($pdo instanceof PDO)) {
                // 與 DBConnection.php 相同連線參數，但失敗只丟例外、不 die
                $pdo = new PDO(
                    'mysql:host=127.0.0.1;port=3306;dbname=EGsystem;charset=utf8mb4',
                    'EG-TS2024',
                    'excell30367593',
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]
                );
            }

            $stmt = $pdo->prepare(
                "INSERT INTO page_visit_stats (page_path, visit_date, user_id, visit_count, last_visit_at)
                 VALUES (?, CURDATE(), ?, 1, NOW())
                 ON DUPLICATE KEY UPDATE visit_count = visit_count + 1, last_visit_at = NOW()"
            );
            $stmt->execute([$path, $uid]);
        } catch (Throwable $e) {
            // 靜默：記錄失敗絕不影響頁面
        }
    }
}

// 立即呼叫：優先沿用 include 時點已存在的連線（頁面或側欄常見變數名）
try {
    $egPvPdo = null;
    if (isset($pdo) && $pdo instanceof PDO) {
        $egPvPdo = $pdo;
    } elseif (isset($db) && $db instanceof PDO) {
        $egPvPdo = $db;
    } elseif (isset($conn) && $conn instanceof DBConnection) {
        $egPvPdo = $conn->getPDO();
    }
    eg_page_visit_log($egPvPdo);
    unset($egPvPdo);
} catch (Throwable $e) {
    // 靜默
}
