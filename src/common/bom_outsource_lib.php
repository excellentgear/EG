<?php
/**
 * 加工單流水帳共用庫（2026-08-03 建立，Phase 0）——「送出去/回廠/報廢」數量的唯一實作點
 *
 * 背景：`bom_ing`（每個製程一列）過去只有單一 sqty，沒有回廠數量、沒有報廢數量欄位，
 * 也沒有「同一製程送出去好幾次」的記錄能力（客戶案例：廠商整批報廢、生管重新叫料重跑，
 * 或分批補件回來）。這支庫把「每一次送出去」記成流水帳一列，`bom_ing` 現有欄位不動、
 * 仍是「目前這一關的最新快照」，供既有的甘特圖／KPI／vendor_audit 照舊讀取。
 *
 * 唯一性：要送出/回廠/報廢一律呼叫這支庫，不要各頁自己寫 bom_ing_outsource_batch 的 SQL。
 */

if (!function_exists('eg_bom_outsource_ensure_schema')) {
    function eg_bom_outsource_ensure_schema(PDO $db): void {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS bom_ing_outsource_batch (
                batch_id       INT AUTO_INCREMENT PRIMARY KEY,
                bom_ing_fid    INT NOT NULL COMMENT 'FK -> bom_ing.bom_ing_fid，這一趟外包屬於哪個製程列',
                maker_id_no    VARCHAR(11) NULL COMMENT '廠商代號',
                maker_id       VARCHAR(30) NULL COMMENT '廠商名稱快照',
                send_qty       DECIMAL(14,3) NOT NULL COMMENT '送出數量',
                send_date      DATE NULL COMMENT '送出日',
                est_unit_price DECIMAL(12,2) NULL COMMENT '預估單價（開單當下的參考價，非結算金額）',
                return_qty     DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT '累積回廠良品數量',
                scrap_qty      DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT '累積報廢數量',
                actual_amount  DECIMAL(12,2) NULL COMMENT '對帳後的實際結算金額（回填用，不等於單價×數量）',
                status         VARCHAR(10) NOT NULL DEFAULT 'open' COMMENT 'open=尚未結清 closed=已結清(回廠+報廢=送出)',
                batch_no       VARCHAR(30) NULL COMMENT '對外加工單單號，供合併列印/對帳追蹤',
                note           VARCHAR(200) NULL,
                issued_by      INT NULL COMMENT '開立人',
                issued_by_name VARCHAR(50) NULL COMMENT '開立人姓名快照（印在單據上，不須圖章）',
                created_at     DATETIME NULL,
                updated_at     DATETIME NULL,
                INDEX idx_bom_ing_fid (bom_ing_fid),
                INDEX idx_maker (maker_id_no),
                INDEX idx_status (status)
            ) COMMENT='加工單流水帳：每次送出去記一列，累積回廠/報廢數量，供對帳與供應商KPI彙總'");
        } catch (Exception $e) { /* 已存在或無權限，交由呼叫端自行處理 */ }
        try {
            // 人工異動保護：手動改過順序/狀態的列，內網ERP重新匯入時不可覆蓋（見 Transfer_ERP_Commit 的 guard）
            $db->exec("ALTER TABLE bom_ing ADD COLUMN manual_seq_override_at DATETIME NULL COMMENT '人工異動戳記：ERP匯入比對用，比這個時間舊的匯入一律略過' AFTER transfer_changed_at");
        } catch (Exception $e) {}
        try {
            $db->exec("ALTER TABLE bom_ing ADD COLUMN manual_seq_override_by INT NULL COMMENT '人工異動操作人' AFTER manual_seq_override_at");
        } catch (Exception $e) {}
    }
}

