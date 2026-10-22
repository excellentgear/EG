<?php
/**
 * order_price_autofill_lib.php — 訂單「自動補單價」候選判定與套用（唯一實作，2026-10-22）
 *
 * 只處理 order_track 裡「單價空白」的明細，絕不覆蓋既有單價。
 * 類別（cat）：
 *   R1  規則1：補資料暫存區同訂單編號下 (料號,數量) 組合與筆數，與訂單追蹤現有完全相同 → 可直接補
 *   R2  規則2：不符規則1，但同訂單編號＋同料號＋同數量＋製程完全相同（製程不可空白）→ 可補
 *   DONE 已補過單價(有 autofill_log)，但補資料暫存列仍是「待轉入」→ 只需把暫存列認定為已轉入
 *   A   建議：ERP 訂單資料(order_list)同單號＋料號＋數量，單價唯一且製程一致
 *   B   建議：ERP 單價唯一，但訂單製程空白／不一致（需人工抽查）
 *   C   建議：ERP 有多個單價（僅顯示，不可批次）
 *   D   建議：查無 ERP 資料（僅顯示，不可批次）
 * 可批次轉價格＝R1 R2 DONE A B。套用時一律在後端重新判定，不採信前端送來的價格。
 * 補入者：Order_ps 加註「【自動補單價】」、寫 order_price_autofill_log；不動 Modified_At／Modified_By。
 */

const OPF_MARK = '【自動補單價】';
const OPF_APPLY_CATS = ['R1', 'R2', 'DONE', 'A', 'B'];

function opf_ensure_schema(PDO $db): void {
    static $done = false;
    if ($done) return; $done = true;
    if ($db->inTransaction()) return;
    $db->exec("CREATE TABLE IF NOT EXISTS order_price_autofill_log (
      id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, order_oo VARCHAR(40) NULL, rule_code VARCHAR(10) NOT NULL,
      old_price DECIMAL(15,4) NULL, new_price DECIMAL(15,4) NOT NULL, stage_id INT NULL, created_at DATETIME NOT NULL,
      KEY idx_order (order_id)) CHARACTER SET utf8mb4 COMMENT='自動補單價紀錄'");
}

function opf_norm($s): string { return preg_replace('/\s+/u', '', trim((string)$s)); }
function opf_pk($d, $p): string { return $d ? 'd' . $d : 'p' . opf_norm($p); }
function opf_empty_price($v): bool { return !((float)$v > 0); }

