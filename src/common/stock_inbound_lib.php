<?php
// 成品入庫單（包裝排程「待入庫」與庫存管理「入庫單」共用的唯一實作）
// 來源＝qc_packing_inspection（已結案、warehouse_qty>0）；入庫明細存 stock_inbound_receipt（同一批可拆多個儲位）。
// 狀態不存欄位，由「已入庫合計 vs 應入庫數」即時推導：待入庫／部分入庫／已完成。

function si_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $has = $pdo->query("SHOW TABLES LIKE 'stock_inbound_receipt'")->fetchColumn();
    if (!$has && !$pdo->inTransaction()) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS stock_inbound_receipt (
            receipt_id INT AUTO_INCREMENT PRIMARY KEY,
            packing_inspection_id INT NOT NULL,
            stock_item_id INT NULL,
            txn_id INT NULL,
            qty INT NOT NULL,
            location_id INT NOT NULL,
            location_code VARCHAR(60) NULL COMMENT '入庫當下儲位快照',
            received_date DATE NOT NULL,
            remark VARCHAR(200) NULL,
            Created_By INT NULL,
            Created_At TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sir_pi (packing_inspection_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $done = true;
}

/** 入庫備註：棧板+膠膜要顯示（使用者要求） */
function si_note(array $r): string {
    $n = [];
    if (($r['storage_method'] ?? '') === 'pallet') $n[] = '棧板+膠膜' . (($r['pallet_qty'] ?? '') !== '' && $r['pallet_qty'] !== null ? ' ' . (int)$r['pallet_qty'] . ' 板' : '');
    return implode('；', $n);
}

/**
 * 待入庫清單。$status：''全部／pending／partial／done。
 * 排序：done 由新到舊；其餘由舊到新（$forceDesc 給包裝排程用＝一律新到舊）。
 */
function si_list(PDO $pdo, string $status, string $kw, int $page, int $per, bool $forceDesc = false): array {
    si_ensure_schema($pdo);
    $base = "FROM qc_packing_inspection q
             LEFT JOIN (SELECT packing_inspection_id, SUM(qty) rq, MAX(received_date) last_date FROM stock_inbound_receipt GROUP BY packing_inspection_id) r
                    ON r.packing_inspection_id = q.packing_inspection_id
             WHERE q.status='closed' AND q.warehouse_qty > 0";
    $params = [];
    if ($kw !== '') {
        $base .= " AND (q.bom LIKE ? OR q.part_no LIKE ? OR q.customer_name LIKE ?)";
        $lk = '%' . $kw . '%'; array_push($params, $lk, $lk, $lk);
    }
    $stSql = "CASE WHEN COALESCE(r.rq,0)=0 THEN 'pending' WHEN COALESCE(r.rq,0) < q.warehouse_qty THEN 'partial' ELSE 'done' END";
    // 各狀態筆數（依關鍵字，不含狀態篩選）
    $cs = $pdo->prepare("SELECT $stSql st, COUNT(*) n $base GROUP BY st");
    $cs->execute($params);
    $counts = ['all' => 0, 'pending' => 0, 'partial' => 0, 'done' => 0];
    foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $c) { $counts[$c['st']] = (int)$c['n']; $counts['all'] += (int)$c['n']; }

    $where = '';
    if (in_array($status, ['pending', 'partial', 'done'], true)) $where = " AND ($stSql) = '$status'";
    $ct = $pdo->prepare("SELECT COUNT(*) $base $where"); $ct->execute($params);
    $total = (int)$ct->fetchColumn();

    $desc = $forceDesc || $status === 'done';
    $dir = $desc ? 'DESC' : 'ASC';
    $off = max(0, ($page - 1) * $per);
    $sql = "SELECT q.packing_inspection_id, q.bom, q.part_no, q.customer_name, q.inspection_date, q.warehouse_qty,
                   q.storage_method, q.pallet_qty, COALESCE(r.rq,0) received_qty, r.last_date, $stSql AS st
            $base $where ORDER BY q.inspection_date $dir, q.packing_inspection_id $dir LIMIT $per OFFSET $off";
    $s = $pdo->prepare($sql); $s->execute($params);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_column($rows, 'packing_inspection_id');
    $rec = si_receipts($pdo, $ids);
    foreach ($rows as &$r) {
        $r['note'] = si_note($r);
        $r['receipts'] = $rec[$r['packing_inspection_id']] ?? [];
        $r['remain_qty'] = max(0, (int)$r['warehouse_qty'] - (int)$r['received_qty']);
    }
    return ['rows' => $rows, 'total' => $total, 'counts' => $counts];
}

