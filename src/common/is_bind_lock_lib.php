<?php
/**
 * 出貨單「已綁定確認」記號＋ERP 匯入保護（2026-11-11 使用者交辦）——唯一實作
 * ------------------------------------------------------------------
 * 為什麼：「更新資料」頁的出貨單 ERP 直接匯入，是把檔案日期區間內的 is_list 全部刪掉再重新插入，
 * IS_id 會全部換新。凡是靠 IS_id 綁住的東西（出貨↔訂單、出貨↔製令、退貨重出、專案出貨、對帳底稿…）
 * 會被 FK CASCADE 一起刪掉或變成孤兒，而且完全不報錯。目前的 ERP 資料又有問題（重複轉出、日期錯），
 * 已經人工綁好的紀錄不能被它洗掉。
 *
 * 規則：
 *  - is_list.bind_locked=1＝這一列出貨已有人工或系統建立的綁定，ERP 匯入「不刪、不改、IS_id 不動」。
 *  - 記號的來源：①綁定寫入時呼叫 isbl_mark()　②匯入當下 isbl_bound_ids() 再盤點一次真的有綁定的列並補上記號
 *    （任何一個寫綁定的地方漏呼叫，也不會因此被匯入洗掉）。
 *  - 檔案裡對應到已鎖定列的資料：內容相同→略過；內容不同→不覆蓋，寫進 is_list_erp_diff 待管理員逐欄決定；
 *    已鎖定列在檔案裡找不到→記成 missing（ERP 可能刪了這筆），一樣由管理員決定，系統不自動刪。
 *  - 管理員套用時只 UPDATE 勾選的欄位，IS_id 一律不變（綁定才找得到）。
 */

