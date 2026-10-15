<?php
// as_doc_owner_tick.php — AS 文件部門負責人設定缺口的提醒（順路觸發，免工作排程器）。
//
// 為什麼要有這支（使用者 2026-10-15）：
//   「此人員若離職時務必提醒需要設定新 AS 文件部門負責人，且暫時由代理人代理；
//     若部門負責人或代理人有調離該負責課室或是文件負責部門，則需要提醒要重新設定。」
//   離職與調職是在**別的頁面**（員工管理、組織設定）發生的，那兩頁不會知道 AS 文件這邊
//   還掛著這個人。逐頁去掛連動＝每新增一個用到人員的模組都要回去改那兩頁（遲早漏掉），
//   所以改成這裡定期盤點目前的設定狀態——人怎麼離開的不重要，「現在設定對不對」才重要。
//
// 分工：
//   - 畫面上的紅色橫幅由 as_document_management.php 每次載入即時查（ado_problems()），
//     所以管理員一進那一頁就看得到，不必等這支跑；
//   - 這支負責「主動通知」（站內通知＋Web Push＋Telegram），讓沒有特別去開那一頁的人也知道。
//
// 同一組問題 ADO_NOTIFY_MIN_DAYS（7）天內只發一次，問題內容變了才會再發一輪
// （指紋比對見 ado_problem_sig）——每天吵同一件事的通知沒人會看。

if (!function_exists('eg_as_doc_owner_tick')) {
    function eg_as_doc_owner_tick(PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $stateFile = __DIR__ . '/as_doc_owner_last_check.txt';
            $last = @filemtime($stateFile);
            if ($last && (time() - $last) < 3600) return;   // 1 小時內跑過就跳過
            @touch($stateFile);                              // 先佔位避免並發重複
            clearstatcache(true, $stateFile);

            require_once __DIR__ . '/as_doc_owner_lib.php';
            $n = ado_notify($pdo);
            if ($n > 0) error_log('[as_doc_owner] 已通知 ' . $n . ' 人');
        } catch (\Throwable $e) {
            error_log('[as_doc_owner] tick failed: ' . $e->getMessage());
        }
    }
}