/** 回傳全部候選列：['order_id','cat','price','note','stage_ids'=>[...],'src'=>[來源明細...]]，另附 'order'=>訂單列 */
function opf_candidates(PDO $db): array {
    opf_ensure_schema($db);
    $cols = "Order_id,Order_oo,Order_date,Delivery_date,Client_name,Client_name_ID,d_id,d_id_ID,Qty,Processing_items,unit_price,Order_ps,Order_status,quote_no";
    $orders = $db->query("SELECT $cols FROM order_track WHERE (Order_status IS NULL OR Order_status<>6) AND (unit_price IS NULL OR unit_price=0)")
                 ->fetchAll(PDO::FETCH_ASSOC);
    if (!$orders) return [];
    $needByOO = [];
    foreach ($orders as $o) $needByOO[$o['Order_oo']][] = $o;

    $res = []; $handled = [];   // order_id => true
    // ---- 規則 1／2（補資料暫存區 待轉入／已略過 的訂單編號）----
    $oos = $db->query("SELECT DISTINCT order_oo FROM order_erp_import_stage WHERE state IN ('pending','skipped')")->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_chunk($oos, 400) as $ch) {
        $ph = implode(',', array_fill(0, count($ch), '?'));
        $s = $db->prepare("SELECT id,order_oo,part_no,d_id_id,spec_text,process_text,qty,unit_price,state,order_date FROM order_erp_import_stage WHERE order_oo IN ($ph) ORDER BY id");
        $s->execute($ch);
        $st = []; foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $st[$r['order_oo']][] = $r;
        $koAll = [];
        $q2 = $db->prepare("SELECT Order_oo,d_id,d_id_ID,Qty FROM order_track WHERE Order_oo IN ($ph) AND (Order_status IS NULL OR Order_status<>6)");
        $q2->execute($ch);
        foreach ($q2->fetchAll(PDO::FETCH_ASSOC) as $r) $koAll[$r['Order_oo']][] = opf_pk($r['d_id_ID'], $r['d_id']) . '|' . (int)$r['Qty'];
        foreach ($ch as $oo) {
            if (empty($needByOO[$oo])) continue;
            $S = $st[$oo] ?? [];
            $ks = array_map(fn($r) => opf_pk($r['d_id_id'], $r['part_no']) . '|' . (int)$r['qty'], $S); sort($ks);
            $ko = $koAll[$oo] ?? [];
            sort($ko);
            $rule1 = ($ks === $ko);
            $usedStage = [];
            foreach ($needByOO[$oo] as $x) {
                $kx = opf_pk($x['d_id_ID'], $x['d_id']) . '|' . (int)$x['Qty'];
                $px = opf_norm($x['Processing_items']);
                if (!$rule1 && $px === '') continue;
                $cand = [];
                foreach ($S as $r) {
                    if (opf_pk($r['d_id_id'], $r['part_no']) . '|' . (int)$r['qty'] !== $kx || !((float)$r['unit_price'] > 0)) continue;
                    if (!$rule1 && opf_norm($r['process_text']) !== $px) continue;
                    $cand[] = $r;
                }
                $prices = array_unique(array_map(fn($r) => round((float)$r['unit_price'], 4), $cand));
                if (count($prices) !== 1) continue;
                $pick = null;   // 一個訂單列對應一筆暫存列（優先挑還沒被其他訂單列用掉、且待轉入的）
                foreach ($cand as $r) { if (!isset($usedStage[$r['id']]) && $r['state'] === 'pending') { $pick = $r; break; } }
                if (!$pick) foreach ($cand as $r) { if (!isset($usedStage[$r['id']])) { $pick = $r; break; } }
                if ($pick) $usedStage[$pick['id']] = true;
                $res[] = ['order_id' => (int)$x['Order_id'], 'cat' => $rule1 ? 'R1' : 'R2', 'price' => $prices[0],
                          'stage_ids' => $pick ? [(int)$pick['id']] : [], 'src' => $pick ? [$pick] : [$cand[0]], 'order' => $x,
                          'note' => $rule1 ? '訂單編號下料號與數量組合完全相同' : '同單號＋料號＋數量＋製程完全相同'];
                $handled[$x['Order_id']] = true;
            }
        }
    }
    // ---- DONE：已補過單價，但補資料暫存列仍待轉入 ----
    $dn = $db->query("SELECT l.order_id,l.new_price,l.rule_code,s.id sid,s.order_oo,s.part_no,s.spec_text,s.process_text,s.qty,s.unit_price,s.state,s.order_date,
                             o.Order_id oid,o.Order_oo,o.Order_date od,o.Delivery_date,o.Client_name,o.Client_name_ID,o.d_id,o.d_id_ID,o.Qty,o.Processing_items,o.unit_price ou,o.Order_ps,o.Order_status,o.quote_no
                      FROM order_price_autofill_log l JOIN order_erp_import_stage s ON s.id=l.stage_id AND s.state='pending'
                      JOIN order_track o ON o.Order_id=l.order_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dn as $r) {
        $ord = ['Order_id' => $r['oid'], 'Order_oo' => $r['Order_oo'], 'Order_date' => $r['od'], 'Delivery_date' => $r['Delivery_date'], 'Client_name' => $r['Client_name'],
                'Client_name_ID' => $r['Client_name_ID'], 'd_id' => $r['d_id'], 'd_id_ID' => $r['d_id_ID'], 'Qty' => $r['Qty'], 'Processing_items' => $r['Processing_items'],
                'unit_price' => $r['ou'], 'Order_ps' => $r['Order_ps'], 'Order_status' => $r['Order_status'], 'quote_no' => $r['quote_no']];
        $src = ['id' => $r['sid'], 'order_oo' => $r['order_oo'], 'part_no' => $r['part_no'], 'spec_text' => $r['spec_text'], 'process_text' => $r['process_text'], 'qty' => $r['qty'], 'unit_price' => $r['unit_price'], 'state' => $r['state'], 'order_date' => $r['order_date']];
        $res[] = ['order_id' => (int)$r['order_id'], 'cat' => 'DONE', 'price' => (float)$r['new_price'], 'stage_ids' => [(int)$r['sid']], 'src' => [$src], 'order' => $ord,
                  'note' => '單價已補入（' . $r['rule_code'] . '），補資料暫存列尚未結案'];
    }
    // ---- 建議：ERP 訂單資料 ----
    $L = [];
    foreach ($db->query("SELECT Order_oo,d_id,Qty,Specification,unit_price,Order_date FROM order_list WHERE unit_price>0") as $r)
        $L[$r['Order_oo'] . '|' . $r['d_id'] . '|' . (int)$r['Qty']][] = $r;
    foreach ($orders as $o) {
        if (isset($handled[$o['Order_id']])) continue;
        $cand = $L[$o['Order_oo'] . '|' . $o['d_id'] . '|' . (int)$o['Qty']] ?? [];
        $px = opf_norm($o['Processing_items']);
        $pr = array_unique(array_map(fn($r) => round((float)$r['unit_price'], 4), $cand));
        $src = array_map(fn($r) => ['order_oo' => $r['Order_oo'], 'part_no' => $r['d_id'], 'qty' => $r['Qty'], 'spec_text' => $r['Specification'], 'unit_price' => $r['unit_price'], 'order_date' => $r['Order_date'], 'state' => 'ERP'], $cand);
        if (!$cand)            { $cat = 'D'; $price = null; $note = '查無 ERP 資料：請業務依報價補單價或綁定報價單'; }
        elseif (count($pr) > 1) { $cat = 'C'; $price = null; $note = 'ERP 有多個單價（' . implode(' / ', $pr) . '）：請人工確認'; }
        else {
            $m = false;
            if ($px !== '') foreach ($cand as $r) if (mb_substr(opf_norm($r['Specification']), -mb_strlen($px)) === $px) { $m = true; break; }
            $cat = $m ? 'A' : 'B'; $price = (float)$pr[0];
            $note = $m ? 'ERP 單價唯一且製程一致' : 'ERP 單價唯一，但訂單製程' . ($px === '' ? '空白' : '與 ERP 不一致') . '，請抽查';
        }
        $res[] = ['order_id' => (int)$o['Order_id'], 'cat' => $cat, 'price' => $price, 'stage_ids' => [], 'src' => $src, 'order' => $o, 'note' => $note];
    }
    return $res;
}

