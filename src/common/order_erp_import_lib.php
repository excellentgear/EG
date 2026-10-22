<?php
/**
 * order_erp_import_lib.php — ERP「客戶訂購單日報表」補訂單資料（唯一實作）
 * 建立：2026-10-13（使用者交辦：OO1130101-0131.xls 這類舊訂購單報表轉進 order_track）
 *
 * 流程：上傳 xls → 解析 → 與 order_track 比對（同單號底下的料號／數量／製程／單價）
 *       → 存入暫存區 order_erp_import_stage（state=pending，不動 order_track）
 *       → 管理員在「補資料」分頁檢視、勾選後「轉成正式訂單」才寫入 order_track。
 * 鐵則：絕不覆蓋、不修改任何既有訂單，只新增；新增列的業務備註(Order_ps)一律寫「補訂單資料」。
 *
 * 品名規格欄＝品名 ＋ 料號主檔 Spec_No ＋ 空白 ＋ 訂單「製程」(Processing_items)，
 * 所以製程＝Spec_No 之後的文字；料號主檔沒有 Spec_No 或對不上時退回「最後一個空白之後」。
 */

const OEI_PS_TEXT = '補訂單資料';
/** 訂單追蹤數量可比 ERP 多打的備品比例（例：ERP 90、訂單追蹤打 100 含備品）；超過就仍算「內容不同」 */
const OEI_SPARE_PCT = 0.12;
/** 數量太小時多幾件的比例很大、無法判定是備品，不套用 */
const OEI_SPARE_MIN_QTY = 20;

