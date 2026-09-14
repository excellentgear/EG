<?php
/**
 * 出貨單 ↔ 訂單 綁定：全站唯一實作
 * ------------------------------------------------------------------
 * 為什麼要有這支共用檔（2026-09-14）：
 * 綁定同時存在「兩種來源」，各頁各自解讀就會出現對不起來的畫面：
 *   ① shipment_order_map  ＝精確綁定，可拆量（一張出貨分給多張訂單，各記 shipped_qty）
 *   ② is_list.Order_id    ＝舊資料的直接綁定，一張出貨只存得下一張訂單
 * 實際踩到的四個 bug 全部源自「某一頁只讀其中一種」：
 *   - ERP 成本分析的明細讀①②聯集顯示「已綁定1筆」，綁定跳窗卻只讀①→ 跳窗一片空白
 *   - 於是那筆②的綁定永遠解不掉（存檔只 DELETE ①，②原封不動）
 *   - 出貨分析的主清單只認②，所以在 ERP 綁好的（只有①）在那裡一律顯示「未綁定」
 *   - 出貨分析寫入前檢查 order_list，但 FK 其實是指向 order_track（資料表註解寫錯），
 *     檢查永遠不過 → 只寫得進②，回頭又餵大了第一個 bug
 *
 * 口徑（使用者 2026-09-14 拍板）：
 *   - 允許一張出貨拆給多張訂單，且「每一筆分配都要查得到」→ ① 是唯一權威紀錄
 *   - ② 降為「主要訂單」快取＝分配量最大的那一張，只由 sob_sync_is_order() 一處寫入
 *     （比照 trace_chain_lib 對 is_order_map／is_list.Order_id 的既有做法）
 *   - 舊資料只有②沒有①時，動到它之前先 sob_seed_direct() 補成①的一列，
 *     否則重存一次就會把舊綁定安靜地洗掉（trace_chain_lib 的 tc_seed_is_order 同一個坑）
 *
 * 禁止各頁再自己寫 shipment_order_map 的 INSERT/DELETE 或 is_list.Order_id 的 UPDATE。
 */

if (!function_exists('sob_bound_map')) {

/** 內部：把值正規化成大於 0 的整數陣列（去重） */
function sob__ids(array $ids) {
    $out = [];
    foreach ($ids as $v) { $v = intval($v); if ($v > 0) $out[$v] = $v; }
    return array_values($out);
}

/** 內部：產生 IN (?,?,?) 的佔位字串 */
function sob__ph(array $ids) {
    return implode(',', array_fill(0, count($ids), '?'));
}

/**
 * 這張訂單目前綁到哪些出貨單（①②聯集）
 * @return array IS_id => ['qty'=>int, 'src'=>'map'|'direct'|'both']
 *               qty：①有紀錄時用①的 shipped_qty 合計；只有②時用出貨單全量
 */
function sob_bound_map(PDO $pdo, $order_id) {
    $order_id = intval($order_id);
    if ($order_id <= 0) return [];
    $out = [];

    $st = $pdo->prepare(
        'SELECT IS_id, SUM(shipped_qty) AS qty FROM shipment_order_map'
        . ' WHERE Order_id = ? GROUP BY IS_id'
    );
    $st->execute([$order_id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[intval($r['IS_id'])] = ['qty' => intval($r['qty']), 'src' => 'map'];
    }

    // ② 舊資料的直接綁定：沒有①的才補進來，有①的標成 both（qty 以①為準）
    $st2 = $pdo->prepare('SELECT IS_id, Qty FROM is_list WHERE Order_id = ?');
    $st2->execute([$order_id]);
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sid = intval($r['IS_id']);
        if (isset($out[$sid])) { $out[$sid]['src'] = 'both'; continue; }
        $out[$sid] = ['qty' => intval($r['Qty']), 'src' => 'direct'];
    }
    return $out;
}

/**
 * 這些出貨單各自被分配到哪幾張訂單（供「拆分後每一筆都要追查得到」）
 * @return array IS_id => [ ['order_id','order_oo','client_name','qty','src'], ... ]
 */
function sob_ship_bindings(PDO $pdo, array $is_ids) {
    $is_ids = sob__ids($is_ids);
    if (!$is_ids) return [];
    $ph  = sob__ph($is_ids);
    $out = [];

    $st = $pdo->prepare(
        'SELECT som.IS_id, som.Order_id, som.shipped_qty, ot.Order_oo, ot.Client_name'
        . ' FROM shipment_order_map som'
        . ' LEFT JOIN order_track ot ON ot.Order_id = som.Order_id'
        . " WHERE som.IS_id IN ($ph)"
        . ' ORDER BY som.shipped_qty DESC, som.Order_id'
    );
    $st->execute($is_ids);
    $seen = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sid = intval($r['IS_id']);
        $oid = intval($r['Order_id']);
        $out[$sid][] = [
            'order_id'    => $oid,
            'order_oo'    => $r['Order_oo'] ?? '',
            'client_name' => $r['Client_name'] ?? '',
            'qty'         => intval($r['shipped_qty']),
            'src'         => 'map',
        ];
        $seen[$sid][$oid] = true;
    }

    // ② 只有直接綁定、還沒補進①的
    $st2 = $pdo->prepare(
        'SELECT il.IS_id, il.Order_id, il.Qty, ot.Order_oo, ot.Client_name'
        . ' FROM is_list il'
        . ' LEFT JOIN order_track ot ON ot.Order_id = il.Order_id'
        . " WHERE il.IS_id IN ($ph) AND il.Order_id IS NOT NULL AND il.Order_id <> 0"
    );
    $st2->execute($is_ids);
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sid = intval($r['IS_id']);
        $oid = intval($r['Order_id']);
        if (!empty($seen[$sid][$oid])) continue;
        $out[$sid][] = [
            'order_id'    => $oid,
            'order_oo'    => $r['Order_oo'] ?? '',
            'client_name' => $r['Client_name'] ?? '',
            'qty'         => intval($r['Qty']),
            'src'         => 'direct',
        ];
    }
    return $out;
}

