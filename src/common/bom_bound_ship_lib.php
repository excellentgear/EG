<?php
/**
 * 製令（BOM）↔ 已綁定出貨單：全站唯一實作（2026-11-11 使用者要求）
 * ------------------------------------------------------------------
 * 用途：
 *   ① BOM 總覽客戶欄列出「這張製令已綁定的出貨單」（✔ 簡式單號 ×數量）
 *   ② 最後一關「待出貨」的量已全數由綁定出貨單出掉 → 發單日欄不再顯示該關與「待出貨」
 *   ③ 良品數（sqty − 已結案確認的報廢量）全數由綁定出貨單出完 → 製令自動結案
 *
 * 「綁定」只認確實的綁定，不用料號／客戶去猜（猜的出貨不可以拿來自動結案）：
 *   (a) is_bom_map：快速出貨建立出貨單時寫入的「出貨↔製令」分配（數量＝shipped_qty）
 *   (b) 出貨綁定到「這張製令綁定的訂單」（shipment_order_map ∪ is_list.Order_id，
 *       口徑同 ship_order_bind_lib），且該出貨沒有任何 is_bom_map 分配、
 *       該訂單只綁這一張製令（綁多張製令時分不出是哪一張出的，不算）、
 *       出貨日不早於製令開立日（編號回推）。
 */

