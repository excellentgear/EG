<?php
// eng_change_remind_tick.php — 工程變更申請單「送出後 5 個工作天仍未完成」提醒申請人（順路觸發，免工作排程器）。
// 申請人平常只收「結案（核准結果）」與「被退回」兩種通知，其餘各關進度不打擾；
// 只有單子卡太久（距送出或距上次提醒滿 5 個工作天）才提醒一次，之後每滿 5 個工作天再提醒一次。
// 一小時最多檢查一次，掃描量很小（只掃進行中的單據），直接在請求內跑。

if (!function_exists('eg_ec_remind_tick')) {

    function eg_ec_remind_tick(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $stateFile = __DIR__ . '/eng_change_remind_last_check.txt';
            $last = @filemtime($stateFile);
            if ($last && (time() - $last) < 3600) return;
            @touch($stateFile);
            clearstatcache(true, $stateFile);

            require_once __DIR__ . '/DBConnection.php';
            require_once __DIR__ . '/eng_change_lib.php';
            require_once __DIR__ . '/leave_lib.php';
            $db = (new DBConnection())->getPDO();
            ec_ensure_schema($db);
            $today = (string)$db->query("SELECT CURDATE()")->fetchColumn();
            $rows = $db->query("SELECT * FROM eng_change
                                 WHERE status NOT IN ('DRAFT','CLOSED','REJECTED') AND submitted_at IS NOT NULL")
                       ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $from = substr((string)(($r['remind_sent_at'] ?? '') > (string)$r['submitted_at'] ? $r['remind_sent_at'] : $r['submitted_at']), 0, 10);
                // 數「from 之後（不含當天）到今天（含）」的工作天
                $n = 0;
                for ($t = strtotime($from . ' +1 day'); date('Y-m-d', $t) <= $today && $n < 60; $t += 86400)
                    if (eg_leave_is_workday($db, date('Y-m-d', $t))) $n++;
                if ($n < 5) continue;
                $stage = EC_STAGES[(string)$r['status']]['label'] ?? (string)$r['status'];
                ec_notify_result($db, $r, (int)$r['applicant_id'],
                    ec_notify_body($r) . "\n\n這張工程變更申請單已經過了 " . 5 . " 個工作天仍未完成，目前停在「" . $stage . "」關卡，可向該關簽核人確認。", 0);
                $db->prepare("UPDATE eng_change SET remind_sent_at=NOW() WHERE ec_id=?")->execute([(int)$r['ec_id']]);
            }
        } catch (\Throwable $e) {
            error_log('[eng_change_remind] tick failed: ' . $e->getMessage());
        }
    }
}

// 自動簽認補簽（使用者要求 2026-10-21）：自動簽認排定的時間（auto_sign_at）到了就補簽。
// 兩分鐘最多檢查一次；只掃 auto_sign_at 已到的單據，量極小。
if (!function_exists('eg_ec_autosign_tick')) {
    function eg_ec_autosign_tick(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $stateFile = __DIR__ . '/eng_change_autosign_last_check.txt';
            $last = @filemtime($stateFile);
            if ($last && (time() - $last) < 120) return;
            @touch($stateFile);
            clearstatcache(true, $stateFile);

            require_once __DIR__ . '/DBConnection.php';
            require_once __DIR__ . '/eng_change_lib.php';
            $db = (new DBConnection())->getPDO();
            ec_ensure_schema($db);
            $ids = $db->query("SELECT ec_id FROM eng_change WHERE auto_sign_at IS NOT NULL AND auto_sign_at <= NOW()
                                  AND status NOT IN ('DRAFT','CLOSED','REJECTED')")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) ec_auto_sign_try($db, (int)$id);
        } catch (\Throwable $e) {
            error_log('[eng_change_autosign] tick failed: ' . $e->getMessage());
        }
    }
}
