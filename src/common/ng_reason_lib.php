<?php
/**
 * 報工 NG 原因（ng_txt）管理（2026-11-03）——唯一實作。
 *
 * 報工紀錄（pm_process_daily_ng.ng_id）、回報紀錄（reply／reply_all.ng_id）一律存「原因 id」不存文字，
 * 所以改名、停用、排序都不會讓舊資料失聯。管理員可在「報工排程管理 V2」→ 更多 → NG原因設定 維護：
 *   - 增減原因、改名、停用、調整順序；
 *   - 每個原因可設「報工自動開立異常單時，責任單位是否帶入報工人員與其部門」（auto_resp）——
 *     某些異常（例如來料不良、機台異常）跟報工人員無關，就不帶入；
 *   - 刪除前一律先檢查有沒有被使用；用過的必須指定「移轉到另一個現有原因」，移轉＋刪除同一個交易，
 *     所有引用它的資料（見 ng_reason_ref_tables()）一併改成新原因的 id，不會有資料缺漏。
 */

if (!function_exists('ng_reason_ref_tables')) {
    /** 引用 ng_txt.ng_id 的資料表（新增引用它的表時只要在這裡加一列，使用次數與移轉就自動涵蓋） */
    function ng_reason_ref_tables(): array
    {
        return [
            ['table' => 'pm_process_daily_ng', 'label' => '報工NG紀錄'],
            ['table' => 'reply_all',           'label' => '回報紀錄'],
            ['table' => 'reply',               'label' => '回報紀錄(舊)'],
        ];
    }
}

if (!function_exists('ng_reason_ensure_schema')) {
    /** 補欄位（先 SHOW COLUMNS 再逐欄 ALTER；本站 MySQL 沒有 ADD COLUMN IF NOT EXISTS）。DDL 不可在交易內呼叫。 */
    function ng_reason_ensure_schema(PDO $db): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if ($db->inTransaction()) return;
        $have = [];
        foreach ($db->query("SHOW COLUMNS FROM ng_txt")->fetchAll(PDO::FETCH_ASSOC) as $c) $have[$c['Field']] = true;
        $add = [
            'is_active'  => "ADD COLUMN is_active TINYINT NOT NULL DEFAULT 1 COMMENT '1=啟用(報工下拉可選)，0=停用(舊資料仍顯示)'",
            'sort_order' => "ADD COLUMN sort_order INT NOT NULL DEFAULT 0 COMMENT '報工下拉順序'",
            'auto_resp'  => "ADD COLUMN auto_resp TINYINT NOT NULL DEFAULT 1 COMMENT '報工自動開立異常單時，責任單位是否帶入報工人員與其部門'",
        ];
        foreach ($add as $col => $ddl) {
            if (!isset($have[$col])) {
                try { $db->exec("ALTER TABLE ng_txt $ddl"); } catch (Throwable $e) { error_log('ng_txt add ' . $col . ': ' . $e->getMessage()); }
            }
        }
        // 第一次：用既有順序（ng_id）當排序，避免全部 0 時順序不穩定
        try { $db->exec("UPDATE ng_txt SET sort_order = ng_id WHERE sort_order = 0"); } catch (Throwable $e) {}
    }
}

if (!function_exists('ng_reason_list')) {
    /** 清單（含使用次數）。$onlyActive=true 給報工下拉用。 */
    function ng_reason_list(PDO $db, bool $onlyActive = false, bool $withUsage = false): array
    {
        ng_reason_ensure_schema($db);
        $rows = $db->query("SELECT ng_id, ng_txt, is_active, sort_order, auto_resp FROM ng_txt "
                         . ($onlyActive ? "WHERE is_active=1 " : "") . "ORDER BY sort_order, ng_id")->fetchAll(PDO::FETCH_ASSOC);
        if ($withUsage) {
            foreach ($rows as &$r) $r['used'] = ng_reason_usage($db, (int)$r['ng_id']);
            unset($r);
        }
        return $rows;
    }
}

if (!function_exists('ng_reason_usage')) {
    /** 被引用的筆數（各引用表合計）與明細 */
    function ng_reason_usage(PDO $db, int $ngId, bool $detail = false)
    {
        $total = 0; $parts = [];
        foreach (ng_reason_ref_tables() as $t) {
            try {
                $s = $db->prepare("SELECT COUNT(*) FROM `{$t['table']}` WHERE ng_id = ?");
                $s->execute([$ngId]);
                $n = (int)$s->fetchColumn();
            } catch (Throwable $e) { $n = 0; }
            if ($n > 0) $parts[] = ['table' => $t['table'], 'label' => $t['label'], 'n' => $n];
            $total += $n;
        }
        return $detail ? ['total' => $total, 'parts' => $parts] : $total;
    }
}