/**
 * 套用：只接受 $orderIds 與 $cat，價格／暫存列一律重新判定。回傳 ['filled'=>n,'staged'=>n,'skipped'=>n,'msgs'=>[]]
 */
function opf_apply(PDO $db, array $orderIds, string $cat, int $uid): array {
    if (!in_array($cat, OPF_APPLY_CATS, true)) return ['filled' => 0, 'staged' => 0, 'skipped' => count($orderIds), 'msgs' => ['此類別不可批次轉價格']];
    $want = array_flip(array_map('intval', $orderIds));
    $cands = array_filter(opf_candidates($db), fn($c) => $c['cat'] === $cat && isset($want[$c['order_id']]));
    $filled = $staged = 0; $msgs = [];
    $db->beginTransaction();
    try {
        $up  = $db->prepare("UPDATE order_track SET unit_price=?, Order_ps=CONCAT(IFNULL(Order_ps,''), IF(IFNULL(Order_ps,'') LIKE ?,'',IF(IFNULL(Order_ps,'')='','',' ')), IF(IFNULL(Order_ps,'') LIKE ?,'','" . OPF_MARK . "')) WHERE Order_id=? AND (unit_price IS NULL OR unit_price=0)");
        $lg  = $db->prepare("INSERT INTO order_price_autofill_log (order_id,order_oo,rule_code,old_price,new_price,stage_id,created_at) VALUES (?,?,?,?,?,?,NOW())");
        $stg = $db->prepare("UPDATE order_erp_import_stage SET state='converted', converted_order_id=?, converted_by=?, converted_at=NOW(), match_note='訂單已存在，僅補單價（自動補單價）' WHERE id=? AND state='pending'");
        $like = '%' . OPF_MARK . '%';
        foreach ($cands as $c) {
            if ($cat !== 'DONE') {
                $up->execute([$c['price'], $like, $like, $c['order_id']]);
                if (!$up->rowCount()) { $msgs[] = '訂單 ' . $c['order_id'] . ' 單價已有值，略過'; continue; }
                $lg->execute([$c['order_id'], $c['order']['Order_oo'], $cat, null, $c['price'], $c['stage_ids'][0] ?? null]);
                $filled++;
            }
            foreach ($c['stage_ids'] as $sid) { $stg->execute([$c['order_id'], $uid, $sid]); $staged += $stg->rowCount(); }
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return ['filled' => $filled, 'staged' => $staged, 'skipped' => count($want) - count($cands), 'msgs' => $msgs];
}
