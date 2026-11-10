<?php
/**
 * 移轉紀錄 ERP 直接匯入：分批發包判定（唯一實作，2026-11-10）
 *
 * 規則（使用者定案）：
 *  - 同 BOM + 同 SN 可以有多筆發包紀錄（分次／分批發出去），每一批都要留下紀錄，不覆蓋。
 *  - 判斷「是不是重複資料」只看：同 BOM、同 SN、同廠商、同「異動日期時間」。
 *    （加工單號分批時會完全相同，所以不能拿來判斷。）
 *  - 只有「預設資料」（開 BOM 時建的空列：狀態 N、沒有發包日、沒有移轉單號）會被第一批蓋掉；
 *    其餘一律新增一列。
 *  - 數量取 ERP 檔 ModalNO 欄，空白才用單位數量（沒有單位數量欄時用生產數量）。
 *
 * 本檔只做「判定」與「組 bom_ing_id」，不寫資料庫；預覽與確認匯入共用同一份判定。
 */

if (!function_exists('transferErpBatchId')) {
    /**
     * 分批新增列的 bom_ing_id：BOM 末 9 碼-SN-發包日(ymd)-異動時間(mdHi)，最長 29 字元（欄位是 varchar(30)）。
     * bom_ing_id 本來就不是唯一鍵，這裡只是讓同一個 BOM+SN 的各批看得出差別；
     * 程式一律用主鍵 bom_ing_fid 定位，不依賴這個值。
     */
    function transferErpBatchId($bom, $bomSn, $moveDate, $changedTs) {
        $ymd = date('ymd', strtotime($moveDate));
        $ct  = strtotime((string)$changedTs);
        $mdh = $ct ? date('mdHi', $ct) : '00000000';
        return substr($bom, -9) . '-' . (int)$bomSn . '-' . $ymd . '-' . $mdh;
    }
}

