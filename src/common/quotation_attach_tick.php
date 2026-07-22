<?php
// quotation_attach_tick.php — 報價單暫存/垃圾附件自動清除的順路觸發（做法同 personal_task_tick.php，免工作排程器）。
// 頁面請求時呼叫 eg_quotation_attach_tick($pdo)：距上次清除超過 3600 秒才執行一次，
// 永久刪除已到期的暫存(temp，未存檔逾期)與垃圾(trash，補件被否決逾期)附件（實體檔＋DB列）。
// 工作量極小（通常 0 列，status/expire_at 有索引），故直接內嵌執行、不另開背景程序。
// 半夜無人使用時不清除，到期的檔會等隔天有人開任何頁面時補刪（與其他 tick 一致）。

if (!function_exists('eg_quotation_attach_tick')) {
    function eg_quotation_attach_tick(PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        try {
            $stateFile = __DIR__ . '/quotation_attach_last_check.txt';
            $last = @filemtime($stateFile);
            if ($last && (time() - $last) < 3600) return; // 1 小時內清過就跳過
            @touch($stateFile);                            // 先佔位避免並發重複
            clearstatcache(true, $stateFile);

            // 取儲存根路徑（system_parameters QUOTATION/upload_path，值為 json_encode 字串）
            $stmt = $pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group='QUOTATION' AND param_key='upload_path' LIMIT 1");
            $stmt->execute();
            $pv = $stmt->fetchColumn();
            $base = '';
            if ($pv) { $d = json_decode($pv, true); $base = is_string($d) ? $d : ''; }
            if ($base === '') return;
            $expBase = realpath(rtrim($base, "/\\"));
            if (!$expBase) return;

            $rows = $pdo->query(
                "SELECT id, quote_no, filename, status FROM quotation_attachments
                 WHERE status IN ('temp','trash') AND expire_at IS NOT NULL AND expire_at < NOW()"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) return;

            $del = $pdo->prepare("DELETE FROM quotation_attachments WHERE id=?");
            foreach ($rows as $r) {
                $qn  = preg_replace('/[^a-zA-Z0-9\-_]/', '', (string)$r['quote_no']);
                // temp 在報價單資料夾；trash 在 _att_trash/<quote_no>/
                $dir = ($r['status'] === 'trash')
                     ? rtrim($base, "/\\") . DIRECTORY_SEPARATOR . '_att_trash' . DIRECTORY_SEPARATOR . $qn . DIRECTORY_SEPARATOR
                     : rtrim($base, "/\\") . DIRECTORY_SEPARATOR . $qn . DIRECTORY_SEPARATOR;
                $real = realpath($dir . $r['filename']);
                if ($real && strpos($real, $expBase) === 0 && is_file($real)) { @unlink($real); }
                try { $del->execute([$r['id']]); } catch (\Throwable $e) {}
            }
        } catch (\Throwable $e) {
            error_log('[quot_attach] tick failed: ' . $e->getMessage());
        }
    }
}