if (!function_exists('isbl_ensure_schema')) {

/** 比對與可套用的欄位：檔案列的鍵 => is_list 欄位（d_setting_id 刻意不比：綁定的料號主檔常是人工修正過的） */
function isbl_fields(): array {
    return ['order_date' => 'Order_date', 'client_name' => 'Client_name', 'specification' => 'Specification',
            'content' => 'Content', 'qty' => 'Qty', 'unit_price' => 'Unit_price', 'warehouse' => 'Warehouse', 'note' => 'Note'];
}
function isbl_field_labels(): array {
    return ['Order_date' => '出貨日期', 'Client_name' => '客戶', 'Specification' => '品名', 'Content' => '內容',
            'Qty' => '數量', 'Unit_price' => '單價', 'Warehouse' => '倉庫', 'Note' => '備註'];
}

/** DDL 一律在交易外呼叫（CREATE/ALTER 會隱式 commit） */
function isbl_ensure_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $cols = [];
    foreach ($db->query("SHOW COLUMNS FROM is_list")->fetchAll(PDO::FETCH_ASSOC) as $c) $cols[$c['Field']] = 1;
    if (!isset($cols['bind_locked']))    $db->exec("ALTER TABLE is_list ADD COLUMN bind_locked TINYINT NOT NULL DEFAULT 0 COMMENT '1=已綁定確認，ERP匯入不覆蓋'");
    if (!isset($cols['bind_locked_at'])) $db->exec("ALTER TABLE is_list ADD COLUMN bind_locked_at DATETIME NULL");
    if (!isset($cols['bind_locked_by'])) $db->exec("ALTER TABLE is_list ADD COLUMN bind_locked_by INT NULL");
    $idx = $db->query("SHOW INDEX FROM is_list WHERE Key_name='idx_is_bind_locked'")->fetchAll();
    if (!$idx) $db->exec("ALTER TABLE is_list ADD INDEX idx_is_bind_locked (bind_locked, Order_date)");
    $db->exec("CREATE TABLE IF NOT EXISTS is_list_erp_diff (
        id INT AUTO_INCREMENT PRIMARY KEY,
        is_id INT NOT NULL,
        is_number VARCHAR(30) NULL,
        kind VARCHAR(10) NOT NULL COMMENT 'changed=ERP內容不同 / missing=ERP檔案裡找不到',
        old_json TEXT NULL, new_json TEXT NULL,
        diff_fields VARCHAR(255) NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'pending' COMMENT 'pending/applied/kept/superseded',
        applied_fields VARCHAR(255) NULL,
        created_at DATETIME NOT NULL, created_by INT NULL,
        decided_at DATETIME NULL, decided_by INT NULL,
        KEY idx_status (status), KEY idx_is (is_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ERP出貨單匯入時，已綁定出貨單的差異（待管理員決定）'");
    $done = true;
}

/** 補上「已綁定確認」記號（已有記號的不動，保留第一次確認的時間與人） */
function isbl_mark(PDO $db, array $isIds, int $uid = 0): int {
    $ids = array_values(array_unique(array_filter(array_map('intval', $isIds))));
    if (!$ids) return 0;
    isbl_ensure_schema($db);
    $st = $db->prepare("UPDATE is_list SET bind_locked=1, bind_locked_at=NOW(), bind_locked_by=? WHERE bind_locked=0 AND IS_id IN (" . implode(',', $ids) . ")");
    $st->execute([$uid ?: null]);
    return $st->rowCount();
}

/**
 * 綁定寫入／解除之後呼叫：真的有綁定的補上記號；已經沒有任何綁定的把記號拿掉（之後 ERP 匯入就照常更新它）。
 * 失敗不擋呼叫端（記號只是保護，匯入當下還會再盤點一次）。
 */
function isbl_sync(PDO $db, array $isIds, int $uid = 0): void {
    try {
        $ids = array_values(array_unique(array_filter(array_map('intval', $isIds))));
        if (!$ids) return;
        isbl_ensure_schema($db);
        $real = isbl_bound_among($db, $ids, false);
        if ($real) isbl_mark($db, $real, $uid);
        $gone = array_values(array_diff($ids, $real));
        if ($gone) $db->exec("UPDATE is_list SET bind_locked=0, bind_locked_at=NULL, bind_locked_by=NULL WHERE bind_locked=1 AND IS_id IN (" . implode(',', $gone) . ")");
    } catch (Throwable $e) { error_log('isbl_sync: ' . $e->getMessage()); }
}

/** 這些出貨單裡，實際有綁定（$withFlag=true 時連「已有記號」也算）的 IS_id */
function isbl_bound_among(PDO $db, array $isIds, bool $withFlag = true): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $isIds))));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $sql = "SELECT IS_id FROM is_list WHERE IS_id IN ($in) AND (" . ($withFlag ? "bind_locked=1 OR " : "") . "Order_id IS NOT NULL)
            UNION SELECT IS_id FROM shipment_order_map WHERE IS_id IN ($in)
            UNION SELECT IS_id FROM is_order_map WHERE IS_id IN ($in)
            UNION SELECT IS_id FROM is_bom_map WHERE IS_id IN ($in)
            UNION SELECT IS_id FROM return_order_map WHERE IS_id IN ($in)
            UNION SELECT IS_id FROM ir_reship_map WHERE IS_id IN ($in)
            UNION SELECT is_id FROM project_shipment WHERE is_id IN ($in)
            UNION SELECT src_id FROM acc_recon_line WHERE src_type='IS' AND src_id IN ($in)
            UNION SELECT src_id FROM acc_invoice_item WHERE src_type='IS' AND src_id IN ($in)";
    $out = [];
    foreach ($db->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $v) $out[(int)$v] = (int)$v;
    return array_values($out);
}

/**
 * 匯入計畫：哪些檔案列要新增、哪些已鎖定列要保留、哪些有差異。
 * 候選鎖定列＝日期在檔案區間內，或出貨單號出現在檔案裡（ERP 改了日期也認得出來）。
 * 配對鍵＝出貨單號＋料號；同一鍵有多列時先配「內容完全相同」的，剩下的依原順序兩兩配對。
 * @return array ['insert'=>[檔案列…], 'locked_ids'=>[…], 'same'=>n, 'changed'=>[[is_id, old, new, fields]], 'missing'=>[old…]]
 */
