<?php
/**
 * 自動簽核「蓋章時間」共用計算（唯一實作，禁止各模組自己算）— 2026-09-07 新增
 *
 * 使用者 2026-09-07 明確要求（起因：表單簽核設計器補歷史案件時，兩位簽核人的自動簽核時間
 * 都被寫成「業務日期 23:59:59」——既超出上班時間，兩個人的時間又完全一樣）：
 *   ①自動簽核時間不可以超出正常上班時間（含加班）＝ 08:00 ~ 19:00。
 *   ②文件本身要有上傳/整理的時間，所以實際蓋章一律排在 09:30 ~ 19:00 之間。
 *   ③同一份單據裡，兩個人不可能在完全相同的時間蓋章（一定要錯開）。
 *
 * 用法（呼叫端只要提供「這份單據上一個已寫入的自動簽核時間」，本函式負責算下一個）：
 *   $ts = eg_auto_sign_next_ts($db, $bizDate, $lastTsOfThisDoc, $remainingCount);
 * 時間一律以「業務日期」為準（ai-rules/21：業務日期與精確時間戳分離）；
 * 「今天」要拿 DB 的時間，不可以用 PHP 的 date()（PHP 是 UTC、MySQL 是本地時間，差 8 小時）。
 */

if (!defined('EG_AUTOSIGN_START_MIN')) {
    define('EG_AUTOSIGN_START_MIN', 9 * 60 + 30);   // 09:30 開始蓋章（預留上傳時間）
    define('EG_AUTOSIGN_END_MIN',   19 * 60);       // 19:00 為止（含加班的上班時間上限）
    define('EG_AUTOSIGN_HARD_MIN',  8 * 60);        // 任何自動產生的時間戳都不得早於 08:00
}

if (!function_exists('eg_db_today')) {
    /** 目前的 DB 日期與「今天的第幾秒」；PHP 與 MySQL 時區可能差 8 小時，一律以 DB 為準。 */
    function eg_db_today(PDO $db): array {
        $r = $db->query("SELECT CURDATE() d, TIME_TO_SEC(CURTIME()) s")->fetch(PDO::FETCH_ASSOC);
        return ['date' => (string)$r['d'], 'sec' => (int)$r['s']];
    }
}

if (!function_exists('eg_auto_sign_next_ts')) {
    /**
     * 算出這份單據下一個自動簽核（蓋章）時間，回傳 'Y-m-d H:i:s'。
     *
     * @param string      $bizDate   單據的業務日期（Y-m-d）＝蓋章日期
     * @param string|null $prevTs    這份單據在同一天內上一個已寫入的自動簽核時間；沒有就傳 null
     * @param int         $remaining 這一批還要蓋幾個章（含這一個）。傳了才會依剩餘時間縮小間隔，
     *                               避免章多到把 09:30~19:00 用完後全部擠在 19:00。
     */
    function eg_auto_sign_next_ts(PDO $db, string $bizDate, ?string $prevTs = null, int $remaining = 1): string {
        $startSec = EG_AUTOSIGN_START_MIN * 60;
        $endSec   = EG_AUTOSIGN_END_MIN * 60;

        $prevSec = null;
        if ($prevTs !== null && $prevTs !== '' && substr($prevTs, 0, 10) === $bizDate) {
            $prevSec = (int)substr($prevTs, 11, 2) * 3600 + (int)substr($prevTs, 14, 2) * 60 + (int)substr($prevTs, 17, 2);
        }

        if ($prevSec === null) {
            // 這份單據的第一個章：從 09:30 起算；若業務日期就是今天而現在已經過了 09:30，
            // 就從「現在」起算（不可以蓋出一個比實際送出還早的章）。
            $now  = eg_db_today($db);
            $base = $startSec;
            if ($bizDate === $now['date'] && $now['sec'] > $base) $base = min($now['sec'], $endSec);
            $minStep = 0; $maxStep = 25;                       // 分鐘
        } else {
            $base = $prevSec;
            $minStep = 5; $maxStep = 30;                       // 比照 ai-rules/21：每人錯開 5~30 分鐘
        }

        $roomMin = max(0, intdiv($endSec - $base, 60));
        if ($remaining > 1) $maxStep = max(1, min($maxStep, intdiv($roomMin, $remaining)));
        if ($minStep > $maxStep) $minStep = $maxStep;

        $t = $base + random_int($minStep, $maxStep) * 60;
        if ($t > $endSec) $t = $endSec;
        // 窗口用完時靠秒數維持「同一份單據裡不會有兩個人時間完全相同」（時鐘上仍是 19:00）
        if ($prevSec !== null && $t <= $prevSec) $t = $prevSec + random_int(1, 20);
        $t = min($t, $endSec + 59);
        return $bizDate . ' ' . sprintf('%02d:%02d:%02d', intdiv($t, 3600), intdiv($t % 3600, 60), $t % 60);
    }
}

if (!function_exists('eg_auto_sign_before_ts')) {
    /** 取一個「比某個蓋章時間稍早一點」的時間戳（例如簽核請求的送出時間），最早不早於 08:00。 */
    function eg_auto_sign_before_ts(string $ts, int $minutesBefore = 0): string {
        $date = substr($ts, 0, 10);
        $sec  = (int)substr($ts, 11, 2) * 3600 + (int)substr($ts, 14, 2) * 60 + (int)substr($ts, 17, 2);
        $sec -= ($minutesBefore > 0 ? $minutesBefore : random_int(1, 5)) * 60;
        $sec  = max(EG_AUTOSIGN_HARD_MIN * 60, $sec);
        return $date . ' ' . sprintf('%02d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60);
    }
}