/**
 * 把「只有②沒有①」的舊綁定補成①的一列，避免之後重存時被安靜洗掉。
 * 只處理指定的出貨單，不做全表回填。
 * @return int 補了幾列
 */
function sob_seed_direct(PDO $pdo, array $is_ids) {
    $is_ids = sob__ids($is_ids);
    if (!$is_ids) return 0;
    $ph = sob__ph($is_ids);
    // 有直接綁定、該訂單存在於 order_track（FK 要求）、且①還沒有對應列的
    $st = $pdo->prepare(
        'SELECT il.IS_id, il.Order_id, il.Qty'
        . ' FROM is_list il'
        . ' JOIN order_track ot ON ot.Order_id = il.Order_id'
        . " WHERE il.IS_id IN ($ph) AND il.Order_id IS NOT NULL AND il.Order_id <> 0"
        . '   AND NOT EXISTS (SELECT 1 FROM shipment_order_map som'
        . '                    WHERE som.IS_id = il.IS_id AND som.Order_id = il.Order_id)'
    );
    $st->execute($is_ids);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0;
    $ins = $pdo->prepare(
        'INSERT INTO shipment_order_map (IS_id, Order_id, shipped_qty, created_at) VALUES (?, ?, ?, NOW())'
    );
    foreach ($rows as $r) {
        $ins->execute([intval($r['IS_id']), intval($r['Order_id']), max(0, intval($r['Qty']))]);
    }
    return count($rows);
}

/**
 * 重算 is_list.Order_id ＝ 分配量最大的那一張訂單（並列時取 Order_id 較小者，結果才穩定）
 * 沒有任何①紀錄的出貨單一律不動（那是還沒被碰過的舊資料，不可以清掉）。
 */