function isbl_plan(PDO $db, array $rows, string $from, string $to): array {
    isbl_ensure_schema($db);
    $nos = array_values(array_unique(array_filter(array_map(function ($r) { return (string)($r['is_number'] ?? ''); }, $rows))));
    $cand = [];
    $st = $db->prepare("SELECT IS_id FROM is_list WHERE Order_date BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) $cand[(int)$v] = 1;
    foreach (array_chunk($nos, 500) as $chunk) {
        $q = $db->prepare("SELECT IS_id FROM is_list WHERE IS_number IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")");
        $q->execute($chunk);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $v) $cand[(int)$v] = 1;
    }
    $locked = isbl_bound_among($db, array_keys($cand));
    $plan = ['insert' => [], 'locked_ids' => $locked, 'same' => 0, 'changed' => [], 'missing' => []];
    if (!$locked) { $plan['insert'] = $rows; return $plan; }

    $F = isbl_fields();
    $lrows = $db->query("SELECT IS_id, IS_number, Product_id, " . implode(',', array_values($F)) . " FROM is_list WHERE IS_id IN (" . implode(',', $locked) . ") ORDER BY IS_id")->fetchAll(PDO::FETCH_ASSOC);
    $byKey = [];
    foreach ($lrows as $l) $byKey[trim((string)$l['IS_number']) . '|' . trim((string)$l['Product_id'])][] = $l;

    $norm = function ($col, $v) {
        if ($v === null) return '';
        if ($col === 'Qty' || $col === 'Unit_price') return is_numeric($v) ? rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.') : '';
        if ($col === 'Order_date') return substr((string)$v, 0, 10);
        return trim((string)$v);
    };
    $diffOf = function (array $fileRow, array $dbRow) use ($F, $norm) {
        $d = [];
        foreach ($F as $fk => $col) if ($norm($col, $fileRow[$fk] ?? null) !== $norm($col, $dbRow[$col] ?? null)) $d[] = $col;
        return $d;
    };
    $pendingByKey = [];
    foreach ($rows as $r) {
        $k = trim((string)($r['is_number'] ?? '')) . '|' . trim((string)($r['product_id'] ?? ''));
        if (empty($byKey[$k])) { $plan['insert'][] = $r; continue; }
        $hit = null;   // 先找內容完全相同的
        foreach ($byKey[$k] as $i => $l) if (!$diffOf($r, $l)) { $hit = $i; break; }
        if ($hit !== null) { $plan['same']++; array_splice($byKey[$k], $hit, 1); continue; }
        $pendingByKey[$k][] = $r;
    }
    foreach ($pendingByKey as $k => $list) {
        foreach ($list as $r) {
            if (!empty($byKey[$k])) {
                $l = array_shift($byKey[$k]);
                $new = [];
                foreach ($F as $fk => $col) $new[$col] = $r[$fk] ?? null;
                $plan['changed'][] = ['is_id' => (int)$l['IS_id'], 'is_number' => (string)$l['IS_number'], 'old' => $l, 'new' => $new + ['Product_id' => $l['Product_id']], 'fields' => $diffOf($r, $l)];
            } else {
                $plan['insert'][] = $r;   // 同一張單 ERP 多出來的明細列：正常新增
            }
        }
    }
    foreach ($byKey as $list) foreach ($list as $l) {
        // 日期不在檔案區間、單號也不在檔案裡的鎖定列不會走到這裡；走到這裡的是檔案涵蓋範圍內卻找不到的
        $plan['missing'][] = $l;
    }
    return $plan;
}

/** 把計畫裡的差異寫進待決定清單（同一列之前還沒決定的舊差異標成 superseded，只留最新一筆） */
function isbl_record_diffs(PDO $db, array $plan, int $uid): int {
    $n = 0;
    $sup = $db->prepare("UPDATE is_list_erp_diff SET status='superseded' WHERE is_id=? AND status='pending'");
    $ins = $db->prepare("INSERT INTO is_list_erp_diff (is_id, is_number, kind, old_json, new_json, diff_fields, created_at, created_by) VALUES (?,?,?,?,?,?,NOW(),?)");
    foreach ($plan['changed'] as $c) {
        $sup->execute([$c['is_id']]);
        $ins->execute([$c['is_id'], $c['is_number'], 'changed', json_encode($c['old'], JSON_UNESCAPED_UNICODE), json_encode($c['new'], JSON_UNESCAPED_UNICODE), implode(',', $c['fields']), $uid ?: null]);
        $n++;
    }
    foreach ($plan['missing'] as $l) {
        $sup->execute([(int)$l['IS_id']]);
        $ins->execute([(int)$l['IS_id'], (string)$l['IS_number'], 'missing', json_encode($l, JSON_UNESCAPED_UNICODE), null, null, $uid ?: null]);
        $n++;
    }
    return $n;
}

