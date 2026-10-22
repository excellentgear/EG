<?php
/**
 * order_price_autofill_lib.php — 訂單「自動補單價」候選判定與套用（唯一實作，2026-10-22）
 *
 * 只處理 order_track 裡「單價空白」的明細，絕不覆蓋既有單價。
 * 類別（cat）：
 *   R1  規則1：補資料暫存區同訂單編號下 (料號,數量) 組合與筆數，與訂單追蹤現有完全相同 → 可直接補
 *   R2  規則2：不符規則1，但同訂單編號＋同料號＋同數量＋製程完全相同（製程不可空白）→ 可補
 *   PROC 已補過單價、但訂單製程仍空白且 ERP 有製程 → 只補製程（補早期轉入時沒帶到製程的）
 *   PROCM 同上但無法自動判定（多種製程／尾段不像製程）→ 僅顯示，請人工補
 *   DONE 已補過單價(有 autofill_log)，但補資料暫存列仍是「待轉入」→ 只需把暫存列認定為已轉入
 *   A   建議：ERP 訂單資料(order_list)同單號＋料號＋數量，單價唯一且製程一致
 *   B   建議：ERP 單價唯一，但訂單製程空白／不一致（需人工抽查）
 *   C   建議：ERP 有多個單價（僅顯示，不可批次）
 *   D   建議：查無 ERP 資料（僅顯示，不可批次）
 * 可批次轉價格＝R1 R2 DONE A B。套用時一律在後端重新判定，不採信前端送來的價格。
 * 補入者：Order_ps 加註「【自動補單價】」、寫 order_price_autofill_log；不動 Modified_At／Modified_By。
 */

const OPF_MARK = '【自動補單價】';
const OPF_APPLY_CATS = ['R1', 'R2', 'DONE', 'A', 'B', 'PROC', 'PROCM'];

function opf_ensure_schema(PDO $db): void {
    static $done = false;
    if ($done) return; $done = true;
    if ($db->inTransaction()) return;
    $db->exec("CREATE TABLE IF NOT EXISTS order_price_autofill_log (
      id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, order_oo VARCHAR(40) NULL, rule_code VARCHAR(10) NOT NULL,
      old_price DECIMAL(15,4) NULL, new_price DECIMAL(15,4) NOT NULL, stage_id INT NULL, created_at DATETIME NOT NULL,
      KEY idx_order (order_id)) CHARACTER SET utf8mb4 COMMENT='自動補單價紀錄'");
    foreach (['old_process', 'new_process'] as $col) {
        if (!$db->query("SHOW COLUMNS FROM order_price_autofill_log LIKE '$col'")->fetchColumn())
            $db->exec("ALTER TABLE order_price_autofill_log ADD COLUMN $col VARCHAR(255) NULL COMMENT '同時補製程時的舊/新值'");
    }
}

function opf_norm($s): string { return preg_replace('/\s+/u', '', trim((string)$s)); }
function opf_pk($d, $p): string { return $d ? 'd' . $d : 'p' . opf_norm($p); }
function opf_empty_price($v): bool { return !((float)$v > 0); }