function si_receipts(PDO $pdo, array $ids): array {
    if (!$ids) return [];
    $in = implode(',', array_map('intval', $ids));
    $out = [];
    $q = $pdo->query("SELECT receipt_id, packing_inspection_id, qty, location_code, received_date, remark
                      FROM stock_inbound_receipt WHERE packing_inspection_id IN ($in) ORDER BY receipt_id");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['packing_inspection_id']][] = $r;
    return $out;
}

/** 入庫：$splits＝[['location_id'=>,'qty'=>],…]，同一批可拆不同儲位；全部在同一個交易內 */
function si_receive(PDO $pdo, int $piId, array $splits, string $date, int $userId, string $remark = ''): array {
    si_ensure_schema($pdo);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new Exception('請指定入庫日期');
    if ($date > date('Y-m-d')) throw new Exception('入庫日期不可晚於今天');
    $clean = [];
    foreach ($splits as $s) {
        $loc = (int)($s['location_id'] ?? 0); $q = (int)($s['qty'] ?? 0);
        if ($q <= 0 && $loc <= 0) continue;
        if ($loc <= 0) throw new Exception('每一列都要選擇入庫儲位');
        if ($q <= 0) throw new Exception('入庫數量必須大於 0');
        $clean[] = ['loc' => $loc, 'qty' => $q];
    }
    if (!$clean) throw new Exception('請至少填寫一列入庫位置與數量');

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM qc_packing_inspection WHERE packing_inspection_id=? FOR UPDATE");
        $st->execute([$piId]); $pi = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pi || $pi['status'] !== 'closed' || (int)$pi['warehouse_qty'] <= 0) throw new Exception('這筆沒有待入庫數量（可能已被修改，請重新整理）');
        $rq = $pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM stock_inbound_receipt WHERE packing_inspection_id=?");
        $rq->execute([$piId]);
        $remain = (int)$pi['warehouse_qty'] - (int)$rq->fetchColumn();
        $sum = array_sum(array_column($clean, 'qty'));
        if ($sum > $remain) throw new Exception("本次入庫合計 {$sum} 超過尚未入庫數量 {$remain}，請重新整理後再填");

        // 料號主檔 id：BOM 優先，退回以料號文字找主檔（同名取最小 id）
        $dsid = null;
        if ($pi['bom']) {
            $b = $pdo->prepare("SELECT d_setting_id FROM bom WHERE bom=? LIMIT 1"); $b->execute([$pi['bom']]);
            $dsid = (int)$b->fetchColumn() ?: null;
        }
        if (!$dsid && $pi['part_no']) {
            $b = $pdo->prepare("SELECT MIN(d_id) FROM d_setting WHERE D_Setting_Id=?"); $b->execute([$pi['part_no']]);
            $dsid = (int)$b->fetchColumn() ?: null;
        }
        $partTxt = (string)$pi['part_no'];
        $clientName = (string)$pi['customer_name'];
        $clientId = null;
        if ($dsid) {
            $d = $pdo->prepare("SELECT d.D_Setting_Id, d.Customer_Id FROM d_setting d WHERE d.d_id=?"); $d->execute([$dsid]);
            if ($dr = $d->fetch(PDO::FETCH_ASSOC)) { if ($dr['D_Setting_Id'] !== null && $dr['D_Setting_Id'] !== '') $partTxt = $dr['D_Setting_Id']; $clientId = $dr['Customer_Id'] ?: null; }
        }
        if ($partTxt === '') throw new Exception('找不到料號，無法入庫');

        foreach ($clean as $c) {
            $l = $pdo->prepare("SELECT location_code FROM stock_locations WHERE location_id=? AND is_active=1");
            $l->execute([$c['loc']]); $code = $l->fetchColumn();
            if ($code === false) throw new Exception('入庫儲位不存在或已停用');
            // 成品庫存列：同料號主檔＋同儲位沿用，沒有才建立
            $f = $pdo->prepare("SELECT stock_item_id, qty FROM stock_items WHERE item_type=1 AND is_active=1 AND location_id=? AND " . ($dsid ? "d_setting_id=?" : "d_id=?") . " LIMIT 1 FOR UPDATE");
            $f->execute([$c['loc'], $dsid ?: $partTxt]);
            $it = $f->fetch(PDO::FETCH_ASSOC);
            if ($it) {
                $before = (int)$it['qty']; $itemId = (int)$it['stock_item_id'];
                $pdo->prepare("UPDATE stock_items SET qty=qty+?, Modified_By=?, Modified_At=NOW() WHERE stock_item_id=?")->execute([$c['qty'], $userId, $itemId]);
            } else {
                $before = 0;
                $pdo->prepare("INSERT INTO stock_items (d_id,d_setting_id,item_type,storage_location,location_id,qty,bom_ref,client_name,client_id,stock_date,Created_By) VALUES (?,?,1,?,?,?,?,?,?,?,?)")
                    ->execute([$partTxt, $dsid, $code, $c['loc'], $c['qty'], $pi['bom'] ?: null, $clientName ?: null, $clientId, $date, $userId]);
                $itemId = (int)$pdo->lastInsertId();
            }
            $rmk = '包裝入庫（BOM:' . $pi['bom'] . '）' . ($remark !== '' ? ' ' . $remark : '');
            $pdo->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,location_to,location_to_id,bom_ref,txn_date,remark,Created_By) VALUES (?,?,'in',?,?,?,?,?,?,?,?,?)")
                ->execute([$itemId, $partTxt, $c['qty'], $before, $before + $c['qty'], $code, $c['loc'], $pi['bom'] ?: null, $date, mb_substr($rmk, 0, 300), $userId]);
            $txnId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO stock_inbound_receipt (packing_inspection_id,stock_item_id,txn_id,qty,location_id,location_code,received_date,remark,Created_By) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$piId, $itemId, $txnId, $c['qty'], $c['loc'], $code, $date, $remark !== '' ? mb_substr($remark, 0, 200) : null, $userId]);
        }
        $pdo->commit();
        return ['received' => $sum, 'remain' => $remain - $sum];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
