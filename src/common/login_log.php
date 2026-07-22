<?php
/**
 * login_log.php — 登入紀錄共用函式
 *
 * 由 src/store/Login.php 於登入成功/失敗時呼叫，寫入 login_log 表
 * （誰、何時、來源 IP、瀏覽器；失敗含原因），供 views/admin/audit_log_report.php 查詢。
 *
 * 硬性要求：完全靜默——記錄失敗絕不可影響登入流程，所有操作包 try/catch(Throwable)。
 * 保留期限：一年。順路觸發清理（約 2% 機率於寫入時刪除一年前舊資料），不依賴排程。
 */

if (!function_exists('eg_login_log')) {
    /**
     * @param PDO|null $pdo        沿用呼叫端連線；拿不到才自建（失敗靜默）
     * @param int|null $uid        使用者 id（帳號不存在時傳 null）
     * @param string   $uname      嘗試登入的帳號
     * @param bool     $success    是否登入成功
     * @param string|null $reason  失敗原因（密碼錯誤/帳號不存在/帳號停用）
     */
    function eg_login_log($pdo, ?int $uid, string $uname, bool $success, ?string $reason = null): void
    {
        try {
            if (!($pdo instanceof PDO)) {
                $pdo = new PDO(
                    'mysql:host=127.0.0.1;port=3306;dbname=EGsystem;charset=utf8mb4',
                    'EG-TS2024',
                    'excell30367593',
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]
                );
            }

            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
            if (function_exists('mb_substr')) {
                $uname = mb_substr($uname, 0, 100);
                $ua    = mb_substr($ua, 0, 255);
            } else {
                $uname = substr($uname, 0, 100);
                $ua    = substr($ua, 0, 255);
            }

            $pdo->prepare(
                "INSERT INTO login_log (user_id, user_uname, success, fail_reason, ip, user_agent)
                 VALUES (?,?,?,?,?,?)"
            )->execute([$uid, $uname, $success ? 1 : 0, $reason, $ip, ($ua !== '' ? $ua : null)]);

            // 順路清理：約 2% 機率刪除一年前舊紀錄（保留一年）
            if (mt_rand(1, 50) === 1) {
                $pdo->exec("DELETE FROM login_log WHERE created_at < NOW() - INTERVAL 1 YEAR");
            }
        } catch (Throwable $e) {
            // 靜默：記錄失敗絕不影響登入
        }
    }
}