function oei_ensure_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!$db->inTransaction() && !$db->query("SHOW TABLES LIKE 'order_erp_customer_map'")->fetchColumn()) {
        $db->exec("CREATE TABLE order_erp_customer_map (
            erp_name VARCHAR(50) NOT NULL PRIMARY KEY COMMENT '補資料報表上的客戶名稱',
            customer_id VARCHAR(11) NOT NULL COMMENT '對應 customer_list.customer_id',
            created_by INT NULL, created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ERP訂購單補資料：報表客戶名稱→客戶主檔對應'");
    }
    $r = $db->query("SHOW TABLES LIKE 'order_erp_import_stage'")->fetchColumn();
    if ($r) {
        if (!$db->inTransaction() && !$db->query("SHOW COLUMNS FROM order_erp_import_stage LIKE 'part_total'")->fetchColumn()) {
            if (!$db->query("SHOW COLUMNS FROM order_erp_import_stage LIKE 'dup_rank'")->fetchColumn())
                $db->exec("ALTER TABLE order_erp_import_stage ADD COLUMN dup_rank INT NOT NULL DEFAULT 1 COMMENT '(停用)' AFTER existing_order_id");
            $db->exec("ALTER TABLE order_erp_import_stage ADD COLUMN part_total INT NOT NULL DEFAULT 0 COMMENT 'Excel 此單內此料號共幾筆明細' AFTER dup_rank");
        }
        return;
    }
    if ($db->inTransaction()) return; // DDL 會隱式 commit，交易中不建
    $db->exec("CREATE TABLE order_erp_import_stage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_id VARCHAR(20) NOT NULL COMMENT '同一次上傳',
        line_no INT NOT NULL DEFAULT 0 COMMENT 'Excel 列號',
        order_oo VARCHAR(12) NOT NULL,
        order_date DATE NULL,
        delivery_date DATE NULL,
        client_id VARCHAR(11) NULL,
        client_name VARCHAR(50) NULL,
        part_no VARCHAR(50) NOT NULL COMMENT 'Excel 產品編號',
        d_id_id INT NULL COMMENT '對應 d_setting.d_id',
        spec_text VARCHAR(150) NULL COMMENT 'Excel 品名規格原文',
        process_text VARCHAR(50) NULL COMMENT '解析出的製程',
        qty INT NOT NULL DEFAULT 0,
        unit_price DECIMAL(15,4) NULL,
        amount DECIMAL(15,2) NULL,
        currency VARCHAR(30) NULL,
        exchange_rate DECIMAL(10,6) NULL,
        match_status VARCHAR(20) NOT NULL COMMENT 'new_order/add_to_order/exact/diff/no_part',
        match_note VARCHAR(255) NULL,
        existing_order_id INT NULL COMMENT '比對到的既有訂單(任一筆)',
        dup_rank INT NOT NULL DEFAULT 1 COMMENT '(停用)',
        part_total INT NOT NULL DEFAULT 0 COMMENT 'Excel 此單內此料號共幾筆明細',
        state VARCHAR(12) NOT NULL DEFAULT 'pending' COMMENT 'pending/converted/skipped',
        converted_order_id INT NULL,
        created_by INT NULL, created_at DATETIME NOT NULL,
        converted_by INT NULL, converted_at DATETIME NULL,
        KEY idx_batch (batch_id), KEY idx_state (state), KEY idx_oo (order_oo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ERP訂購單補資料暫存區'");
}

function oei_date($v): ?string {
    $v = trim((string)$v);
    if ($v === '') return null;
    if (preg_match('/^(\d+)[\/.\-](\d+)[\/.\-](\d+)$/', $v, $m)) {
        $y = (int)$m[1]; if ($y < 1911) $y += 1911; // 民國年 +1911
        return checkdate((int)$m[2], (int)$m[3], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[3]) : null;
    }
    return null;
}
function oei_num($v): ?float {
    $v = str_replace([',', '，', ' ', "\xC2\xA0"], '', trim((string)$v));
    return ($v !== '' && is_numeric($v)) ? (float)$v : null;
}
/** 製程相同，或任一邊沒記載（舊訂單製程欄多半是空的，空白不算衝突） */
function oei_proc_ok(string $dbProc, string $xlProc): bool {
    $a = oei_norm($dbProc); $b = oei_norm($xlProc);
    return $a === '' || $b === '' || $a === $b;
}
function oei_norm(string $s): string { return preg_replace('/\s+/u', '', trim($s)); }

/** 解析整張工作表 → 列陣列（單據日期／單號向下延續） */
function oei_parse(array $allRows, array &$err): array {
    $head = '';
    foreach ($allRows as $i => $row) { if ($i >= 12) break; $head .= implode('|', array_map('strval', $row)) . '|'; }
    if (mb_strpos($head, '客戶訂購單日報表') === false) { $err[] = '找不到「客戶訂購單日報表」字樣，請確認是否為 ERP 客戶訂購單日報表'; return []; }
    if (mb_strpos($head, '客戶別') === false) { $err[] = '報表型別需為「客戶別 明細表」（才有客戶編號群組列）'; return []; }
    $out = []; $cid = $cname = null; $date = $oo = null; $skipNoOO = 0;
    foreach ($allRows as $i => $row) {
        $r = array_pad(array_values($row), 10, null);
        $a = trim((string)$r[0]); $b = trim((string)$r[1]); $d = trim((string)$r[3]);
        if ($a === '客戶編號：') { $cid = trim((string)$r[2]) ?: null; $cname = trim((string)$r[3]) ?: null; $date = $oo = null; continue; }
        if ($d === '' || $d === '產品編號' || mb_strpos($d, '合計') !== false) continue;
        $qty = oei_num($r[5]);
        if ($qty === null) continue;
        if ($b !== '') {
            if (!preg_match('/^OO\d+$/', $b)) { $skipNoOO++; $oo = null; continue; }
            $oo = $b; if ($a !== '') $date = oei_date($a);
        }
        if ($oo === null) continue;
        $cur = trim((string)$r[8]);
        $out[] = [
            'line_no' => $i + 1, 'order_oo' => $oo, 'order_date' => $date,
            'delivery_date' => oei_date($r[2]), 'client_id' => $cid, 'client_name' => $cname,
            'part_no' => mb_substr($d, 0, 50), 'spec_text' => mb_substr(trim((string)$r[4]), 0, 150),
            'qty' => (int)round($qty), 'unit_price' => oei_num($r[6]), 'amount' => oei_num($r[7]),
            'currency' => ($cur === '' || $cur === '台幣') ? null : mb_substr($cur, 0, 30),
            'exchange_rate' => oei_num($r[9]),
        ];
    }
    if ($skipNoOO) $err[] = "有 {$skipNoOO} 列單號不是 OO 開頭，已略過";
    return $out;
}

/** 製程＝品名規格中 Spec_No 之後的文字；對不上時取最後一個空白之後 */
function oei_split_process(string $text, string $specNo): string {
    $text = trim($text);
    if ($specNo !== '' && ($p = mb_strpos($text, $specNo)) !== false) {
        return trim(mb_substr($text, $p + mb_strlen($specNo)));
    }
    $p = mb_strrpos($text, ' ');
    if ($p === false) { $p = mb_strrpos($text, "\u{3000}"); }
    return $p === false ? '' : trim(mb_substr($text, $p + 1));
}

/** 解析料號主檔：產品編號＋客戶 → d_setting（同編號多筆時以客戶判定，仍不唯一就不猜） */
function oei_part_lookup(PDO $db, string $partNo, ?string $clientId): array {
    static $cache = [];
    $k = $partNo . '|' . $clientId;
    if (isset($cache[$k])) return $cache[$k];
    $st = $db->prepare("SELECT d_id, Spec_No, Customer_Id FROM d_setting WHERE D_Setting_Id = ?");
    $st->execute([$partNo]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $pick = null; $note = '';
    if (count($rows) === 1) $pick = $rows[0];
    elseif (count($rows) > 1) {
        $m = array_values(array_filter($rows, fn($x) => $clientId !== null && (string)$x['Customer_Id'] === $clientId));
        if (count($m) === 1) $pick = $m[0];
        elseif (!$m && $clientId !== null && $clientId !== '') $note = '料號主檔查無此產品編號';   // 同編號的料號都屬別家客戶＝此客戶尚無此料號
        else $note = '料號主檔有 ' . count($rows) . ' 筆同編號' . ($m ? '且同客戶仍有 ' . count($m) . ' 筆' : '且無法由客戶判定');
    } else $note = '料號主檔查無此產品編號';
    return $cache[$k] = ['row' => $pick, 'note' => $note];
}

/**
 * 解析客戶主檔：先用 ERP 客戶編號，找不到再用客戶名稱「完全相同」（customer／customer_full）且只有一筆者。
 * 找不到或有多筆同名一律回 null（不猜）。
 */
function oei_resolve_customer(PDO $db, ?string $cid, ?string $cname): ?string {
    static $cache = [];
    if ($cid === '__reset__') { $cache = []; return null; }
    $k = $cid . '|' . $cname;
    if (array_key_exists($k, $cache)) return $cache[$k];
    $hit = null;
    // 管理員在「設定客戶對應」手動指定的優先
    if ($cname !== null && trim($cname) !== '') {
        $s = $db->prepare("SELECT m.customer_id FROM order_erp_customer_map m JOIN customer_list c ON c.customer_id=m.customer_id WHERE m.erp_name=? LIMIT 1");
        $s->execute([trim($cname)]);
        $hit = $s->fetchColumn() ?: null;
        if ($hit !== null) return $cache[$k] = (string)$hit;
    }
    if ($cid !== null && $cid !== '') {
        $s = $db->prepare("SELECT customer_id FROM customer_list WHERE customer_id=? LIMIT 1"); $s->execute([$cid]);
        $hit = $s->fetchColumn() ?: null;
    }
    if ($hit === null && $cname !== null && trim($cname) !== '') {
        $s = $db->prepare("SELECT customer_id FROM customer_list WHERE customer=? OR customer_full=? LIMIT 2");
        $s->execute([trim($cname), trim($cname)]);
        $ids = array_values(array_unique($s->fetchAll(PDO::FETCH_COLUMN)));
        if (count($ids) === 1) $hit = (string)$ids[0];
    }
    return $cache[$k] = $hit;
}

/** 訂單追蹤數量 > Excel 數量且多出的部分在備品比例內，製程／單價沒有衝突 */
function oei_spare_ok(array $e, int $xqty, string $xproc, ?float $xprice): bool {
    $eq = (int)$e['Qty'];
    if ($eq <= $xqty || $xqty < OEI_SPARE_MIN_QTY || $eq > (int)floor($xqty * (1 + OEI_SPARE_PCT))) return false;
    if (!oei_proc_ok((string)$e['Processing_items'], $xproc)) return false;
    return !($e['unit_price'] !== null && $xprice !== null && abs((float)$e['unit_price'] - $xprice) >= 0.005);
}

/**
 * Excel 把同一筆訂單拆成多行（如 30＋70＋2），訂單追蹤卻打成一筆（102）：
 * 在「尚未對應的既有訂單列」中找一組，數量合計剛好等於這些 Excel 行的合計，且製程／單價沒有衝突。
 * $xl＝[['qty','proc','price'],…]；$ex＝[['Qty','Processing_items','unit_price'],…]；回傳既有列的索引陣列，找不到回 null。
 */
function oei_group_sum_match(array $xl, array $ex): ?array {
    if (count($xl) < 2 || !$ex || count($ex) > 12) return null;
    $target = 0; foreach ($xl as $x) $target += (int)$x['qty'];
    $n = count($ex);
    for ($mask = 1; $mask < (1 << $n); $mask++) {
        $sum = 0; $idx = [];
        for ($i = 0; $i < $n; $i++) if ($mask & (1 << $i)) { $sum += (int)$ex[$i]['Qty']; $idx[] = $i; }
        if ($sum !== $target) continue;
        $ok = true;
        foreach ($idx as $i) foreach ($xl as $x) {
            if (!oei_proc_ok((string)$ex[$i]['Processing_items'], (string)$x['proc'])) { $ok = false; break 2; }
            if ($ex[$i]['unit_price'] !== null && $x['price'] !== null && abs((float)$ex[$i]['unit_price'] - (float)$x['price']) >= 0.005) { $ok = false; break 2; }
        }
        if ($ok) return $idx;
    }
    return null;
}

/**
 * 逐列比對。回傳每列補上 d_id_id／process_text／match_status／match_note／existing_order_id。
 * 同單號底下可能有多筆「料號、數量、製程、單價」都相同的列，所以採「一對一消耗」：
 * 每筆 Excel 列只會對應一筆既有訂單列，且每筆既有列只被對應一次。
 */
function oei_match(PDO $db, array $rows): array {
    $custNames = [];
    foreach (array_unique(array_filter(array_column($rows, 'client_id'))) as $c) {
        $s = $db->prepare("SELECT customer FROM customer_list WHERE customer_id=?"); $s->execute([$c]);
        $custNames[$c] = $s->fetchColumn() ?: null;
    }
    foreach ($rows as &$r) {
        $lk = oei_part_lookup($db, $r['part_no'], $r['client_id']);
        $r['d_id_id'] = $lk['row'] ? (int)$lk['row']['d_id'] : null;
        $spec = $lk['row']['Spec_No'] ?? '';
        $r['process_text'] = mb_substr(oei_split_process($r['spec_text'], (string)$spec), 0, 50);
        $r['_cust_ok'] = $r['client_id'] && !empty($custNames[$r['client_id']]);
        if ($r['_cust_ok']) $r['client_name'] = $custNames[$r['client_id']];
        $r['match_status'] = ''; $r['match_note'] = ''; $r['existing_order_id'] = null;
        $r['_lknote'] = $lk['note'];
    }
    unset($r);

    // Excel 為 ERP 原始檔＝正確：同單號內「料號/數量/製程/單價」相同的明細要算有幾筆
    $idKey = fn($r) => $r['order_oo'] . '|' . ($r['d_id_id'] ?: $r['part_no']) . '|' . $r['qty'] . '|' . oei_norm($r['process_text']) . '|' . ($r['unit_price'] === null ? '' : round($r['unit_price'], 2));
    $partKey = fn($r) => $r['order_oo'] . '|' . ($r['d_id_id'] ?: $r['part_no']);
    $ptCnt = []; $ptSeq = [];
    foreach ($rows as $r) { $k = $partKey($r); $ptCnt[$k] = ($ptCnt[$k] ?? 0) + 1; }
    foreach ($rows as &$r) { $k = $partKey($r); $ptSeq[$k] = ($ptSeq[$k] ?? 0) + 1; $r['part_total'] = $ptCnt[$k]; $r['part_seq'] = $ptSeq[$k]; }
    unset($r);

    $ooList = array_values(array_unique(array_column($rows, 'order_oo')));
    $exist = [];
    foreach (array_chunk($ooList, 500) as $ch) {
        $ph = implode(',', array_fill(0, count($ch), '?'));
        $s = $db->prepare("SELECT Order_id, Order_oo, d_id, d_id_ID, Qty, Processing_items, unit_price FROM order_track WHERE Order_oo IN ($ph)");
        $s->execute($ch);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $e) { $e['used'] = false; $exist[$e['Order_oo']][] = $e; }
    }
    $samePart = fn($e, $r) => ($r['d_id_id'] && (int)$e['d_id_ID'] === $r['d_id_id']) || (string)$e['d_id'] === (string)$r['part_no'];
    // 第一輪：完全相同者先消耗
    foreach ($rows as &$r) {
        if (!isset($exist[$r['order_oo']])) continue;
        foreach ($exist[$r['order_oo']] as &$e) {
            if ($e['used'] || !$samePart($e, $r)) continue;
            $priceOk = $e['unit_price'] === null || $r['unit_price'] === null || abs((float)$e['unit_price'] - $r['unit_price']) < 0.005;
            if ((int)$e['Qty'] === $r['qty'] && oei_proc_ok((string)$e['Processing_items'], $r['process_text']) && $priceOk) {
                $e['used'] = true; $r['match_status'] = 'exact'; $r['existing_order_id'] = (int)$e['Order_id'];
                $r['match_note'] = '訂單內已有相同料號／數量／製程／單價，略過'; break;
            }
        }
        unset($e);
    }
    unset($r);
    // 第二輪：Excel 同單同料多行，合計＝訂單內未對應的一筆（或幾筆）→ 視為已存在
    $grp = [];
    foreach ($rows as $i => $r) { if ($r['match_status'] === '' && isset($exist[$r['order_oo']])) $grp[$partKey($r)][] = $i; }
    foreach ($grp as $idxs) {
        $r0 = $rows[$idxs[0]];
        $cand = [];
        foreach ($exist[$r0['order_oo']] as $k => $e) if (!$e['used'] && $samePart($e, $r0)) $cand[] = $k;
        if (!$cand) continue;
        $xl = array_map(fn($i) => ['qty' => $rows[$i]['qty'], 'proc' => $rows[$i]['process_text'], 'price' => $rows[$i]['unit_price']], $idxs);
        $hit = oei_group_sum_match($xl, array_map(fn($k) => $exist[$r0['order_oo']][$k], $cand));
        if ($hit === null) continue;
        foreach ($hit as $h) { $exist[$r0['order_oo']][$cand[$h]]['used'] = true; $eid = (int)$exist[$r0['order_oo']][$cand[$h]]['Order_id']; }
        foreach ($idxs as $i) {
            $rows[$i]['match_status'] = 'exact'; $rows[$i]['existing_order_id'] = $eid;
            $rows[$i]['match_note'] = 'Excel 此單此料 ' . count($idxs) . ' 行合計 ' . array_sum(array_column($xl, 'qty')) . '＝訂單內 ' . count($hit) . ' 筆合計，視為已存在，略過';
        }
    }
    // 第三輪：訂單追蹤數量含備品（比 Excel 多一點）→ 視為已存在，一對一消耗，取差額最小者
    foreach ($rows as &$r) {
        if ($r['match_status'] !== '' || !isset($exist[$r['order_oo']])) continue;
        $best = null;
        foreach ($exist[$r['order_oo']] as $k => $e) {
            if ($e['used'] || !$samePart($e, $r) || !oei_spare_ok($e, $r['qty'], $r['process_text'], $r['unit_price'])) continue;
            if ($best === null || (int)$e['Qty'] < (int)$exist[$r['order_oo']][$best]['Qty']) $best = $k;
        }
        if ($best !== null) {
            $exist[$r['order_oo']][$best]['used'] = true;
            $r['match_status'] = 'exact'; $r['existing_order_id'] = (int)$exist[$r['order_oo']][$best]['Order_id'];
            $r['match_note'] = '訂單追蹤數量 ' . $exist[$r['order_oo']][$best]['Qty'] . ' 含備品（Excel ' . $r['qty'] . '），視為已存在，略過';
        }
    }
    unset($r);
    foreach ($rows as &$r) {
        if ($r['match_status'] !== '') continue;
        if ($r['d_id_id'] === null) {
            // 查無料號：客戶能對上主檔（編號或名稱完全相同）者，轉入時自動建立料號並綁該客戶
            $cu = ($r['_lknote'] === '料號主檔查無此產品編號') ? oei_resolve_customer($db, $r['client_id'], $r['client_name']) : null;
            if ($cu !== null) {
                $r['client_id'] = $cu;
                $r['match_status'] = 'new_part'; $r['match_note'] = '料號主檔查無此產品編號，轉入時將自動建立料號並綁定客戶 ' . $cu;
            } else {
                $r['match_status'] = 'no_part'; $r['match_note'] = $r['_lknote'] . ($r['_lknote'] === '料號主檔查無此產品編號' ? '，且客戶無法對應客戶主檔（請用「設定客戶對應」）' : '，請到料號主檔整理後再轉入');
            }
            continue;
        }
        if (!isset($exist[$r['order_oo']])) { $r['match_status'] = 'new_order'; $r['match_note'] = '單號不存在，將新建此訂單'; continue; }
        $same = array_values(array_filter($exist[$r['order_oo']], fn($e) => $samePart($e, $r)));
        $r['existing_order_id'] = (int)$exist[$r['order_oo']][0]['Order_id'];
        if (!$same) { $r['match_status'] = 'add_to_order'; $r['match_note'] = '單號已存在但無此產品編號，將補進去'; continue; }
        $unused = array_values(array_filter($same, fn($e) => !$e['used']));
        if (!$unused) {   // 訂單內此料號的明細都已被 Excel 其他列對應掉 → 這一筆是 Excel 多出來的
            $r['match_status'] = 'add_to_order';
            $r['match_note'] = "Excel 此單此料共 {$r['part_total']} 筆，訂單內只有 " . count($same) . " 筆且已被其他列對應，補進第 {$r['part_seq']} 筆";
            continue;
        }
        $d = [];
        $e = $unused[0];
        if ((int)$e['Qty'] !== $r['qty']) $d[] = "數量 {$e['Qty']}→{$r['qty']}";
        if (!oei_proc_ok((string)$e['Processing_items'], $r['process_text'])) $d[] = '製程「' . $e['Processing_items'] . '」→「' . $r['process_text'] . '」';
        if ($e['unit_price'] !== null && $r['unit_price'] !== null && abs((float)$e['unit_price'] - $r['unit_price']) >= 0.005) $d[] = "單價 " . (float)$e['unit_price'] . "→" . $r['unit_price'];
        $r['match_status'] = 'diff';
        $r['match_note'] = $d ? '同單號已有此產品但 ' . implode('；', $d) . '（不覆蓋，需要才勾選補入）' : '同單號此產品已有 ' . count($same) . ' 筆且都已對應完（相同明細第 N 筆，需要才勾選補入）';
    }
    unset($r);
    foreach ($rows as &$r) { unset($r['_cust_ok'], $r['_lknote']); }
    return $rows;
}

/** 轉成正式訂單：重新比對後才寫入，只 INSERT。回傳 [ok, message, order_id] */
function oei_convert_one(PDO $db, int $stageId, int $uid): array {
    $s = $db->prepare("SELECT * FROM order_erp_import_stage WHERE id=? FOR UPDATE");
    $s->execute([$stageId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r || $r['state'] !== 'pending') return [false, '此筆已處理過或不存在', null];
    if (!$r['d_id_id']) {
        // 自動建立料號：客戶必須對得上主檔；同編號同客戶已存在就沿用（同批前一筆可能剛建過）
        $cu = oei_resolve_customer($db, $r['client_id'], $r['client_name']);
        if ($cu === null) return [false, '料號主檔未對應，且客戶無法對應客戶主檔，無法轉入', null];
        $f = $db->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id=? AND Customer_Id=? ORDER BY d_id LIMIT 2");
        $f->execute([$r['part_no'], $cu]);
        $ex = $f->fetchAll(PDO::FETCH_COLUMN);
        if (count($ex) > 1) return [false, '料號主檔同編號同客戶有多筆，無法判定', null];
        if ($ex) $newId = (int)$ex[0];
        else {
            $db->prepare("INSERT INTO d_setting (D_Setting_Id,Type,Customer_Id,Created_By,Created_At) VALUES (?,'N',?,?,NOW())")
               ->execute([$r['part_no'], $cu, (string)$uid]);
            $newId = (int)$db->lastInsertId();
        }
        $db->prepare("UPDATE order_erp_import_stage SET d_id_id=?, client_id=? WHERE id=?")->execute([$newId, $cu, $stageId]);
        $r['d_id_id'] = $newId; $r['client_id'] = $cu;
    }
    if (!$r['order_date']) return [false, '單據日期缺漏', null];
    // 轉入當下重新比對（不比對交期）：訂單內此料號筆數已 ≥ Excel 此料號筆數 → 都已存在，略過
    // 「同料號·內容不同」是使用者明確勾選要補入的，不套這個檢查；舊版暫存列(part_total=0)也不套
    if ($r['match_status'] !== 'diff' && (int)$r['part_total'] > 0) {
        $q = $db->prepare("SELECT COUNT(*) FROM order_track WHERE Order_oo=? AND (d_id_ID=? OR d_id=?)");
        $q->execute([$r['order_oo'], (int)$r['d_id_id'], $r['part_no']]);
        if ((int)$q->fetchColumn() >= (int)$r['part_total']) {
            $db->prepare("UPDATE order_erp_import_stage SET state='skipped', match_status='exact', match_note=? WHERE id=?")
               ->execute(['轉入時發現訂單內此料號筆數已足夠，略過', $stageId]);
            return [false, '訂單內此料號筆數已足夠，已略過', null];
        }
    }
    $cn = $db->prepare("SELECT customer FROM customer_list WHERE customer_id=?"); $cn->execute([$r['client_id']]);
    $clientName = $cn->fetchColumn();
    $clientId = $clientName ? $r['client_id'] : null;
    $clientName = $clientName ?: ($r['client_name'] ?: '');
    if ($clientName === '') return [false, '查無客戶名稱', null];
    $db->prepare("INSERT INTO order_track SET
        Order_oo=?, d_id=?, Specification=NULL, Order_ps=?, Client_name=?, Qty=?, Order_date=?, Delivery_date=?,
        Processing_items=?, unit_price=?, currency=?, Order_status=NULL, split_seq=1, parent_order_id=NULL,
        Created_At=NOW(), Created_By=?, Client_name_ID=?, d_id_ID=?, pmGet=?")
       ->execute([$r['order_oo'], $r['part_no'], OEI_PS_TEXT, $clientName, (int)$r['qty'], $r['order_date'],
                  $r['delivery_date'] ?: $r['order_date'], $r['process_text'] ?: null, $r['unit_price'], $r['currency'],
                  $uid, $clientId, (int)$r['d_id_id'], $r['order_date']]); // 補資料：轉生管日＝接單日；指派設計／設計接收日可留空故不填
    $oid = (int)$db->lastInsertId();
    $db->prepare("UPDATE order_erp_import_stage SET state='converted', converted_order_id=?, converted_by=?, converted_at=NOW() WHERE id=?")
       ->execute([$oid, $uid, $stageId]);
    return [true, 'ok', $oid];
}

/**
 * 重新判定暫存區「待轉入」裡料號未對應(no_part／new_part)的列：
 * 客戶對應或料號主檔有變動後呼叫。只調整狀態／說明／d_id_id，不動訂單資料。回傳異動筆數。
 */
function oei_rematch_pending(PDO $db): int {
    oei_resolve_customer($db, '__reset__', null);
    $rows = $db->query("SELECT id, order_oo, part_no, client_id, client_name, match_status FROM order_erp_import_stage
                        WHERE state='pending' AND match_status IN ('no_part','new_part')")->fetchAll(PDO::FETCH_ASSOC);
    $up = $db->prepare("UPDATE order_erp_import_stage SET match_status=?, match_note=?, d_id_id=?, client_id=? WHERE id=?");
    $oc = $db->prepare("SELECT 1 FROM order_track WHERE Order_oo=? LIMIT 1");
    $n = 0;
    foreach ($rows as $r) {
        $cu = oei_resolve_customer($db, $r['client_id'], $r['client_name']);
        $lk = oei_part_lookup($db, $r['part_no'], $cu ?? $r['client_id']);
        $cid = $cu ?? $r['client_id'];
        if ($lk['row']) {
            $oc->execute([$r['order_oo']]);
            $st = $oc->fetchColumn() ? 'add_to_order' : 'new_order';
            $note = $st === 'add_to_order' ? '單號已存在但無此產品編號，將補進去' : '單號不存在，將新建此訂單';
            $up->execute([$st, $note, (int)$lk['row']['d_id'], $cid, $r['id']]);
        } elseif ($lk['note'] === '料號主檔查無此產品編號' && $cu !== null) {
            $up->execute(['new_part', '料號主檔查無此產品編號，轉入時將自動建立料號並綁定客戶 ' . $cu, null, $cid, $r['id']]);
        } else {
            $up->execute(['no_part', $lk['note'] . ($lk['note'] === '料號主檔查無此產品編號' ? '，且客戶無法對應客戶主檔（請用「設定客戶對應」）' : '，請到料號主檔整理後再轉入'), null, $r['client_id'], $r['id']]);
        }
        $n++;
    }
    // 同料號·內容不同：Excel 多行合計＝訂單內既有列合計者，改為已存在（略過）
    $g = [];
    foreach ($db->query("SELECT id, order_oo, part_no, d_id_id, qty, process_text, unit_price FROM order_erp_import_stage WHERE state='pending' AND match_status='diff'")->fetchAll(PDO::FETCH_ASSOC) as $r)
        $g[$r['order_oo'] . '|' . $r['part_no']][] = $r;
    $exs = $db->prepare("SELECT Order_id, Qty, Processing_items, unit_price FROM order_track WHERE Order_oo=? AND (d_id=? OR d_id_ID=?)");
    $sk = $db->prepare("UPDATE order_erp_import_stage SET state='skipped', match_status='exact', match_note=?, existing_order_id=? WHERE id=? AND state='pending'");
    foreach ($g as $rs) {
        $exs->execute([$rs[0]['order_oo'], $rs[0]['part_no'], (int)$rs[0]['d_id_id']]);
        $ex = $exs->fetchAll(PDO::FETCH_ASSOC);
        $xl = array_map(fn($r) => ['qty' => $r['qty'], 'proc' => (string)$r['process_text'], 'price' => $r['unit_price'] === null ? null : (float)$r['unit_price']], $rs);
        $hit = oei_group_sum_match($xl, $ex);
        if ($hit === null) {
            $usedE = [];
            foreach ($rs as $r) {
                $best = null;
                foreach ($ex as $k => $e) {
                    if (isset($usedE[$k]) || !oei_spare_ok($e, (int)$r['qty'], (string)$r['process_text'], $r['unit_price'] === null ? null : (float)$r['unit_price'])) continue;
                    if ($best === null || (int)$e['Qty'] < (int)$ex[$best]['Qty']) $best = $k;
                }
                if ($best === null) continue;
                $usedE[$best] = true;
                $sk->execute(['訂單追蹤數量 ' . $ex[$best]['Qty'] . ' 含備品（Excel ' . $r['qty'] . '），視為已存在，略過', (int)$ex[$best]['Order_id'], $r['id']]); $n++;
            }
            continue;
        }
        foreach ($rs as $r) { $sk->execute(['Excel 此單此料 ' . count($rs) . ' 行合計＝訂單內 ' . count($hit) . ' 筆合計，視為已存在，略過', (int)$ex[$hit[0]]['Order_id'], $r['id']]); $n++; }
    }
    return $n;
}