if (!function_exists('ng_reason_save')) {
    /** 新增（$id=0）或修改。回傳 ng_id。 */
    function ng_reason_save(PDO $db, int $id, string $name, int $active, int $autoResp, ?int $uid): int
    {
        ng_reason_ensure_schema($db);
        $name = trim($name);
        if ($name === '') throw new Exception('原因名稱不可為空');
        if (mb_strlen($name) > 30) throw new Exception('原因名稱最多 30 字');
        $dup = $db->prepare("SELECT ng_id FROM ng_txt WHERE ng_txt = ? AND ng_id <> ? LIMIT 1");
        $dup->execute([$name, $id]);
        if ($dup->fetchColumn()) throw new Exception('已有同名的 NG 原因：' . $name);
        $active = $active ? 1 : 0; $autoResp = $autoResp ? 1 : 0;
        if ($id > 0) {
            $chk = $db->prepare("SELECT COUNT(*) FROM ng_txt WHERE ng_id=?"); $chk->execute([$id]);
            if (!(int)$chk->fetchColumn()) throw new Exception('找不到這個 NG 原因');
            if (!$active) {   // 至少要留一個啟用中的原因，否則報工下拉會整個空掉
                $o = $db->prepare("SELECT COUNT(*) FROM ng_txt WHERE is_active=1 AND ng_id<>?"); $o->execute([$id]);
                if (!(int)$o->fetchColumn()) throw new Exception('至少要保留一個啟用中的 NG 原因');
            }
            $db->prepare("UPDATE ng_txt SET ng_txt=?, is_active=?, auto_resp=?, Modified_By=?, Modified_At=NOW() WHERE ng_id=?")
               ->execute([$name, $active, $autoResp, $uid, $id]);
            return $id;
        }
        $max = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM ng_txt")->fetchColumn();
        $db->prepare("INSERT INTO ng_txt (ng_txt, Created_By, is_active, sort_order, auto_resp) VALUES (?,?,?,?,?)")
           ->execute([$name, $uid, $active, $max + 1, $autoResp]);
        return (int)$db->lastInsertId();
    }
}

if (!function_exists('ng_reason_move')) {
    /** 上移／下移（與相鄰一筆交換 sort_order） */
    function ng_reason_move(PDO $db, int $id, string $dir): void
    {
        $rows = $db->query("SELECT ng_id FROM ng_txt ORDER BY sort_order, ng_id")->fetchAll(PDO::FETCH_COLUMN);
        $i = array_search((string)$id, array_map('strval', $rows), true);
        if ($i === false) throw new Exception('找不到這個 NG 原因');
        $j = $dir === 'up' ? $i - 1 : $i + 1;
        if ($j < 0 || $j >= count($rows)) return;
        [$rows[$i], $rows[$j]] = [$rows[$j], $rows[$i]];
        $up = $db->prepare("UPDATE ng_txt SET sort_order=? WHERE ng_id=?");
        foreach ($rows as $k => $nid) $up->execute([$k + 1, (int)$nid]);   // 一律重新編號，避免多筆同序號
    }
}

if (!function_exists('ng_reason_delete')) {
    /**
     * 刪除。被使用過的一律要求 $moveTo（另一個現有原因）：所有引用表的 ng_id 一併改成新的，
     * 移轉與刪除同一個交易。回傳 ['moved'=>被移轉的筆數, 'parts'=>明細]。
     */
    function ng_reason_delete(PDO $db, int $id, ?int $moveTo): array
    {
        ng_reason_ensure_schema($db);
        $chk = $db->prepare("SELECT ng_txt FROM ng_txt WHERE ng_id=?"); $chk->execute([$id]);
        $name = $chk->fetchColumn();
        if ($name === false) throw new Exception('找不到這個 NG 原因（可能已被刪除，請重新整理）');
        $left = $db->prepare("SELECT COUNT(*) FROM ng_txt WHERE ng_id<>?"); $left->execute([$id]);
        if (!(int)$left->fetchColumn()) throw new Exception('至少要保留一個 NG 原因');

        $use = ng_reason_usage($db, $id, true);
        if ($use['total'] > 0) {
            if (!$moveTo) throw new Exception('「' . $name . '」已被使用過（' . $use['total'] . ' 筆），請指定要移轉到哪一個現有的 NG 原因才能刪除');
            if ($moveTo === $id) throw new Exception('不能移轉到自己');
            $t = $db->prepare("SELECT COUNT(*) FROM ng_txt WHERE ng_id=?"); $t->execute([$moveTo]);
            if (!(int)$t->fetchColumn()) throw new Exception('移轉目標不存在');
        }
        $db->beginTransaction();
        try {
            if ($use['total'] > 0) {
                foreach (ng_reason_ref_tables() as $tb) {
                    try { $db->prepare("UPDATE `{$tb['table']}` SET ng_id=? WHERE ng_id=?")->execute([$moveTo, $id]); }
                    catch (PDOException $e) { if ($e->getCode() !== '42S02') throw $e; }   // 表不存在才略過
                }
            }
            $db->prepare("DELETE FROM ng_txt WHERE ng_id=?")->execute([$id]);
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); throw $e; }
        return ['moved' => $use['total'], 'parts' => $use['parts'], 'name' => (string)$name];
    }
}

if (!function_exists('ng_reason_report_needs_resp')) {
    /**
     * 這筆報工，自動開立異常單時要不要把報工人員帶進責任單位？
     * 只要這筆報工有任何一個 NG 原因設為「帶入」（或原因已不存在／沒填原因）就帶；
     * 全部 NG 原因都設為「不帶入」才不帶；沒有 NG 明細時維持帶入（原本行為）。
     */
    function ng_reason_report_needs_resp(PDO $db, int $reportId): bool
    {
        ng_reason_ensure_schema($db);
        $s = $db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(t.ng_id IS NULL OR t.auto_resp=1),0) AS yes
                           FROM pm_process_daily_ng g LEFT JOIN ng_txt t ON t.ng_id=g.ng_id
                           WHERE g.report_id=? AND g.ng_qty>0");
        $s->execute([$reportId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return (int)$r['n'] === 0 || (int)$r['yes'] > 0;
    }
}