/** ERP 品名規格＝品名＋規格＋空白＋製程，製程取最後一個空白之後；有獨立製程欄（暫存區）就用它 */
function opf_erp_proc(array $src): string {
    if (isset($src['process_text']) && trim((string)$src['process_text']) !== '') return (string)$src['process_text'];
    $t = trim((string)($src['spec_text'] ?? ''));
    $i = strrpos($t, ' ');
    return $i === false ? $t : substr($t, $i + 1);
}
/** 製程同義正規化：去掉備註（「-單價候補」「-特急件」「有設定報告」）、帶→代、「全製／代料完成／代料到成品…」→ FULL */
function opf_proc_canon(string $s): string {
    $n = opf_norm($s);
    $n = preg_replace('/[-－—–].*$/u', '', $n);          // 破折號之後多半是備註
    $n = str_replace(['有設定報告', '帶'], ['', '代'], $n);
    $n = str_replace(['到', '至'], '', $n);
    if (preg_match('/^(代料)?(全製|完成|成品|成品完成|全製完成)$/u', $n)) return 'FULL';
    return $n;
}
/** 訂單製程空白、而 ERP 有製程時，回傳可補入的製程文字（看起來像規格/型號、含數字或過長者不補），否則 null */
function opf_proc_fillable(string $orderProc, string $erpProc): ?string {
    $e = trim($erpProc);
    if (opf_norm($orderProc) !== '' || $e === '') return null;
    $e = preg_replace('/^[0-9A-Za-z]+(?=[\x{4e00}-\x{9fff}])/u', '', $e);   // 開頭的治具／型號代碼（例：2GG1雷刻 → 雷刻）
    if ($e === '') return null;
    if (preg_match('/\d/u', $e) || mb_strlen($e) > 20) return null;
    return $e;
}
/** 回傳 ok（一致/包含）｜syn（同義）｜bad（不一致）｜na（任一邊空白無法比對） */
function opf_proc_state(string $orderProc, string $erpProc): string {
    $a = opf_norm($orderProc); $b = opf_norm($erpProc);
    if ($a === '' || $b === '') return 'na';
    if ($a === $b || mb_substr($b, -mb_strlen($a)) === $a || mb_substr($a, -mb_strlen($b)) === $b) return 'ok';
    $ca = opf_proc_canon($a); $cb = opf_proc_canon($b);
    if ($ca === $cb && $ca !== '') return 'syn';
    // 其中一邊完整包含另一邊（例：「成型磨齒研有設定報告」含「齒研」）→ 視為同義，供人快速確認
    if ($ca !== '' && $cb !== '' && min(mb_strlen($ca), mb_strlen($cb)) >= 2 && (mb_strpos($ca, $cb) !== false || mb_strpos($cb, $ca) !== false)) return 'syn';
    return 'bad';
}


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
    // ---- PROC：已補過單價，但訂單製程仍空白、ERP 有製程 → 只補製程 ----
    $pr = $db->query("SELECT DISTINCT l.order_id, l.stage_id, o.Order_id oid,o.Order_oo,o.Order_date,o.Delivery_date,o.Client_name,o.Client_name_ID,o.d_id,o.d_id_ID,o.Qty,o.Processing_items,o.unit_price,o.Order_ps,o.Order_status,o.quote_no
                      FROM order_price_autofill_log l JOIN order_track o ON o.Order_id=l.order_id
                      WHERE (o.Processing_items IS NULL OR TRIM(o.Processing_items)='') AND l.rule_code<>'PROC'")->fetchAll(PDO::FETCH_ASSOC);
    if ($pr) {
        $L2 = [];
        foreach ($db->query("SELECT Order_oo,d_id,Qty,Specification,Order_date FROM order_list") as $r) $L2[$r['Order_oo'] . '|' . $r['d_id'] . '|' . (int)$r['Qty']][] = $r;
        $stIds = array_values(array_unique(array_filter(array_column($pr, 'stage_id'))));
        $stMap = [];
        foreach (array_chunk($stIds, 500) as $ch) {
            $q = $db->prepare("SELECT id,order_oo,part_no,spec_text,process_text,qty,unit_price,state,order_date FROM order_erp_import_stage WHERE id IN (" . implode(',', array_fill(0, count($ch), '?')) . ")");
            $q->execute($ch); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $stMap[$r['id']] = $r;
        }
        $seen = [];
        foreach ($pr as $r) {
            if (isset($seen[$r['oid']])) continue;
            $ord = ['Order_id' => $r['oid'], 'Order_oo' => $r['Order_oo'], 'Order_date' => $r['Order_date'], 'Delivery_date' => $r['Delivery_date'], 'Client_name' => $r['Client_name'],
                    'Client_name_ID' => $r['Client_name_ID'], 'd_id' => $r['d_id'], 'd_id_ID' => $r['d_id_ID'], 'Qty' => $r['Qty'], 'Processing_items' => $r['Processing_items'],
                    'unit_price' => $r['unit_price'], 'Order_ps' => $r['Order_ps'], 'Order_status' => $r['Order_status'], 'quote_no' => $r['quote_no']];
            $src = [];
            if (!empty($r['stage_id']) && isset($stMap[$r['stage_id']])) $src[] = $stMap[$r['stage_id']];
            foreach ($L2[$r['Order_oo'] . '|' . $r['d_id'] . '|' . (int)$r['Qty']] ?? [] as $e)
                $src[] = ['order_oo' => $e['Order_oo'], 'part_no' => $e['d_id'], 'qty' => $e['Qty'], 'spec_text' => $e['Specification'], 'unit_price' => null, 'order_date' => $e['Order_date'], 'state' => 'ERP'];
            $procs = [];
            foreach ($src as $e) { $f = opf_proc_fillable('', opf_erp_proc($e)); if ($f !== null) $procs[opf_norm($f)] = $f; }
            if (count($procs) !== 1) {      // 沒有、或有多種不同製程 → 不自動補，列出供人工判斷
                $seen[$r['oid']] = true;
                $res[] = ['order_id' => (int)$r['oid'], 'cat' => 'PROCM', 'price' => null, 'stage_ids' => [], 'src' => $src, 'order' => $ord,
                          'proc_options' => array_values(array_unique(array_filter(array_map(fn($e) => trim(opf_erp_proc($e)), $src), fn($x) => $x !== ''))),
                          'note' => $procs ? '同單號＋料號＋數量的 ERP 明細有多種製程（' . implode(' / ', $procs) . '），請人工判斷後到訂單追蹤手動補製程'
                                           : 'ERP 品名規格最後一段看起來不像製程，請人工判斷後到訂單追蹤手動補製程'];
                continue;
            }
            $seen[$r['oid']] = true;
            $res[] = ['order_id' => (int)$r['oid'], 'cat' => 'PROC', 'price' => null, 'stage_ids' => [], 'src' => $src, 'order' => $ord, 'proc_fill' => reset($procs),
                      'note' => '單價已補入，訂單製程仍空白：補入 ERP 製程「' . reset($procs) . '」'];
        }
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
            $m = false; $bestSt = 'bad';
            foreach ($cand as $r) { $st0 = opf_proc_state((string)$o['Processing_items'], opf_erp_proc(['spec_text' => $r['Specification']]));
                if ($st0 === 'ok' || $st0 === 'syn') { $m = true; $bestSt = $st0; break; } if ($st0 === 'na') $bestSt = 'na'; }
            $cat = $m ? 'A' : 'B'; $price = (float)$pr[0];
            $note = $m ? ($bestSt === 'syn' ? 'ERP 單價唯一，製程用語不同但意思相同（全製＝代料完成…）' : 'ERP 單價唯一且製程一致') : 'ERP 單價唯一，但訂單製程' . ($px === '' ? '空白' : '與 ERP 不一致') . '，請抽查';
        }
        $res[] = ['order_id' => (int)$o['Order_id'], 'cat' => $cat, 'price' => $price, 'stage_ids' => [], 'src' => $src, 'order' => $o, 'note' => $note];
    }
    foreach ($res as &$rr) {
        $erp = opf_erp_proc($rr['src'][0] ?? []);
        $rr['erp_proc'] = $erp; if (!array_key_exists('proc_fill', $rr)) $rr['proc_fill'] = opf_proc_fillable((string)$rr['order']['Processing_items'], $erp); $rr['proc_state'] = opf_proc_state((string)$rr['order']['Processing_items'], $erp);
    }
    unset($rr);
    return $res;
}

