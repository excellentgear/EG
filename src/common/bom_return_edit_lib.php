<?php
// 修改／刪除「移送批次」與「回廠紀錄」的唯一實作（2026-11-06 使用者交辦）。
// 使用端：views/pm/OreadyReply_ForPm_BaseOfTime_ajax.php（ber_* 動作）。資料表同 bom_outsource_lib.php。
// 規則：
//  - 角色功能碼 oready_edit_return（或本頁管理員）；唯讀角色一律不可；後端每個動作都再驗一次
//  - 修改移送數量／回廠數量：這一站累計回廠良品數不可低於「已報工／QC 已通報」的數量，
//    也不可讓回廠＋報廢＋取回超過該批移送數量，移送總量不可超過製程發單數
//  - 刪除：只有「當天建立」的回廠紀錄／移送批次可刪（誤送、誤按回廠）
//  - 每次動作都寫 bom_ing_event 留紀錄（誰、改了什麼）
require_once __DIR__ . '/role_features_helper.php';
require_once __DIR__ . '/bom_outsource_lib.php';

if (!function_exists('ber_is_admin')) {
    function ber_is_admin(PDO $db, int $uid): bool {
        return oready_resolve_is_admin($db, $uid, '/EGsystem/views/pm/OreadyReply_ForPm_BaseOfTime.php');
    }
}

if (!function_exists('ber_can_edit')) {
    function ber_can_edit(PDO $db, int $uid): bool {
        if ($uid <= 0) return false;
        $f = rf_load_user_features($db, $uid);
        // 唯讀要角色明確勾選才算（不可用 rf_has_feature，它把 'all' 當成符合一切）
        if (in_array('oready_readonly', $f, true)) return false;
        if (ber_is_admin($db, $uid)) return true;
        return rf_has_feature($f, 'oready_edit_return');
    }
}

if (!function_exists('ber_reported')) {
    /** 這一站已經通報的數量：QC 檢驗（允收+特採+異常+驗退）與報工（良品+NG），取較大者當回廠數量下限。 */
    function ber_reported(PDO $db, int $fid): array {
        $q = $db->prepare("SELECT COALESCE(SUM(QC_ok_sqty),0)+COALESCE(SUM(QC_aod_sqty),0)+COALESCE(SUM(QC_QQ_sqty),0)+COALESCE(SUM(QC_ng_sqty),0) FROM QC_check WHERE bom_ing_fid_ref=?");
        $q->execute([(string)$fid]);
        $qc = (float)$q->fetchColumn();
        $p = $db->prepare("SELECT COALESCE(SUM(produced_qty),0) FROM pm_process_daily_report WHERE bom_ing_fid=?");
        $p->execute([$fid]);
        $pm = (float)$p->fetchColumn();
        $n = $db->prepare("SELECT COALESCE(SUM(ng.ng_qty),0) FROM pm_process_daily_ng ng JOIN pm_process_daily_report r ON r.report_id=ng.report_id WHERE r.bom_ing_fid=?");
        $n->execute([$fid]);
        $ng = (float)$n->fetchColumn();
        return ['qc' => $qc, 'pm' => $pm + $ng, 'need' => max($qc, $pm + $ng)];
    }
}