if (!function_exists('transferErpPlanBatch')) {
    /**
     * 【新規則】逐列判定：回傳與 $rows 同順序（已依發包日、異動時間排序）的陣列，每列多三個欄位
     *   action  : insert（新增一批）| write（蓋掉預設資料列）| skip_dup（重複，跳過）
     *   target_fid : action=write 時要更新的 bom_ing_fid
     *   state   : 新增／覆蓋後的 processing_state（'ing'；比該 BOM+SN 已有的更早的歷史批次記 'E'）
     * 全程在記憶體模擬同一份檔案內先後列的影響（第一批佔用預設列，第二批就是新增），
     * 所以預覽的筆數與實際匯入完全一致。
     */
    function transferErpPlanBatch(PDO $db, array $rows) {
        usort($rows, function ($a, $b) {
            return [$a['bom'], $a['bom_sn'], $a['move_date'], $a['changed_ts']]
               <=> [$b['bom'], $b['bom_sn'], $b['move_date'], $b['changed_ts']];
        });
        $st = $db->prepare("SELECT bom_ing_fid, maker_id_no, sqty, processing_state, outsource_date,
                                   transfer_no, transfer_changed_at, is_consumed, is_schedule_split
                            FROM bom_ing WHERE bom = ? AND bom_sn = ? ORDER BY bom_ing_fid");
        $cache = []; // "bom|sn" => [既有列..., 本次已規劃的列...]
        foreach ($rows as &$r) {
            $key = $r['bom'] . '|' . (int)$r['bom_sn'];
            if (!isset($cache[$key])) {
                $st->execute([$r['bom'], $r['bom_sn']]);
                $cache[$key] = $st->fetchAll(PDO::FETCH_ASSOC);
            }
            $maker  = (string)$r['maker_id_no'];
            $mdate  = substr($r['move_date'], 0, 10);
            $action = null; $target = null; $latest = null;

            foreach ($cache[$key] as $ex) {
                $exMaker = (string)($ex['maker_id_no'] ?? '');
                $exChg   = $ex['transfer_changed_at'] ?? null;
                // 重複：同廠商＋同異動日期時間
                if ($exMaker === $maker && $exChg !== null && $exChg === $r['changed_ts']) { $action = 'skip_dup'; break; }
                // 舊版匯入／VBA 建的列沒有記異動時間：退而求其次，同廠商＋同發包日＋同數量視為已匯入過
                if ($exChg === null && $exMaker === $maker && !empty($ex['outsource_date'])
                    && substr($ex['outsource_date'], 0, 10) === $mdate && (int)$ex['sqty'] === (int)$r['sqty']) { $action = 'skip_dup'; break; }
                if (!empty($ex['outsource_date']) && ($latest === null || $ex['outsource_date'] > $latest)) $latest = $ex['outsource_date'];
            }

            if ($action === null) {
                // 預設資料列：開 BOM 時建立的空列（只有這種會被蓋掉）
                foreach ($cache[$key] as $i => $ex) {
                    $isDefault = in_array((string)($ex['processing_state'] ?? 'N'), ['N', ''], true)
                        && empty($ex['transfer_no']) && empty($ex['outsource_date'])
                        && empty($ex['is_consumed']) && empty($ex['is_schedule_split']);
                    if ($isDefault) { $action = 'write'; $target = (int)$ex['bom_ing_fid']; break; }
                }
            }
            if ($action === null) $action = 'insert';

            // 發包日比該 BOM+SN 現有最新一批還早＝補進來的歷史批次，記為已移轉（E），不當成目前還在外面的批次
            $state = 'ing';
            if ($action === 'insert' && $latest !== null && $r['move_date'] < $latest) $state = 'E';

            $r['action'] = $action; $r['target_fid'] = $target; $r['state'] = $state;

            // 讓同檔案內後面的列看得到這一列的影響
            if ($action === 'write') {
                foreach ($cache[$key] as $i => $ex) {
                    if ((int)$ex['bom_ing_fid'] === $target) {
                        $cache[$key][$i]['maker_id_no'] = $r['maker_id_no']; $cache[$key][$i]['sqty'] = $r['sqty'];
                        $cache[$key][$i]['processing_state'] = 'ing'; $cache[$key][$i]['outsource_date'] = $r['move_date'];
                        $cache[$key][$i]['transfer_no'] = $r['transfer_no']; $cache[$key][$i]['transfer_changed_at'] = $r['changed_ts'];
                    }
                }
            } elseif ($action === 'insert') {
                $cache[$key][] = ['bom_ing_fid' => 0, 'maker_id_no' => $r['maker_id_no'], 'sqty' => $r['sqty'],
                    'processing_state' => $state, 'outsource_date' => $r['move_date'], 'transfer_no' => $r['transfer_no'],
                    'transfer_changed_at' => $r['changed_ts'], 'is_consumed' => 0, 'is_schedule_split' => 0];
            }
        }
        unset($r);
        return $rows;
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 新舊規則分流（2026-11-10）：只有「新的 BOM」套用上面的分批規則，舊 BOM 完全維持原本做法
// （同 BOM+SN 只留一列、後來的移轉覆蓋前一列、前端目前關卡的認定方式不變）。
// 分界＝BOM 編號的民國年月日（B-1151110002 → 1151110），設定存 system_parameters
// （param_group='TRANSFER_ERP'、param_key='batch_from_bom'），沒設定時預設 1151110（2026-11-10 起開的 BOM）。
// 管理員修正本來就是逐列手動，不受這個分界影響。
// ═══════════════════════════════════════════════════════════════════════════
if (!function_exists('transferErpBatchFrom')) {
    function transferErpBatchFrom(PDO $db) {
        try {
            $v = $db->query("SELECT param_value FROM system_parameters WHERE param_group='TRANSFER_ERP' AND param_key='batch_from_bom' LIMIT 1")->fetchColumn();
            if ($v !== false && preg_match('/^\d{7}$/', trim((string)$v))) return (int)trim($v);
        } catch (Throwable $e) {}
        return 1151110;
    }
}
if (!function_exists('transferErpIsNewBom')) {
    function transferErpIsNewBom($bom, $from) {
        return preg_match('/^B-(\d{7})/', (string)$bom, $m) && (int)$m[1] >= $from;
    }
}
if (!function_exists('transferErpClassifyLegacy')) {
    /** 舊規則（原 transferErpClassify，一字未改）：insert | write | skip_dup | skip_stale | skip_manual */
    function transferErpClassifyLegacy($existing, $inTransferNo, $inChangedTs) {
        if (empty($existing)) return 'insert';
        $exNo = $existing['transfer_no'] ?? null;
        if ($inTransferNo !== null && $exNo !== null && $exNo === $inTransferNo) return 'skip_dup';
        $manualAt = $existing['manual_seq_override_at'] ?? null;
        if (!empty($manualAt) && $inChangedTs < $manualAt) return 'skip_manual';
        $exChanged   = $existing['transfer_changed_at'] ?? null;
        $hasProgress = !empty($existing['QC_check']) || !empty($existing['return_date']) || (int)($existing['qc_completed'] ?? 0) === 1;
        if ($hasProgress) {
            return ($exChanged !== null && $inChangedTs >= $exChanged) ? 'write' : 'skip_stale';
        }
        if ($exChanged !== null && $inChangedTs < $exChanged) return 'skip_stale';
        return 'write';
    }
}
if (!function_exists('transferErpPlan')) {
    /**
     * 對外唯一入口（預覽與確認匯入共用）。每列多 action／target_fid／state／legacy 四欄。
     * legacy=true 的列走舊規則：同 BOM+SN 只留異動時間最新一筆、數量用單位數量(sqty_legacy)、
     * action 可能是 insert|write|skip_dup|skip_stale|skip_manual，write 的目標一律是該 BOM+SN 既有那一列。
     */
    function transferErpPlan(PDO $db, array $rows, &$stats = []) {
        $from = transferErpBatchFrom($db);
        $new = []; $oldBy = [];
        $stats['legacy_rows'] = 0; $stats['batch_rows'] = 0; $stats['legacy_dedup'] = 0;
        foreach ($rows as $r) {
            if (transferErpIsNewBom($r['bom'], $from)) { $new[] = $r; continue; }
            $k = $r['bom'] . '|' . (int)$r['bom_sn'];
            if (isset($oldBy[$k])) {
                $stats['legacy_dedup']++;
                if ($r['changed_ts'] <= $oldBy[$k]['changed_ts']) continue;
            }
            $oldBy[$k] = $r;
        }
        $out = [];
        $st = $db->prepare("SELECT bom_ing_fid, transfer_no, transfer_changed_at, QC_check, return_date, qc_completed, manual_seq_override_at
                            FROM bom_ing WHERE bom = ? AND bom_sn = ?");
        foreach ($oldBy as $r) {
            $r['sqty'] = $r['sqty_legacy'] ?? $r['sqty'];
            $st->execute([$r['bom'], $r['bom_sn']]);
            $ex = $st->fetch(PDO::FETCH_ASSOC);
            $r['action'] = transferErpClassifyLegacy($ex, $r['transfer_no'], $r['changed_ts']);
            $r['target_fid'] = $ex ? (int)$ex['bom_ing_fid'] : null;
            $r['state'] = 'ing'; $r['legacy'] = true;
            $out[] = $r; $stats['legacy_rows']++;
        }
        foreach (transferErpPlanBatch($db, $new) as $r) { $r['legacy'] = false; $out[] = $r; $stats['batch_rows']++; }
        return $out;
    }
}
