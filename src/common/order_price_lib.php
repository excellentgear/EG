<?php
/**
 * 訂單「顯示單價」判定 —— 全站唯一實作（2026-09-15 建立）
 *
 * 背景：訂單清單與編輯跳窗原本各有一套「單價要顯示什麼」的規則，而跳窗那套
 * 會在 order_track.unit_price 為空時，用「料號字串 LIKE 比對任何一張報價單、
 * 取最新那張」去猜一個價格。因為那個值是直接塞進表單的 unit_price 欄位，
 * 使用者只要進跳窗改個交期再按「確認更新」，猜出來的價格就會被永久寫進
 * order_track（下游 acc_lib／ppr_lib／kpi_lib 全部跟著錯）。實測 7,448 筆
 * 無單價訂單中有 6,536 筆會被塞入猜測價，其中 50 筆來自別家客戶、243 筆
 * 來自別的料號、2,092 筆來自比接單日還晚的報價單。
 *
 * 因此判定收斂成這一支，規則只有一條：
 *   **只有訂單「真的綁定了報價單」時才推導單價，絕不用料號去猜。**
 *
 * 呼叫端：views/Sales/NewOrder_Track.php 的清單（批次）與 get_order_detail（單筆）。
 * 要改規則請只改這裡，不要在頁面裡再寫第二份。
 */

if (!function_exists('eg_order_price_pick_item')) {
    /**
     * 從「同一張報價單的項目清單」中挑出對應該料號的那一項。
     * @param array  $items 該報價單的項目，呼叫端須已依 item_id DESC 排序
     * @param string $dId   訂單上的料號文字
     */
    function eg_order_price_pick_item(array $items, string $dId): ?array
    {
        if ($dId === '') return null;
        foreach ($items as $qi) {
            if (floatval($qi['unit_price'] ?? 0) <= 0) continue;
            if (strpos((string)($qi['product_id'] ?? ''), $dId) !== false) return $qi;
        }
        return null;
    }
}

if (!function_exists('eg_order_price_fill')) {
    /**
     * 批次填入 $orders 每一列的 display_unit_price（沒有可推導的價格＝null）。
     * 整批只跑 1~2 支查詢，不做 correlated subquery。
     */
    function eg_order_price_fill(PDO $db, array &$orders): void
    {
        if (empty($orders)) return;

        // 先收集「需要回查」的綁定鍵：只有本身沒有價格的訂單才需要
        $needQuoteNos = [];
        $needItemIds  = [];
        foreach ($orders as $o) {
            if (floatval($o['unit_price'] ?? 0) > 0) continue;
            $iid = intval($o['quote_item_id'] ?? 0);
            if ($iid > 0)                       $needItemIds[]  = $iid;
            if (!empty($o['quote_no']))         $needQuoteNos[] = $o['quote_no'];
        }

        // 綁定到「報價項目」的精確查詢
        $itemMap = [];
        $needItemIds = array_values(array_unique($needItemIds));
        if (!empty($needItemIds)) {
            $ph = implode(',', array_fill(0, count($needItemIds), '?'));
            $st = $db->prepare("SELECT item_id, unit_price FROM quotation_item WHERE item_id IN ($ph)");
            $st->execute($needItemIds);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $itemMap[(int)$r['item_id']] = $r;
        }

        // 綁定到「報價單號」的查詢（同一張單可能多個項目，依 item_id DESC 供 pick 挑）
        $quoteMap = [];
        $needQuoteNos = array_values(array_unique($needQuoteNos));
        if (!empty($needQuoteNos)) {
            $ph = implode(',', array_fill(0, count($needQuoteNos), '?'));
            $st = $db->prepare("SELECT ql.quote_no, qi.product_id, qi.unit_price, qi.item_id
                                FROM quotation_list ql
                                JOIN quotation_item qi ON ql.quote_id = qi.quote_id
                                WHERE ql.quote_no IN ($ph) AND qi.unit_price > 0
                                ORDER BY qi.item_id DESC");
            $st->execute($needQuoteNos);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $qi) $quoteMap[$qi['quote_no']][] = $qi;
        }

        foreach ($orders as &$order) {
            $up = floatval($order['unit_price'] ?? 0);
            if ($up > 0) { $order['display_unit_price'] = $up; continue; }

            $order['display_unit_price'] = null;

            // 1) 綁定報價項目＝最精確，直接用
            $iid = intval($order['quote_item_id'] ?? 0);
            if ($iid > 0 && isset($itemMap[$iid]) && floatval($itemMap[$iid]['unit_price']) > 0) {
                $order['display_unit_price'] = floatval($itemMap[$iid]['unit_price']);
                continue;
            }

            // 2) 綁定報價單號＝在該張單內找這個料號
            $qno = (string)($order['quote_no'] ?? '');
            $did = (string)($order['d_id'] ?? '');
            if ($qno !== '' && isset($quoteMap[$qno])) {
                $hit = eg_order_price_pick_item($quoteMap[$qno], $did);
                if ($hit) $order['display_unit_price'] = floatval($hit['unit_price']);
            }
            // 3) 沒有綁定＝留 null。**不可以用料號去猜**（見檔頭說明）
        }
        unset($order);
    }
}

if (!function_exists('eg_order_price_resolve')) {
    /**
     * 單筆版本：回傳 ['price'=>string|'', 'source'=>'order_track'|'quotation'|'none']
     * 與批次版走同一套規則（內部即呼叫批次版，避免兩邊走鐘）。
     */
    function eg_order_price_resolve(PDO $db, array $order): array
    {
        $raw = $order['unit_price'] ?? '';
        if ($raw !== '' && $raw !== null && floatval($raw) > 0) {
            return ['price' => $raw, 'source' => 'order_track'];
        }
        $rows = [$order];
        eg_order_price_fill($db, $rows);
        $p = $rows[0]['display_unit_price'] ?? null;
        if ($p !== null && floatval($p) > 0) return ['price' => $p, 'source' => 'quotation'];
        return ['price' => '', 'source' => 'none'];
    }
}