if (!function_exists('ber_list')) {
    function ber_list(PDO $db, int $fid): array {
        eg_bom_outsource_ensure_schema($db);
        $bi = $db->prepare("SELECT bom, bom_sn, process_no, sqty, processing_state FROM bom_ing WHERE bom_ing_fid=?");
        $bi->execute([$fid]);
        $proc = $bi->fetch(PDO::FETCH_ASSOC);
        if (!$proc) throw new Exception('找不到這個製程');
        $bs = $db->prepare("SELECT *, (DATE(created_at)=CURDATE()) AS is_today FROM bom_ing_outsource_batch WHERE bom_ing_fid=? ORDER BY batch_id");
        $bs->execute([$fid]);
        $batches = $bs->fetchAll(PDO::FETCH_ASSOC);
        $rs = $db->prepare("SELECT r.*, (DATE(r.created_at)=CURDATE()) AS is_today, DATE_FORMAT(r.return_date,'%Y-%m-%d') AS rd, u.user_cname AS by_name
                              FROM bom_ing_outsource_return r LEFT JOIN user u ON u.id=r.created_by
                             WHERE r.bom_ing_fid=? ORDER BY r.return_id");
        $rs->execute([$fid]);
        $by = [];
        foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) $by[(int)$r['batch_id']][] = $r;
        $out = [];
        $retTotal = 0.0;
        foreach ($batches as $b) {
            $retTotal += (float)$b['return_qty'];
            $out[] = ['batch_id' => (int)$b['batch_id'], 'maker' => (string)($b['maker_id'] ?? ''), 'send_qty' => (float)$b['send_qty'],
                'send_date' => !empty($b['send_date']) ? substr($b['send_date'], 0, 10) : '', 'return_qty' => (float)$b['return_qty'],
                'scrap_qty' => (float)$b['scrap_qty'], 'withdrawn_qty' => (float)($b['withdrawn_qty'] ?? 0), 'status' => $b['status'],
                'is_today' => (int)$b['is_today'] === 1, 'by_name' => (string)($b['issued_by_name'] ?? ''),
                'returns' => array_map(function ($r) {
                    return ['return_id' => (int)$r['return_id'], 'return_qty' => (float)$r['return_qty'], 'scrap_qty' => (float)$r['scrap_qty'],
                        'withdraw_qty' => (float)($r['withdraw_qty'] ?? 0), 'return_date' => (string)$r['rd'],
                        'is_today' => (int)$r['is_today'] === 1, 'by_name' => (string)($r['by_name'] ?? ''), 'note' => (string)($r['note'] ?? '')];
                }, $by[(int)$b['batch_id']] ?? [])];
        }
        $rep = ber_reported($db, $fid);
        return ['proc' => ['bom' => $proc['bom'], 'bom_sn' => (int)$proc['bom_sn'], 'sqty' => (float)$proc['sqty'], 'state' => $proc['processing_state']],
                'batches' => $out, 'return_total' => $retTotal, 'reported' => $rep];
    }
}

if (!function_exists('ber_log')) {
    function ber_log(PDO $db, int $fid, string $type, string $note, int $uid): void {
        try {
            $db->prepare("INSERT INTO bom_ing_event (bom_ing_fid, event_type, event_note, Created_By) VALUES (?,?,?,?)")
               ->execute([$fid, $type, mb_substr($note, 0, 500), (string)$uid]);
        } catch (Throwable $e) { /* 稽核紀錄寫入失敗不擋已成功的修改 */ }
    }
}

if (!function_exists('ber_after_change')) {
    /** 重算批次狀態（回廠+報廢+取回 >= 移送 才算結清）；若整站因此從「已回廠」變成還有貨在外且尚無報工／檢驗，狀態退回加工中、清回廠日。 */
    function ber_after_change(PDO $db, int $fid, int $batchId): void {
        $db->prepare("UPDATE bom_ing_outsource_batch SET status=IF(return_qty+scrap_qty+COALESCE(withdrawn_qty,0) >= send_qty - 0.0000001,'closed','open'), updated_at=NOW() WHERE batch_id=?")->execute([$batchId]);
        $allDone = eg_bom_outsource_all_closed($db, $fid) && eg_bom_outsource_fully_sent($db, $fid);
        if ($allDone) return;
        $rep = ber_reported($db, $fid);
        if ($rep['need'] > 0) return; // 已有報工／檢驗，不去動製程狀態
        $db->prepare("UPDATE bom_ing SET processing_state='ing', return_date=NULL, Modified_At=NOW() WHERE bom_ing_fid=? AND processing_state IN ('Q','P') AND return_date IS NOT NULL")->execute([$fid]);
    }
}

if (!function_exists('ber_check_return_floor')) {
    function ber_check_return_floor(PDO $db, int $fid, float $newTotal): void {
        $rep = ber_reported($db, $fid);
        if ($newTotal + 1e-9 < $rep['need']) {
            throw new Exception('修改後這一站回廠數量合計 ' . $newTotal . ' 少於已通報的數量 ' . $rep['need']
                . '（QC檢驗 ' . $rep['qc'] . '、報工 ' . $rep['pm'] . '），請先處理報工／QC紀錄，避免資料不一致');
        }
    }
}