function sob_sync_is_order(PDO $pdo, array $is_ids) {
    $is_ids = sob__ids($is_ids);
    if (!$is_ids) return;
    $ph = sob__ph($is_ids);
    $st = $pdo->prepare(
        'SELECT IS_id, Order_id, SUM(shipped_qty) AS qty FROM shipment_order_map'
        . " WHERE IS_id IN ($ph) GROUP BY IS_id, Order_id"
        . ' ORDER BY IS_id, qty DESC, Order_id ASC'
    );
    $st->execute($is_ids);
    $primary = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sid = intval($r['IS_id']);
        if (!isset($primary[$sid])) $primary[$sid] = intval($r['Order_id']); // 已排序，第一筆即最大
    }
    if (!$primary) return;
    $upd = $pdo->prepare('UPDATE is_list SET Order_id = ? WHERE IS_id = ?');
    foreach ($primary as $sid => $oid) $upd->execute([$oid, $sid]);
}

/**
 * 儲存「一張訂單」的出貨綁定（整批覆寫這張訂單的分配）
 * @param array $ships [ ['IS_id'=>int,'shipped_qty'=>int], ... ] 未勾選的不要放進來
 * @return array ['bound'=>int, 'seeded'=>int, 'total_qty'=>int]
 */
function sob_save_order_binds(PDO $pdo, $order_id, array $ships) {
    $order_id = intval($order_id);
    if ($order_id <= 0) throw new Exception('缺少訂單ID');

    // 整理勾選清單（同一張出貨單只留一筆，數量取後者）
    $picked = [];
    foreach ($ships as $s) {
        $sid = intval($s['IS_id'] ?? 0);
        $qty = intval($s['shipped_qty'] ?? 0);
        if ($sid > 0 && $qty > 0) $picked[$sid] = $qty;
    }

    // 這次動到的出貨單＝原本綁在這張訂單的 ∪ 這次勾選的
    $prev  = array_keys(sob_bound_map($pdo, $order_id));
    $touch = sob__ids(array_merge($prev, array_keys($picked)));

    // ① 先把「別張訂單」的舊式直接綁定補成分配列，免得下面重算主要訂單時被洗掉
    $seeded = sob_seed_direct($pdo, $touch);

    // ② 覆寫這張訂單的分配
    $pdo->prepare('DELETE FROM shipment_order_map WHERE Order_id = ?')->execute([$order_id]);
    if ($picked) {
        $ins = $pdo->prepare(
            'INSERT INTO shipment_order_map (IS_id, Order_id, shipped_qty, created_at) VALUES (?, ?, ?, NOW())'
        );
        foreach ($picked as $sid => $qty) $ins->execute([$sid, $order_id, $qty]);
    }

    // ③ 這次沒被勾選、卻還直接指著這張訂單的，要一起解掉（否則畫面上永遠解不掉）
    if ($picked) {
        $ph = sob__ph(array_keys($picked));
        $pdo->prepare(
            "UPDATE is_list SET Order_id = NULL WHERE Order_id = ? AND IS_id NOT IN ($ph)"
        )->execute(array_merge([$order_id], array_keys($picked)));
    } else {
        $pdo->prepare('UPDATE is_list SET Order_id = NULL WHERE Order_id = ?')->execute([$order_id]);
    }

    // ④ 重算主要訂單快取
    sob_sync_is_order($pdo, $touch);

    return ['bound' => count($picked), 'seeded' => $seeded, 'total_qty' => array_sum($picked)];
}

/**
 * 解除單一「出貨單 ↔ 訂單」綁定（兩種來源一起解，否則解不乾淨）
 * @return bool 有沒有真的解掉東西
 */
function sob_unbind(PDO $pdo, $is_id, $order_id) {
    $is_id    = intval($is_id);
    $order_id = intval($order_id);
    if ($is_id <= 0 || $order_id <= 0) throw new Exception('參數不足');

    $st = $pdo->prepare('DELETE FROM shipment_order_map WHERE IS_id = ? AND Order_id = ?');
    $st->execute([$is_id, $order_id]);
    $hit = $st->rowCount();

    $st2 = $pdo->prepare('UPDATE is_list SET Order_id = NULL WHERE IS_id = ? AND Order_id = ?');
    $st2->execute([$is_id, $order_id]);
    $hit += $st2->rowCount();

    // 還有別張訂單的分配就把主要訂單補回去
    sob_sync_is_order($pdo, [$is_id]);
    return $hit > 0;
}

}
