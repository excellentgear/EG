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

if (!function_exists('eg_oready_or_toggle')) {
    /** 讀一個 BOM_SETTING 群組的開/關型 system_parameters（{"enabled":bool}，預設開啟）。
     *  唯一讀取點——OreadyReply_ForPm_BaseOfTime.php 主頁面與 _ajax.php 後端都呼叫這支，
     *  兩邊各自寫一次解析遲早對不起來（鐵律4）。目前只給 oready_return_qty_settable／
     *  oready_return_qty_adjustable 兩個鍵用，查不到或壞掉一律視為開啟（不影響任何人）。 */
    function eg_oready_or_toggle(PDO $db, string $key): bool {
        try {
            $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group='BOM_SETTING' AND param_key=? LIMIT 1");
            $st->execute([$key]);
            $raw = $st->fetchColumn();
            if ($raw === false || $raw === null || $raw === '') return true;
            $j = json_decode((string)$raw, true);
            return is_array($j) ? !empty($j['enabled']) : true;
        } catch (Throwable $e) { return true; }
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

/* ─────────────────────────────────────────────────────────────
   2026-10-07 使用者交辦：外包廠商回廠回報的報廢數量要影響 QC 待驗清單的「良品上限/待驗數」
   （與既有 qab_bom_scrap_qty()「確認報廐」是不同層級——那是異常單結案配發報廐單號才算數，
   這裡是回廠當下、尚未經品管判定就先影響顯示，供 QC 提早知道「這批少了幾件不用等了」，
   並標出是哪家廠商回報的，方便品管需要時直接去問原因）。
   比照 qab_bom_scrap_rows()／qab_bom_scrap_qty() 的站別（$uptoBomSn）篩選寫法，唯一實作點。
   ───────────────────────────────────────────────────────────── */
if (!function_exists('eg_bom_outsource_scrap_rows')) {
    /** 多張 BOM 一次查：每一筆外包流水帳（跨所有狀態，open/closed 都算，報廢已經發生不等結清）
     *  的報廐數量＋站別＋廠商，供 eg_bom_outsource_scrap_qty()／_vendors_text() 共用底層資料。 */
    function eg_bom_outsource_scrap_rows(PDO $db, array $bomNos): array {
        eg_bom_outsource_ensure_schema($db);
        $bomNos = array_values(array_unique(array_filter(array_map('trim', $bomNos), function ($v) { return $v !== ''; })));
        if (!$bomNos) return [];
        $in = implode(',', array_fill(0, count($bomNos), '?'));
        $st = $db->prepare("SELECT bi.bom AS bom_no, bi.bom_sn, b.scrap_qty, b.maker_id, b.maker_id_no
                             FROM bom_ing_outsource_batch b
                             JOIN bom_ing bi ON bi.bom_ing_fid = b.bom_ing_fid
                             WHERE bi.bom IN ($in) AND b.scrap_qty > 0");
        $st->execute($bomNos);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['bom_no']][] = [
                'qty' => (float)$r['scrap_qty'], 'origin_sn' => $r['bom_sn'] !== null ? (int)$r['bom_sn'] : null,
                'maker_id' => (string)($r['maker_id'] ?? ''), 'maker_id_no' => (string)($r['maker_id_no'] ?? ''),
            ];
        }
        return $out;
    }
}

if (!function_exists('eg_bom_outsource_scrap_qty')) {
    /** 單一 BOM（可指定 $uptoBomSn 只算這一站(含)之前）的外包回廠報廢總量；查不到回 0。
     *  站別篩選語意與 qab_bom_scrap_qty() 一致：$uptoBomSn=null 整張BOM、給值只算該站以前。 */
    function eg_bom_outsource_scrap_qty(PDO $db, string $bom, ?int $uptoBomSn = null): float {
        $bom = trim($bom);
        if ($bom === '') return 0.0;
        $rows = eg_bom_outsource_scrap_rows($db, [$bom])[$bom] ?? [];
        $sum = 0.0;
        foreach ($rows as $r) {
            if ($uptoBomSn !== null && $r['origin_sn'] !== null && $r['origin_sn'] > $uptoBomSn) continue;
            $sum += $r['qty'];
        }
        return $sum;
    }
}

if (!function_exists('eg_bom_outsource_scrap_vendors_text')) {
    /** 人看得懂的一句話，列出是哪幾家廠商回報了多少報廐（QC待驗畫面提示用，方便找人問原因）。
     *  例：「天文 4件、GG廠 2件」；沒有外包報廐時回傳空字串。 */
    function eg_bom_outsource_scrap_vendors_text(PDO $db, string $bom, ?int $uptoBomSn = null): string {
        $bom = trim($bom);
        if ($bom === '') return '';
        $rows = eg_bom_outsource_scrap_rows($db, [$bom])[$bom] ?? [];
        $byVendor = [];
        foreach ($rows as $r) {
            if ($uptoBomSn !== null && $r['origin_sn'] !== null && $r['origin_sn'] > $uptoBomSn) continue;
            $name = $r['maker_id'] !== '' ? $r['maker_id'] : '（未登記廠商）';
            $byVendor[$name] = ($byVendor[$name] ?? 0) + $r['qty'];
        }
        if (!$byVendor) return '';
        $parts = [];
        foreach ($byVendor as $name => $qty) {
            $qtyTxt = (floor($qty) == $qty) ? (string)(int)$qty : (string)$qty;
            $parts[] = $name . ' ' . $qtyTxt . '件';
        }
        return implode('、', $parts);
    }
}

if (!function_exists('eg_bom_outsource_prev_station_qty')) {
    /**
     * 回廠跳窗「本次回廠數量」的智慧預設值（2026-10-07 使用者交辦）：
     *  ①上一關有報工紀錄 → 報工後良品數（SUM(produced_qty) 扣掉 pm_process_daily_ng 的 SUM(ng_qty)）；
     *  ②上一關是QC線上檢驗 → 依報廢/NG數量扣除後的數量（QC_ok_sqty+QC_aod_sqty，允收與特採皆算
     *    「繼續往下走」，QQ異常與ng驗退才扣掉；優先於①，因為線上檢驗通常晚於報工、是較終局的數字）；
     *  ③都沒有 → 退回上一關（或自己，若是第一關）登記的 sqty 當保守預設。
     * 「上一關」依 bom_sn 排序找最接近的前一筆（可能同 bom_sn 有好幾筆分批/分廠商，全部加總）——
     * 寫法與 kpi_scheme_lib.php 既有的「前一站」查詢同一個 idiom（MAX-子查詢，非 bom_sn-1）。
     * 回傳 ['qty'=>float, 'source'=>'qc'|'pm'|'fallback', 'label'=>供畫面顯示的來源說明文字]。
     */
    function eg_bom_outsource_prev_station_qty(PDO $db, string $bom, int $currentBomSn): array {
        $bom = trim($bom);
        if ($bom === '') return ['qty' => 0.0, 'source' => 'fallback', 'label' => '查無資料'];

        $prevSnSt = $db->prepare("SELECT MAX(bom_sn) FROM bom_ing WHERE bom=? AND bom_sn<?");
        $prevSnSt->execute([$bom, $currentBomSn]);
        $prevSn = $prevSnSt->fetchColumn();

        $targetSn = ($prevSn !== null && $prevSn !== false) ? (int)$prevSn : $currentBomSn; // 第一關沒有上一關，退回自己這站

        // ① QC 線上/傳統檢驗（優先）：允收+特採視為繼續往下走，異常+驗退扣掉
        $qcSt = $db->prepare("SELECT COALESCE(SUM(QC_ok_sqty),0)+COALESCE(SUM(QC_aod_sqty),0)
                                      - COALESCE(SUM(QC_QQ_sqty),0) - COALESCE(SUM(QC_ng_sqty),0) AS net,
                                     COUNT(*) AS cnt
                               FROM QC_check qc JOIN bom_ing bi ON bi.bom_ing_fid=qc.bom_ing_fid_ref
                               WHERE bi.bom=? AND bi.bom_sn=?");
        $qcSt->execute([$bom, $targetSn]);
        $qcRow = $qcSt->fetch(PDO::FETCH_ASSOC);
        if ($qcRow && (int)$qcRow['cnt'] > 0) {
            return ['qty' => max(0.0, (float)$qcRow['net']), 'source' => 'qc', 'label' => '依上一關QC檢驗結果帶入（允收+特採−異常−驗退）'];
        }

        // ② 報工紀錄：良品數＝SUM(produced_qty) 扣掉同站 pm_process_daily_ng 的 SUM(ng_qty)
        $pmSt = $db->prepare("SELECT COALESCE(SUM(pdr.produced_qty),0) AS good,
                                      COALESCE((SELECT SUM(ng.ng_qty) FROM pm_process_daily_ng ng
                                                WHERE ng.report_id IN (SELECT report_id FROM pm_process_daily_report WHERE bom_ing_fid IN (
                                                    SELECT bom_ing_fid FROM bom_ing WHERE bom=? AND bom_sn=?))), 0) AS ng,
                                      COUNT(*) AS cnt
                               FROM pm_process_daily_report pdr
                               JOIN bom_ing bi ON bi.bom_ing_fid=pdr.bom_ing_fid
                               WHERE bi.bom=? AND bi.bom_sn=?");
        $pmSt->execute([$bom, $targetSn, $bom, $targetSn]);
        $pmRow = $pmSt->fetch(PDO::FETCH_ASSOC);
        if ($pmRow && (int)$pmRow['cnt'] > 0) {
            $qty = max(0.0, (float)$pmRow['good'] - (float)$pmRow['ng']);
            return ['qty' => $qty, 'source' => 'pm', 'label' => '依上一關報工後良品數帶入'];
        }

        // ③ 都沒有：退回該站登記的 sqty（第一關或上一關還沒有任何紀錄時的保守預設）
        $sqSt = $db->prepare("SELECT COALESCE(SUM(sqty),0) FROM bom_ing WHERE bom=? AND bom_sn=?");
        $sqSt->execute([$bom, $targetSn]);
        $sqty = (float)$sqSt->fetchColumn();
        return ['qty' => $sqty, 'source' => 'fallback', 'label' => ($prevSn !== null && $prevSn !== false) ? '上一關尚無報工/檢驗紀錄，帶入發包量' : '第一關，帶入發包量'];
    }
}