/** 管理員：套用勾選的欄位（只 UPDATE 這些欄位，IS_id 不變） */
function isbl_apply(PDO $db, int $diffId, array $fields, int $uid): array {
    isbl_ensure_schema($db);
    $st = $db->prepare("SELECT * FROM is_list_erp_diff WHERE id=?");
    $st->execute([$diffId]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) throw new Exception('找不到這筆差異（請重新整理）');
    if ($d['status'] !== 'pending') throw new Exception('這筆差異已經處理過了（請重新整理）');
    if ($d['kind'] !== 'changed') throw new Exception('ERP 檔案裡找不到的出貨單只能選擇保留');
    $allow = array_flip(explode(',', (string)$d['diff_fields']));
    $fields = array_values(array_filter($fields, function ($f) use ($allow) { return isset($allow[$f]); }));
    if (!$fields) throw new Exception('請至少勾選一個要套用的欄位');
    $new = json_decode((string)$d['new_json'], true) ?: [];
    $set = []; $par = [];
    foreach ($fields as $f) { $set[] = "`$f`=?"; $par[] = $new[$f] ?? null; }
    $par[] = (int)$d['is_id'];
    $db->beginTransaction();
    try {
        $u = $db->prepare("UPDATE is_list SET " . implode(',', $set) . " WHERE IS_id=?");
        $u->execute($par);
        $db->prepare("UPDATE is_list_erp_diff SET status='applied', applied_fields=?, decided_at=NOW(), decided_by=? WHERE id=?")->execute([implode(',', $fields), $uid, $diffId]);
        try {
            $db->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at) VALUES ('update','is_list_erp_diff',?,?,?,?,?,NOW())")
               ->execute([(string)$d['is_id'], (string)$d['is_number'], json_encode(['fields' => $fields, 'old' => json_decode((string)$d['old_json'], true), 'new' => $new], JSON_UNESCAPED_UNICODE), $uid, (string)($_SESSION['user_cname'] ?? $uid)]);
        } catch (Throwable $e) {}
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return ['is_id' => (int)$d['is_id'], 'fields' => $fields];
}

/** 管理員：保留原資料（不套用 ERP 的內容） */
function isbl_keep(PDO $db, int $diffId, int $uid): void {
    $u = $db->prepare("UPDATE is_list_erp_diff SET status='kept', decided_at=NOW(), decided_by=? WHERE id=? AND status='pending'");
    $u->execute([$uid, $diffId]);
    if (!$u->rowCount()) throw new Exception('這筆差異已經處理過了（請重新整理）');
}

/** 待決定清單（含目前資料庫的現值，讓管理員看到「舊／現在／ERP 新」） */
function isbl_pending(PDO $db): array {
    isbl_ensure_schema($db);
    $rows = $db->query("SELECT d.*, u.user_cname AS created_name FROM is_list_erp_diff d LEFT JOIN user u ON u.id=d.created_by WHERE d.status='pending' ORDER BY d.id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_column($rows, 'is_id');
    $cur = []; $alloc = [];
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        foreach ($db->query("SELECT * FROM is_list WHERE IS_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) $cur[(int)$r['IS_id']] = $r;
        foreach ($db->query("SELECT IS_id, SUM(shipped_qty) q FROM shipment_order_map WHERE IS_id IN ($in) GROUP BY IS_id")->fetchAll(PDO::FETCH_ASSOC) as $r) $alloc[(int)$r['IS_id']] = (float)$r['q'];
    }
    foreach ($rows as &$r) {
        $r['old'] = json_decode((string)$r['old_json'], true) ?: [];
        $r['new'] = json_decode((string)$r['new_json'], true) ?: [];
        $r['cur'] = $cur[(int)$r['is_id']] ?? null;
        $r['alloc_qty'] = $alloc[(int)$r['is_id']] ?? null;
        $r['fields'] = $r['diff_fields'] ? explode(',', $r['diff_fields']) : [];
        unset($r['old_json'], $r['new_json']);
    }
    unset($r);
    return $rows;
}

}