/**
 * 套用：只接受 $orderIds 與 $cat，價格／暫存列一律重新判定。回傳 ['filled'=>n,'staged'=>n,'skipped'=>n,'msgs'=>[]]
 */
function opf_apply(PDO $db, array $orderIds, string $cat, int $uid, bool $fillProc = false, array $procMap = []): array {
    if (!in_array($cat, OPF_APPLY_CATS, true)) return ['filled' => 0, 'staged' => 0, 'skipped' => count($orderIds), 'msgs' => ['此類別不可批次轉價格']];
    $want = array_flip(array_map('intval', $orderIds));
    $cands = array_filter(opf_candidates($db), fn($c) => $c['cat'] === $cat && isset($want[$c['order_id']]));
    $filled = $staged = $procFilled = 0; $msgs = [];
    $db->beginTransaction();
    try {
        $up  = $db->prepare("UPDATE order_track SET unit_price=?, Order_ps=CONCAT(IFNULL(Order_ps,''), IF(IFNULL(Order_ps,'') LIKE ?,'',IF(IFNULL(Order_ps,'')='','',' ')), IF(IFNULL(Order_ps,'') LIKE ?,'','" . OPF_MARK . "')) WHERE Order_id=? AND (unit_price IS NULL OR unit_price=0)");
        $lg  = $db->prepare("INSERT INTO order_price_autofill_log (order_id,order_oo,rule_code,old_price,new_price,stage_id,old_process,new_process,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())");
        $upp = $db->prepare("UPDATE order_track SET Processing_items=?, Order_ps=CONCAT(IFNULL(Order_ps,''), IF(IFNULL(Order_ps,'')='','',' '), '【自動補製程】') WHERE Order_id=? AND (Processing_items IS NULL OR TRIM(Processing_items)='')");
        $stg = $db->prepare("UPDATE order_erp_import_stage SET state='skipped', match_status='exact', existing_order_id=?, match_note='訂單內已有相同明細（僅補單價），不需轉入' WHERE id=? AND state='pending'");
        $like = '%' . OPF_MARK . '%';
        foreach ($cands as $c) {
            if ($cat === 'PROC' || $cat === 'PROCM') {
                $pv = $cat === 'PROCM' ? trim((string)($procMap[$c['order_id']] ?? '')) : (string)($c['proc_fill'] ?? '');
                if ($pv === '' || mb_strlen($pv) > 100) { $msgs[] = '訂單 ' . $c['order_id'] . ' 未指定製程，略過'; continue; }
                $upp->execute([$pv, $c['order_id']]);
                if ($upp->rowCount()) {
                    $lg->execute([$c['order_id'], $c['order']['Order_oo'], $cat, null, (float)$c['order']['unit_price'], null, '', $pv]);
                    $procFilled++;
                }
                continue;
            }
            if ($cat !== 'DONE') {
                $up->execute([$c['price'], $like, $like, $c['order_id']]);
                if (!$up->rowCount()) { $msgs[] = '訂單 ' . $c['order_id'] . ' 單價已有值，略過'; continue; }
                $oldP = null; $newP = null;
                if ($fillProc && !empty($c['proc_fill'])) {
                    $upp->execute([$c['proc_fill'], $c['order_id']]);
                    if ($upp->rowCount()) { $oldP = (string)$c['order']['Processing_items']; $newP = $c['proc_fill']; $procFilled++; }
                }
                $lg->execute([$c['order_id'], $c['order']['Order_oo'], $cat, null, $c['price'], $c['stage_ids'][0] ?? null, $oldP, $newP]);
                $filled++;
            }
            foreach ($c['stage_ids'] as $sid) { $stg->execute([$c['order_id'], $sid]); $staged += $stg->rowCount(); }
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return ['filled' => $filled, 'staged' => $staged, 'proc_filled' => $procFilled, 'skipped' => count($want) - count($cands), 'msgs' => $msgs];
}


/**
 * 自動認定：補資料暫存區「待轉入」列，若同訂單編號下 (料號,數量) 組合與筆數和訂單追蹤現有完全相同
 * ＝訂單內已有這些明細，不需轉入 → 認定為「已略過·已存在」（state=skipped, match_status=exact）。
 * 只改暫存列狀態，不動 order_track。回傳認定筆數。
 */
function opf_auto_close_exact(PDO $db): int {
    $oos = $db->query("SELECT DISTINCT order_oo FROM order_erp_import_stage WHERE state='pending'")->fetchAll(PDO::FETCH_COLUMN);
    $n = 0;
    $up = $db->prepare("UPDATE order_erp_import_stage SET state='skipped', match_status='exact', existing_order_id=?, match_note='訂單內已有相同料號與數量明細，不需轉入' WHERE id=? AND state='pending'");
    foreach (array_chunk($oos, 400) as $ch) {
        $ph = implode(',', array_fill(0, count($ch), '?'));
        $s = $db->prepare("SELECT id,order_oo,part_no,d_id_id,qty,state FROM order_erp_import_stage WHERE order_oo IN ($ph)");
        $s->execute($ch);
        $S = []; foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $S[$r['order_oo']][] = $r;
        $o = $db->prepare("SELECT Order_id,Order_oo,d_id,d_id_ID,Qty FROM order_track WHERE Order_oo IN ($ph) AND (Order_status IS NULL OR Order_status<>6) ORDER BY Order_id");
        $o->execute($ch);
        $O = []; foreach ($o->fetchAll(PDO::FETCH_ASSOC) as $r) $O[$r['Order_oo']][] = $r;
        foreach ($ch as $oo) {
            $ks = array_map(fn($r) => opf_pk($r['d_id_id'], $r['part_no']) . '|' . (int)$r['qty'], $S[$oo] ?? []); sort($ks);
            $ko = array_map(fn($r) => opf_pk($r['d_id_ID'], $r['d_id']) . '|' . (int)$r['Qty'], $O[$oo] ?? []); sort($ko);
            if (!$ks || $ks !== $ko) continue;
            $byKey = []; foreach ($O[$oo] as $r) $byKey[opf_pk($r['d_id_ID'], $r['d_id']) . '|' . (int)$r['Qty']][] = (int)$r['Order_id'];
            foreach ($S[$oo] as $r) {
                $k = opf_pk($r['d_id_id'], $r['part_no']) . '|' . (int)$r['qty'];
                $oid = array_shift($byKey[$k]);
                if ($r['state'] === 'pending') { $up->execute([$oid, $r['id']]); $n += $up->rowCount(); }
            }
        }
    }
    return $n;
}