if (!function_exists('ber_save_send')) {
    /** 修改某一批的移送數量。 */
    function ber_save_send(PDO $db, int $fid, int $batchId, float $newSend, int $uid): array {
        eg_bom_outsource_ensure_schema($db);
        if ($newSend <= 0) throw new Exception('移送數量必須大於 0');
        $db->beginTransaction();
        try {
            $st = $db->prepare("SELECT * FROM bom_ing_outsource_batch WHERE batch_id=? AND bom_ing_fid=? FOR UPDATE");
            $st->execute([$batchId, $fid]);
            $b = $st->fetch(PDO::FETCH_ASSOC);
            if (!$b) throw new Exception('找不到這一批（可能已被刪除，請重新整理）');
            $used = (float)$b['return_qty'] + (float)$b['scrap_qty'] + (float)($b['withdrawn_qty'] ?? 0);
            if ($newSend + 1e-9 < $used) throw new Exception('移送數量不可少於這批已回廠＋報廢＋取回的 ' . $used);
            $sq = $db->prepare("SELECT sqty FROM bom_ing WHERE bom_ing_fid=?");
            $sq->execute([$fid]);
            $stage = (float)$sq->fetchColumn();
            $sum = $db->prepare("SELECT COALESCE(SUM(send_qty - COALESCE(withdrawn_qty,0)),0) FROM bom_ing_outsource_batch WHERE bom_ing_fid=? AND batch_id<>?");
            $sum->execute([$fid, $batchId]);
            $others = (float)$sum->fetchColumn();
            if ($others + $newSend - (float)($b['withdrawn_qty'] ?? 0) > $stage + 1e-9) {
                throw new Exception('各批移送數量合計不可超過這一站的發單數 ' . $stage);
            }
            $db->prepare("UPDATE bom_ing_outsource_batch SET send_qty=?, updated_at=NOW() WHERE batch_id=?")->execute([$newSend, $batchId]);
            ber_after_change($db, $fid, $batchId);
            ber_log($db, $fid, 'send_edit', '修改移送數量 批次#' . $batchId . '：' . (float)$b['send_qty'] . ' → ' . $newSend, $uid);
            $db->commit();
            return ['old' => (float)$b['send_qty'], 'new' => $newSend];
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }
}

if (!function_exists('ber_save_return')) {
    /** 修改某一次回廠紀錄的回廠／報廢／取回數量與回廠日。 */
    function ber_save_return(PDO $db, int $fid, int $returnId, float $ret, float $scrap, float $wd, ?string $date, int $uid): array {
        eg_bom_outsource_ensure_schema($db);
        if ($ret < 0 || $scrap < 0 || $wd < 0) throw new Exception('數量不可為負數');
        if ($ret + $scrap + $wd <= 0) throw new Exception('回廠、報廢、取回至少要填一個（要整筆作廢請用刪除）');
        if ($date !== null && $date !== '') {
            $dt = DateTime::createFromFormat('Y-m-d', $date);
            if (!$dt || $dt->format('Y-m-d') !== $date) throw new Exception('回廠日格式不正確');
            if ($date > date('Y-m-d')) throw new Exception('回廠日不可晚於今天');
        } else { $date = null; }
        $db->beginTransaction();
        try {
            $st = $db->prepare("SELECT * FROM bom_ing_outsource_return WHERE return_id=? AND bom_ing_fid=? FOR UPDATE");
            $st->execute([$returnId, $fid]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new Exception('找不到這筆回廠紀錄（可能已被刪除，請重新整理）');
            $bs = $db->prepare("SELECT * FROM bom_ing_outsource_batch WHERE batch_id=? FOR UPDATE");
            $bs->execute([$r['batch_id']]);
            $b = $bs->fetch(PDO::FETCH_ASSOC);
            if (!$b) throw new Exception('找不到所屬批次');
            $nr = (float)$b['return_qty'] - (float)$r['return_qty'] + $ret;
            $ns = (float)$b['scrap_qty']  - (float)$r['scrap_qty']  + $scrap;
            $nw = (float)($b['withdrawn_qty'] ?? 0) - (float)($r['withdraw_qty'] ?? 0) + $wd;
            if ($nr + $ns + $nw > (float)$b['send_qty'] + 1e-9) {
                throw new Exception('回廠＋報廢＋取回合計 ' . ($nr + $ns + $nw) . ' 超過這批移送數量 ' . $b['send_qty']);
            }
            $tot = $db->prepare("SELECT COALESCE(SUM(return_qty),0) FROM bom_ing_outsource_batch WHERE bom_ing_fid=? AND batch_id<>?");
            $tot->execute([$fid, $b['batch_id']]);
            ber_check_return_floor($db, $fid, (float)$tot->fetchColumn() + $nr);
            $db->prepare("UPDATE bom_ing_outsource_batch SET return_qty=?, scrap_qty=?, withdrawn_qty=?, updated_at=NOW() WHERE batch_id=?")->execute([$nr, $ns, $nw, $b['batch_id']]);
            $db->prepare("UPDATE bom_ing_outsource_return SET return_qty=?, scrap_qty=?, withdraw_qty=?, return_date=COALESCE(?,return_date) WHERE return_id=?")->execute([$ret, $scrap, $wd, $date, $returnId]);
            ber_after_change($db, $fid, (int)$b['batch_id']);
            ber_log($db, $fid, 'return_edit', '修改回廠紀錄#' . $returnId . '：回廠 ' . (float)$r['return_qty'] . '→' . $ret . '、報廢 ' . (float)$r['scrap_qty'] . '→' . $scrap . '、取回 ' . (float)($r['withdraw_qty'] ?? 0) . '→' . $wd, $uid);
            $db->commit();
            return ['batch_id' => (int)$b['batch_id']];
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }
}

if (!function_exists('ber_delete_return')) {
    /** 刪除當天建立的回廠紀錄（誤按回廠）。 */
    function ber_delete_return(PDO $db, int $fid, int $returnId, int $uid): void {
        eg_bom_outsource_ensure_schema($db);
        $db->beginTransaction();
        try {
            $st = $db->prepare("SELECT *, (DATE(created_at)=CURDATE()) AS is_today FROM bom_ing_outsource_return WHERE return_id=? AND bom_ing_fid=? FOR UPDATE");
            $st->execute([$returnId, $fid]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new Exception('找不到這筆回廠紀錄（可能已被刪除，請重新整理）');
            if ((int)$r['is_today'] !== 1) throw new Exception('只有當天建立的回廠紀錄可以刪除');
            $bs = $db->prepare("SELECT * FROM bom_ing_outsource_batch WHERE batch_id=? FOR UPDATE");
            $bs->execute([$r['batch_id']]);
            $b = $bs->fetch(PDO::FETCH_ASSOC);
            if (!$b) throw new Exception('找不到所屬批次');
            $nr = (float)$b['return_qty'] - (float)$r['return_qty'];
            $tot = $db->prepare("SELECT COALESCE(SUM(return_qty),0) FROM bom_ing_outsource_batch WHERE bom_ing_fid=? AND batch_id<>?");
            $tot->execute([$fid, $b['batch_id']]);
            ber_check_return_floor($db, $fid, (float)$tot->fetchColumn() + $nr);
            $db->prepare("UPDATE bom_ing_outsource_batch SET return_qty=GREATEST(0,return_qty-?), scrap_qty=GREATEST(0,scrap_qty-?), withdrawn_qty=GREATEST(0,COALESCE(withdrawn_qty,0)-?), updated_at=NOW() WHERE batch_id=?")
               ->execute([(float)$r['return_qty'], (float)$r['scrap_qty'], (float)($r['withdraw_qty'] ?? 0), $b['batch_id']]);
            $db->prepare("DELETE FROM bom_ing_outsource_return WHERE return_id=?")->execute([$returnId]);
            ber_after_change($db, $fid, (int)$b['batch_id']);
            ber_log($db, $fid, 'return_delete', '刪除當天回廠紀錄#' . $returnId . '（回廠 ' . (float)$r['return_qty'] . '、報廢 ' . (float)$r['scrap_qty'] . '、取回 ' . (float)($r['withdraw_qty'] ?? 0) . '）', $uid);
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }
}

if (!function_exists('ber_delete_batch')) {
    /** 刪除當天建立、尚無任何回廠紀錄的移送批次（誤送）。 */
    function ber_delete_batch(PDO $db, int $fid, int $batchId, int $uid): void {
        eg_bom_outsource_ensure_schema($db);
        $db->beginTransaction();
        try {
            $st = $db->prepare("SELECT *, (DATE(created_at)=CURDATE()) AS is_today FROM bom_ing_outsource_batch WHERE batch_id=? AND bom_ing_fid=? FOR UPDATE");
            $st->execute([$batchId, $fid]);
            $b = $st->fetch(PDO::FETCH_ASSOC);
            if (!$b) throw new Exception('找不到這一批（可能已被刪除，請重新整理）');
            if ((int)$b['is_today'] !== 1) throw new Exception('只有當天建立的移送批次可以刪除');
            $n = $db->prepare("SELECT COUNT(*) FROM bom_ing_outsource_return WHERE batch_id=?");
            $n->execute([$batchId]);
            if ((int)$n->fetchColumn() > 0 || (float)$b['return_qty'] > 0 || (float)$b['scrap_qty'] > 0 || (float)($b['withdrawn_qty'] ?? 0) > 0) {
                throw new Exception('這批已有回廠／報廢／取回紀錄，請先刪除那些紀錄');
            }
            $c = $db->prepare("SELECT COUNT(*) FROM bom_ing_outsource_batch WHERE bom_ing_fid=?");
            $c->execute([$fid]);
            if ((int)$c->fetchColumn() <= 1) throw new Exception('這是這一站唯一的移送批次，要整站退回請使用「取消移轉」');
            $db->prepare("DELETE FROM bom_ing_outsource_batch WHERE batch_id=?")->execute([$batchId]);
            ber_log($db, $fid, 'send_delete', '刪除當天誤送批次#' . $batchId . '（移送 ' . (float)$b['send_qty'] . '，' . ($b['maker_id'] ?? '') . '）', $uid);
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }
}