if (!function_exists('eg_bom_outsource_stamp_manual')) {
    /**
     * 人工改變這一列的順序/狀態時呼叫（transfer_process／quick_sync_transfer／cancel_transfer 等）。
     * 蓋上「人工異動」戳記，內網ERP重新匯入若比這個時間舊，一律視為過期不覆蓋。
     */
    function eg_bom_outsource_stamp_manual(PDO $db, int $bom_ing_fid, $uid): void {
        try {
            $db->prepare("UPDATE bom_ing SET manual_seq_override_at=NOW(), manual_seq_override_by=? WHERE bom_ing_fid=?")
               ->execute([is_numeric($uid) ? (int)$uid : null, $bom_ing_fid]);
        } catch (Exception $e) { /* 欄位可能還沒建立（尚未呼叫 ensure_schema），忽略即可，下次會補上 */ }
    }
}

if (!function_exists('eg_bom_outsource_open_batch')) {
    /**
     * 送出一批（開一筆流水帳）。多半在 transfer_process／quick_sync_transfer 當下自動呼叫，
     * 之後「快速開立加工單」頁面（Phase 2）也會呼叫這支，數量可能小於 bom_ing.sqty（分批送）。
     */
    function eg_bom_outsource_open_batch(PDO $db, int $bom_ing_fid, ?string $maker_id_no, ?string $maker_id,
                                          float $send_qty, ?string $send_date, ?float $est_unit_price,
                                          ?string $note, $uid, ?string $uid_name = null): int {
        eg_bom_outsource_ensure_schema($db);
        $ins = $db->prepare("INSERT INTO bom_ing_outsource_batch
            (bom_ing_fid, maker_id_no, maker_id, send_qty, send_date, est_unit_price, note, issued_by, issued_by_name, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())");
        $ins->execute([$bom_ing_fid, $maker_id_no, $maker_id, $send_qty, $send_date, $est_unit_price, $note,
                       is_numeric($uid) ? (int)$uid : null, $uid_name]);
        return (int)$db->lastInsertId();
    }
}

if (!function_exists('eg_bom_outsource_latest_open_batch')) {
    function eg_bom_outsource_latest_open_batch(PDO $db, int $bom_ing_fid): ?array {
        eg_bom_outsource_ensure_schema($db);
        $st = $db->prepare("SELECT * FROM bom_ing_outsource_batch WHERE bom_ing_fid=? AND status='open' ORDER BY batch_id DESC LIMIT 1");
        $st->execute([$bom_ing_fid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
}

if (!function_exists('eg_bom_outsource_record_return')) {
    /**
     * 回廠按鈕呼叫：對「目前這筆製程最新一筆未結清的流水帳」累加回廠數量／報廢數量
     * （案例A：廠商分批補件，按多次累加，直到回廠+報廢=送出才自動結清）。
     * 找不到未結清流水帳時（例如舊資料在這支庫上線前就已經在跑），自動用 bom_ing.sqty 現場補開一筆，
     * 不會因為「補資料」擋住使用者操作。
     */
    function eg_bom_outsource_record_return(PDO $db, int $bom_ing_fid, float $return_qty_delta, float $scrap_qty_delta, $uid): array {
        eg_bom_outsource_ensure_schema($db);
        $batch = eg_bom_outsource_latest_open_batch($db, $bom_ing_fid);
        if (!$batch) {
            $bi = $db->prepare("SELECT sqty, maker_id_no, maker_id, outsource_date FROM bom_ing WHERE bom_ing_fid=?");
            $bi->execute([$bom_ing_fid]);
            $row = $bi->fetch(PDO::FETCH_ASSOC) ?: [];
            $sendDate = !empty($row['outsource_date']) ? substr($row['outsource_date'], 0, 10) : date('Y-m-d');
            $newId = eg_bom_outsource_open_batch($db, $bom_ing_fid, $row['maker_id_no'] ?? null, $row['maker_id'] ?? null,
                                                  (float)($row['sqty'] ?? 0), $sendDate, null, '（既有資料現場補開）', $uid);
            $batch = eg_bom_outsource_latest_open_batch($db, $bom_ing_fid);
        }
        $newReturn = (float)$batch['return_qty'] + $return_qty_delta;
        $newScrap  = (float)$batch['scrap_qty']  + $scrap_qty_delta;
        $closed = ($newReturn + $newScrap) >= (float)$batch['send_qty'];
        $upd = $db->prepare("UPDATE bom_ing_outsource_batch SET return_qty=?, scrap_qty=?, status=?, updated_at=NOW() WHERE batch_id=?");
        $upd->execute([$newReturn, $newScrap, $closed ? 'closed' : 'open', $batch['batch_id']]);
        if ($closed) {
            // 結清時鏡射回 bom_ing.return_date，既有甘特圖/KPI 讀 bom_ing 照舊能用
            try { $db->prepare("UPDATE bom_ing SET return_date=IFNULL(return_date,NOW()) WHERE bom_ing_fid=?")->execute([$bom_ing_fid]); }
            catch (Exception $e) {}
        }
        return ['batch_id'=>$batch['batch_id'], 'return_qty'=>$newReturn, 'scrap_qty'=>$newScrap,
                'send_qty'=>(float)$batch['send_qty'], 'closed'=>$closed,
                'remaining_good_qty'=>max(0, (float)$batch['send_qty'] - $newScrap - $newReturn)];
    }
}

if (!function_exists('eg_bom_outsource_set_scrap')) {
    /**
     * 「更新」按鈕的報廢數量欄位呼叫：直接覆蓋（非累加）目前這筆流水帳的報廢數量，供手動修正用。
     * 用於數量填錯時的訂正，不是回廠流程的正常路徑。
     */
    function eg_bom_outsource_set_scrap(PDO $db, int $bom_ing_fid, float $scrap_qty_abs, $uid): ?array {
        eg_bom_outsource_ensure_schema($db);
        $batch = eg_bom_outsource_latest_open_batch($db, $bom_ing_fid);
        if (!$batch) {
            $st = $db->prepare("SELECT * FROM bom_ing_outsource_batch WHERE bom_ing_fid=? ORDER BY batch_id DESC LIMIT 1");
            $st->execute([$bom_ing_fid]);
            $batch = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$batch) return null;
        $closed = ($batch['return_qty'] + $scrap_qty_abs) >= (float)$batch['send_qty'];
        $db->prepare("UPDATE bom_ing_outsource_batch SET scrap_qty=?, status=?, updated_at=NOW() WHERE batch_id=?")
           ->execute([$scrap_qty_abs, $closed ? 'closed' : 'open', $batch['batch_id']]);
        return ['batch_id'=>$batch['batch_id'], 'scrap_qty'=>$scrap_qty_abs];
    }
}

if (!function_exists('eg_bom_outsource_remaining_good_qty')) {
    /** 這個製程目前累積下來，扣掉報廢後剩餘的良品數量（開單/回廠當下自動算給使用者看）。 */
    function eg_bom_outsource_remaining_good_qty(PDO $db, int $bom_ing_fid): float {
        eg_bom_outsource_ensure_schema($db);
        $st = $db->prepare("SELECT COALESCE(SUM(send_qty),0) s, COALESCE(SUM(scrap_qty),0) c FROM bom_ing_outsource_batch WHERE bom_ing_fid=?");
        $st->execute([$bom_ing_fid]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['s'=>0,'c'=>0];
        return max(0, (float)$r['s'] - (float)$r['c']);
    }
}

if (!function_exists('eg_bom_outsource_list')) {
    /** 這個製程列的完整流水帳（畫面顯示用：送了幾次、每次多少、回廠/報廢各多少）。 */
    function eg_bom_outsource_list(PDO $db, int $bom_ing_fid): array {
        eg_bom_outsource_ensure_schema($db);
        $st = $db->prepare("SELECT * FROM bom_ing_outsource_batch WHERE bom_ing_fid=? ORDER BY batch_id");
        $st->execute([$bom_ing_fid]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