if (!function_exists('eg_bom_bound_ships')) {

    /** 製令編號 B-YYYMMDDnnn → Y-m-d（民國年） */
    function eg_bbs_bom_date(string $bom): string {
        if (!preg_match('~^B-(\d{3})(\d{2})(\d{2})~', $bom, $m)) return '';
        return sprintf('%04d-%s-%s', (int)$m[1] + 1911, $m[2], $m[3]);
    }

    /** IS1150921003 → 1150921-3（與訂單 OO1150914001 → 1150914-1 同一種簡式） */
    function eg_bbs_short_no(string $no): string {
        $no = trim($no);
        if (preg_match('~^[A-Za-z]{2}(\d{7})(\d{3})$~', $no, $m)) return $m[1] . '-' . (int)$m[2];
        return $no;
    }

    /**
     * @param string[] $boms
     * @return array bom => ['qty'=>int, 'ships'=>[['is_id','no','short','date','qty','src'], …]]
     */
    function eg_bom_bound_ships(PDO $db, array $boms): array {
        $boms = array_values(array_unique(array_filter(array_map('strval', $boms), fn($b) => trim($b) !== '')));
        if (!$boms) return [];
        $out = [];
        $add = function (string $bom, array $r, string $src) use (&$out) {
            if (!isset($out[$bom])) $out[$bom] = ['qty' => 0, 'ships' => []];
            $id = (int)$r['IS_id'];
            if (isset($out[$bom]['ships'][$id])) { $out[$bom]['ships'][$id]['qty'] += (int)$r['q']; }
            else $out[$bom]['ships'][$id] = ['is_id' => $id, 'no' => (string)$r['IS_number'],
                'short' => eg_bbs_short_no((string)$r['IS_number']), 'date' => (string)$r['d'], 'qty' => (int)$r['q'], 'src' => $src];
            $out[$bom]['qty'] += (int)$r['q'];
        };
        $ph = implode(',', array_fill(0, count($boms), '?'));

        // (a) 出貨↔製令直接分配
        try {
            $st = $db->prepare("SELECT m.bom, il.IS_id, il.IS_number, DATE_FORMAT(il.Order_date,'%Y-%m-%d') d, m.shipped_qty q
                                FROM is_bom_map m JOIN is_list il ON il.IS_id = m.IS_id
                                WHERE m.bom IN ($ph)");
            $st->execute($boms);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $add((string)$r['bom'], $r, 'bom');
        } catch (Throwable $e) { error_log('eg_bom_bound_ships(a): ' . $e->getMessage()); }

        // (b) 經由訂單綁定（該訂單只綁這一張製令）
        try {
            $st = $db->prepare("SELECT bom, Order_id FROM bom_order_process_map WHERE bom IN ($ph)
                                UNION SELECT bom, CAST(o_order_id AS UNSIGNED) FROM bom
                                WHERE bom IN ($ph) AND o_order_id REGEXP '^[0-9]+$'");
            $st->execute(array_merge($boms, $boms));
            $ordBom = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if ((int)$r['Order_id'] > 0) $ordBom[(int)$r['Order_id']][(string)$r['bom']] = 1;
            if ($ordBom) {
                $oids = array_keys($ordBom);
                $ph2  = implode(',', array_fill(0, count($oids), '?'));
                // 這些訂單在全站綁了幾張製令（不只畫面上這幾張）
                $cnt = [];
                $st = $db->prepare("SELECT Order_id, COUNT(DISTINCT bom) c FROM (
                                      SELECT Order_id, bom FROM bom_order_process_map WHERE Order_id IN ($ph2)
                                      UNION SELECT CAST(o_order_id AS UNSIGNED), bom FROM bom WHERE o_order_id IN ($ph2)
                                    ) x GROUP BY Order_id");
                $st->execute(array_merge($oids, array_map('strval', $oids)));
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $cnt[(int)$r['Order_id']] = (int)$r['c'];
                $single = array_values(array_filter($oids, fn($o) => ($cnt[$o] ?? 0) === 1));
                if ($single) {
                    $ph3 = implode(',', array_fill(0, count($single), '?'));
                    $st = $db->prepare("
                        SELECT x.Order_id, il.IS_id, il.IS_number, DATE_FORMAT(il.Order_date,'%Y-%m-%d') d, x.q
                        FROM (
                            SELECT som.Order_id, som.IS_id, som.shipped_qty q FROM shipment_order_map som WHERE som.Order_id IN ($ph3)
                            UNION ALL
                            SELECT il2.Order_id, il2.IS_id, il2.Qty q FROM is_list il2
                            WHERE il2.Order_id IN ($ph3)
                              AND NOT EXISTS (SELECT 1 FROM shipment_order_map s2 WHERE s2.IS_id = il2.IS_id)
                        ) x
                        JOIN is_list il ON il.IS_id = x.IS_id
                        WHERE NOT EXISTS (SELECT 1 FROM is_bom_map bm WHERE bm.IS_id = x.IS_id)");
                    $st->execute(array_merge($single, $single));
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $bom = (string)array_key_first($ordBom[(int)$r['Order_id']]);
                        $bd  = eg_bbs_bom_date($bom);
                        if ($bd !== '' && $r['d'] < $bd) continue;   // 出在製令開立之前＝不是這張製令出的
                        $add($bom, $r, 'order');
                    }
                }
            }
        } catch (Throwable $e) { error_log('eg_bom_bound_ships(b): ' . $e->getMessage()); }

        foreach ($out as &$o) {
            $o['ships'] = array_values($o['ships']);
            usort($o['ships'], fn($a, $b) => strcmp($b['date'], $a['date']) ?: ($b['is_id'] <=> $a['is_id']));
        }
        unset($o);
        return $out;
    }

    /**
     * 良品數全數由綁定出貨單出完的製令 → 自動結案（processing_state='1'），留 bom_operation_log。
     * @param array $bound eg_bom_bound_ships() 的結果（呼叫端已算過就傳進來，不重算）
     * @return string[] 這次結案的製令
     */
    function eg_bom_auto_close_shipped(PDO $db, array $boms, ?array $bound = null, $operatorId = null): array {
        $boms = array_values(array_unique(array_filter(array_map('strval', $boms), fn($b) => trim($b) !== '')));
        if (!$boms) return [];
        if ($bound === null) $bound = eg_bom_bound_ships($db, $boms);
        $cand = array_values(array_filter($boms, fn($b) => !empty($bound[$b]['qty'])));
        if (!$cand) return [];
        $closed = [];
        try {
            $ph = implode(',', array_fill(0, count($cand), '?'));
            $st = $db->prepare("SELECT bom, sqty FROM bom WHERE bom IN ($ph) AND (processing_state IS NULL OR processing_state <> '1')");
            $st->execute($cand);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) return [];
            require_once __DIR__ . '/qa_abnormal_lib.php';
            $scrapMap = function_exists('qab_bom_scrap_rows') ? qab_bom_scrap_rows($db, array_column($rows, 'bom')) : [];
            $up  = $db->prepare("UPDATE bom SET processing_state='1', bom_ing_id='1', closed_by=?, closed_at=NOW(),
                                 Modified_At=NOW(), Modified_By=? WHERE bom=? AND (processing_state IS NULL OR processing_state <> '1')");
            $log = $db->prepare("INSERT INTO bom_operation_log (bom, operation_type, operator_id, details_json) VALUES (?, 'auto_close_shipped', ?, ?)");
            foreach ($rows as $r) {
                $bom   = (string)$r['bom'];
                $sqty  = (int)$r['sqty'];
                $scrap = function_exists('qab_bom_scrap_sum_rows') ? (int)qab_bom_scrap_sum_rows($scrapMap[$bom] ?? [], null) : 0;
                $good  = max(0, $sqty - $scrap);
                $ship  = (int)$bound[$bom]['qty'];
                if ($good <= 0 || $ship < $good) continue;
                $up->execute([$operatorId, $operatorId === null ? 'system_auto_close' : (string)$operatorId, $bom]);
                if ($up->rowCount() < 1) continue;
                $closed[] = $bom;
                try {
                    $log->execute([$bom, $operatorId, json_encode([
                        '操作' => '良品全數出貨自動結案', '製令數量' => $sqty, '確認報廢' => $scrap, '良品數' => $good,
                        '已綁定出貨' => $ship, '出貨單' => array_map(fn($s) => $s['no'] . '×' . $s['qty'], $bound[$bom]['ships']),
                    ], JSON_UNESCAPED_UNICODE)]);
                } catch (Throwable $e) { error_log('auto_close log: ' . $e->getMessage()); }
            }
        } catch (Throwable $e) { error_log('eg_bom_auto_close_shipped: ' . $e->getMessage()); }
        return $closed;
    }
}
