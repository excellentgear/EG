<?php
/**
 * ERP 客戶對帳單匯入 — 唯一實作。
 *
 * 目的：網頁上的出貨單可能跟 ERP 實際轉出的有誤差，以 ERP 為準，
 *       用「出貨單號 + 料號 + 數量」對應後更新網頁出貨明細的單價（數量不同時另列、預設不勾）。
 *
 * ERP 對帳單格式（兩種版型相同、只差品名規格欄是否含規格）：
 *   第 1~9 列為表頭（第 9 列：日期｜單號｜品名規格｜數量｜單位｜單價｜金額…），
 *   單號只出現在每張出貨單的第一列，後面各列要沿用上一個單號；日期為民國 yyy/mm/dd。
 *   「品名規格」開頭即為料號（有規格版＝料號+規格連在一起，因此一律用「開頭比對」）。
 */
require_once __DIR__ . '/acc_lib.php';
require_once __DIR__ . '/acc_voucher_lib.php';

function acc_erp_num($v): float
{
    if (is_numeric($v)) return (float)$v;
    return (float)str_replace([',', ' '], '', (string)$v);
}

/** 民國 yyy/mm/dd → Y-m-d；失敗回 '' */
function acc_erp_date($v): string
{
    $s = trim((string)$v);
    if (!preg_match('~^(\d{2,3})/(\d{1,2})/(\d{1,2})$~', $s, $m)) return '';
    $y = (int)$m[1] + 1911;
    return checkdate((int)$m[2], (int)$m[3], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[3]) : '';
}

/**
 * 解析 ERP 對帳單檔（xls/xlsx）。
 * @return array ['customer_id','customer_name','range','lines'=>[{row,date,no,spec,qty,unit,price,amount}],'total_declared','sheet']
 */
function acc_erp_stmt_parse(string $path): array
{
    require_once __DIR__ . '/../../vendor/autoload.php';
    $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $res = ['customer_id' => '', 'customer_name' => '', 'range' => '', 'lines' => [], 'total_declared' => null, 'sheet' => ''];

    foreach ($ss->getWorksheetIterator() as $ws) {
        $rows = $ws->toArray(null, true, false, false);
        $hdr = -1;
        foreach ($rows as $i => $r) {
            if (trim((string)($r[0] ?? '')) === '日期' && trim((string)($r[1] ?? '')) === '單號'
                && mb_strpos((string)($r[2] ?? ''), '品名') !== false) { $hdr = $i; break; }
        }
        if ($hdr < 0) continue;
        $res['sheet'] = $ws->getTitle();
        for ($i = 0; $i < $hdr; $i++) {
            $r = $rows[$i];
            if (trim((string)($r[0] ?? '')) === '客戶編號：') {
                $res['customer_id'] = trim((string)($r[1] ?? ''));
                $res['customer_name'] = trim((string)($r[2] ?? ''));
            }
            if (mb_strpos((string)($r[0] ?? ''), '日期區間') === 0) $res['range'] = trim((string)$r[0]);
        }
        $cur = '';
        for ($i = $hdr + 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            $c0 = trim((string)($r[0] ?? ''));
            if (mb_strpos($c0, '累計預收') === 0 || mb_strpos((string)($r[4] ?? ''), '本期應收合計') !== false
                || mb_strpos((string)($r[2] ?? ''), '本期應收合計') !== false) {
                foreach ([6, 4] as $k) if (isset($r[$k]) && is_numeric(str_replace(',', '', (string)$r[$k]))) { $res['total_declared'] = acc_erp_num($r[$k]); break; }
                break;
            }
            $date = acc_erp_date($c0);
            $spec = trim((string)($r[2] ?? ''));
            if ($date === '' || $spec === '') continue;
            $no = trim((string)($r[1] ?? ''));
            if ($no !== '') $cur = $no;
            if ($cur === '') continue;
            $res['lines'][] = [
                'row' => $i + 1, 'date' => $date, 'no' => $cur, 'spec' => $spec,
                'qty' => acc_erp_num($r[3] ?? 0), 'unit' => trim((string)($r[4] ?? '')),
                'price' => acc_erp_num($r[5] ?? 0), 'amount' => acc_erp_num($r[6] ?? 0),
            ];
        }
        break;   // 只取第一個符合版型的工作表（「工作表1 (2)」是另一種重排，不重複算）
    }
    return $res;
}

/** 建立匯入紀錄表（DDL 一律在交易外呼叫） */
function acc_erp_stmt_ensure(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db->exec("CREATE TABLE IF NOT EXISTS acc_erp_stmt_log (
        log_id INT AUTO_INCREMENT PRIMARY KEY,
        batch_token VARCHAR(40) NOT NULL,
        file_name VARCHAR(200) NULL,
        is_id INT NOT NULL,
        is_number VARCHAR(30) NULL,
        part_no VARCHAR(80) NULL,
        old_qty DECIMAL(14,4) NULL, new_qty DECIMAL(14,4) NULL,
        old_price DECIMAL(14,4) NULL, new_price DECIMAL(14,4) NULL,
        applied_by VARCHAR(30) NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_is (is_id), KEY idx_batch (batch_token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ERP對帳單匯入更新紀錄'");
}

/**
 * 對應網頁出貨單。
 * @return array ['entries'=>[...], 'summary'=>[...]]
 *   entry.status: same(一致) / price(單價不同) / qty(數量不同) / erp_only(網頁查無) / site_only(ERP 沒有) / locked(已鎖定，不可改)
 */
function acc_erp_stmt_match(PDO $db, array $lines): array
{
    $nos = array_values(array_unique(array_map(fn($l) => $l['no'], $lines)));
    $site = [];
    if ($nos) {
        for ($o = 0; $o < count($nos); $o += 500) {
            $part = array_slice($nos, $o, 500);
            $ph = implode(',', array_fill(0, count($part), '?'));
            $st = $db->prepare("SELECT IS_id, IS_number, Product_id, Specification, Qty, Unit_price, Client_name,
                                       DATE_FORMAT(Order_date,'%Y-%m-%d') AS d, bind_locked
                                FROM is_list WHERE IS_number IN ($ph) ORDER BY IS_id");
            $st->execute($part);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $site[$r['IS_number']][] = $r;
        }
    }
    // 退貨單：ERP 對帳單裡數量／金額為負、或單號是 IR 開頭的明細。網頁退貨單每天由 ERP 同步，
    // 所以只「比對」不更新（entry.kind='return'，沒有 is_id，更新動作碰不到）。
    $retNos = [];
    foreach ($lines as $l) if ($l['qty'] < 0 || $l['amount'] < 0 || preg_match('/^IR/i', (string)$l['no'])) $retNos[$l['no']] = 1;
    $retNos = array_keys($retNos);
    $siteRet = [];
    for ($o = 0; $o < count($retNos); $o += 500) {
        $part = array_slice($retNos, $o, 500);
        $ph = implode(',', array_fill(0, count($part), '?'));
        $st = $db->prepare("SELECT it.IR_id, it.IR_no, it.d_id, it.Qty, it.Unit_price, it.Client_name,
                                   DATE_FORMAT(it.IR_date,'%Y-%m-%d') AS d
                            FROM ir_track it LEFT JOIN ir_return_type rt ON rt.type_id = it.return_type_id
                            WHERE it.IR_no IN ($ph) AND COALESCE(rt.is_note,0) = 0 ORDER BY it.IR_id");
        $st->execute($part);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $siteRet[$r['IR_no']][] = ['IS_id' => null, 'IR_id' => (int)$r['IR_id'], 'Product_id' => $r['d_id'],
                'Qty' => -(float)$r['Qty'], 'Unit_price' => $r['Unit_price'], 'Client_name' => $r['Client_name'],
                'd' => $r['d'], 'bind_locked' => 0, '_ret' => 1];
        }
    }
    $invMap = acc_invoiced_src_map($db);
    $vMap = function_exists('acc_voucher_src_map') ? acc_voucher_src_map($db) : [];

    $group = [];
    foreach ($lines as $l) $group[$l['no']][] = $l;

    $entries = []; $sum = ['same' => 0, 'price' => 0, 'qty' => 0, 'erp_only' => 0, 'site_only' => 0, 'locked' => 0];
    $startsWith = function (string $spec, string $part): bool {
        return $part !== '' && stripos($spec, $part) === 0;
    };
    $lockOf = function (array $row) use ($invMap, $vMap): string {
        $k = 'IS-' . (int)$row['IS_id'];
        if (isset($vMap[$k])) return '已有傳票 ' . ($vMap[$k]['voucher_no'] ?: '(未填號)');
        // 發票還是草稿 → 允許更正（套用時會同步草稿金額）；已轉出／已開立才鎖定
        if (isset($invMap[$k]) && $invMap[$k]['status'] !== 'draft')
            return '已開發票' . (!empty($invMap[$k]['invoice_no']) ? ' ' . $invMap[$k]['invoice_no'] : '（已轉出）');
        if (!empty($row['bind_locked'])) return '已鎖定';
        return '';
    };

    foreach ($group as $no => $erpLines) {
        $isRet = in_array($no, $retNos, true);
        $srows = $isRet ? ($siteRet[$no] ?? []) : ($site[$no] ?? []);
        $usedS = []; $usedE = [];
        // 每一條 ERP 明細可對應的網頁列（依料號開頭）
        $cand = [];
        foreach ($erpLines as $ei => $e) {
            foreach ($srows as $si => $s)
                if ($startsWith($e['spec'], trim((string)$s['Product_id']))) $cand[$ei][] = $si;
        }
        $emit = function (string $status, int $ei, ?int $si, string $lock = '') use (&$entries, &$sum, $erpLines, $srows, $no, $isRet) {
            $e = $ei >= 0 ? $erpLines[$ei] : null;
            $s = $si !== null ? $srows[$si] : null;
            $entries[] = [
                'no' => $no, 'status' => $status,
                'erp_row' => $e['row'] ?? null, 'erp_date' => $e['date'] ?? '', 'erp_spec' => $e['spec'] ?? '',
                'erp_qty' => $e['qty'] ?? null, 'erp_price' => $e['price'] ?? null, 'erp_amount' => $e['amount'] ?? null,
                'kind' => $isRet ? 'return' : 'ship', 'ir_id' => ($s && $isRet) ? (int)$s['IR_id'] : null,
                'is_id' => ($s && !$isRet) ? (int)$s['IS_id'] : null, 'site_part' => $s['Product_id'] ?? '',
                'site_client' => $s['Client_name'] ?? '', 'site_date' => $s['d'] ?? '',
                'site_qty' => $s ? (float)$s['Qty'] : null, 'site_price' => $s ? (float)$s['Unit_price'] : null,
                'lock' => $lock,
            ];
            $sum[$lock !== '' ? 'locked' : $status]++;
        };
        $pass = function (bool $needQty, bool $needPrice, string $label) use (&$usedS, &$usedE, $cand, $erpLines, $srows, $lockOf, $emit) {
            foreach ($erpLines as $ei => $e) {
                if (isset($usedE[$ei])) continue;
                foreach ($cand[$ei] ?? [] as $si) {
                    if (isset($usedS[$si])) continue;
                    $s = $srows[$si];
                    if ($needQty && abs(abs((float)$s['Qty']) - abs($e['qty'])) > 0.0001) continue;
                    if ($needPrice && abs((float)$s['Unit_price'] - $e['price']) > 0.00005) continue;
                    $usedS[$si] = 1; $usedE[$ei] = 1;
                    $differ = (abs(abs((float)$s['Qty']) - abs($e['qty'])) > 0.0001) ? 'qty'
                            : ((abs((float)$s['Unit_price'] - $e['price']) > 0.00005) ? 'price' : 'same');
                    $lock = ($differ === 'same' || !empty($s['_ret'])) ? '' : $lockOf($s);
                    $emit($differ, $ei, $si, $lock);
                    break;
                }
            }
        };
        $pass(true, true, 'exact');      // 料號＋數量＋單價都相同
        $pass(true, false, 'price');     // 料號＋數量相同、單價不同 → 更新單價
        $pass(false, false, 'qty');      // 只有料號對得上 → 數量（與單價）不同
        foreach ($erpLines as $ei => $e) if (!isset($usedE[$ei])) $emit('erp_only', $ei, null);
        foreach ($srows as $si => $s) if (!isset($usedS[$si])) $emit('site_only', -1, $si);
    }
    return ['entries' => $entries, 'summary' => $sum, 'is_numbers' => count($nos)];
}

/**
 * 套用更新。$items＝[{is_id, token_idx}]；新值一律取自伺服器暫存的比對結果，不信前端傳來的數字。
 * 舊值已被別人改過、已被鎖定一律略過並回報。
 */
function acc_erp_stmt_apply(PDO $db, array $stash, array $isIds, string $userName): array
{
    acc_erp_stmt_ensure($db);                       // DDL 在交易外
    $want = array_flip(array_map('intval', $isIds));
    $invMap = acc_invoiced_src_map($db);
    $vMap = function_exists('acc_voucher_src_map') ? acc_voucher_src_map($db) : [];
    $done = []; $skip = []; $sync = [];
    try {
        $db->beginTransaction();
        $sel = $db->prepare("SELECT IS_id, IS_number, Product_id, Qty, Unit_price, bind_locked FROM is_list WHERE IS_id=? FOR UPDATE");
        $upd = $db->prepare("UPDATE is_list SET Qty=?, Unit_price=? WHERE IS_id=?");
        $log = $db->prepare("INSERT INTO acc_erp_stmt_log (batch_token,file_name,is_id,is_number,part_no,old_qty,new_qty,old_price,new_price,applied_by)
                             VALUES (?,?,?,?,?,?,?,?,?,?)");
        $seen = [];
        foreach ($stash['entries'] as $e) {
            if (!in_array($e['status'], ['price', 'qty'], true) || empty($e['is_id'])) continue;
            $id = (int)$e['is_id'];
            if (!isset($want[$id]) || isset($seen[$id])) continue;
            $seen[$id] = 1;
            $label = $e['no'] . ' ' . $e['site_part'];
            if (isset($vMap['IS-' . $id]))   { $skip[] = $label . '：已有傳票，不可修改'; continue; }
            if (acc_sheet_locked_src($db, 'IS', $id)) { $skip[] = $label . '：所屬對帳單已確認鎖帳，不可修改'; continue; }
            $inv = $invMap['IS-' . $id] ?? null;
            if ($inv && $inv['status'] !== 'draft') { $skip[] = $label . '：發票已轉出／開立，不可修改'; continue; }
            $sel->execute([$id]);
            $cur = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$cur) { $skip[] = $label . '：出貨明細已不存在'; continue; }
            // bind_locked 是「綁定鎖」，只改單價不碰綁定，所以單價差異可更新；數量不同可能牽動分配，鎖定時仍略過
            if (!empty($cur['bind_locked']) && $e['status'] !== 'price') { $skip[] = $label . '：已鎖定'; continue; }
            if (abs((float)$cur['Qty'] - (float)$e['site_qty']) > 0.0001
                || abs((float)$cur['Unit_price'] - (float)$e['site_price']) > 0.00005) {
                $skip[] = $label . '：網頁資料在比對後已被修改，請重新匯入比對'; continue;
            }
            $newQty = (float)$e['erp_qty']; $newPrice = (float)$e['erp_price'];
            $upd->execute([$newQty, $newPrice, $id]);
            // 在出貨單備註註記自動更正了什麼（舊值->新值）
            $chg = [];
            if (abs((float)$cur['Qty'] - $newQty) > 0.0001) $chg[] = '數量' . (float)$cur['Qty'] . '->' . $newQty;
            if (abs((float)$cur['Unit_price'] - $newPrice) > 0.00005) $chg[] = '單價' . (float)$cur['Unit_price'] . '->' . $newPrice;
            if ($chg) acc_erp_stmt_note($db, $id, '匯入ERP自動更正 ' . implode('、', $chg));
            $log->execute([$stash['token'], $stash['file_name'], $id, $cur['IS_number'], $cur['Product_id'],
                           $cur['Qty'], $newQty, $cur['Unit_price'], $newPrice, $userName]);
            if ($inv) {   // 發票草稿：同步明細與發票金額
                $newAmt = round($newQty * $newPrice, 2);
                $db->prepare("UPDATE acc_invoice_item SET qty=?, unit_price=?, amount=? WHERE invoice_id=? AND src_type='IS' AND src_id=?")
                   ->execute([$newQty, $newPrice, $newAmt, $inv['invoice_id'], $id]);
                $sync[(int)$inv['invoice_id']] = 1;
            }
            $done[] = $label;
        }
        foreach (array_keys($sync) as $iid) {
            $t = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM acc_invoice_item WHERE invoice_id=?");
            $t->execute([$iid]);
            $net = (float)$t->fetchColumn();
            $rate = acc_tax_rate($db);
            $tax = round($net * $rate);
            $db->prepare("UPDATE acc_invoice SET sales_amount=?, tax_amount=?, total_amount=? WHERE invoice_id=? AND status='draft'")
               ->execute([$net, $tax, $net + $tax, $iid]);
        }
        $db->commit();
    } catch (Throwable $ex) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '更新失敗：' . $ex->getMessage()];
    }
    return ['success' => true, 'updated' => count($done), 'skipped' => $skip,
            'message' => '已更新 ' . count($done) . ' 筆' . ($skip ? '，略過 ' . count($skip) . ' 筆' : '')];
}

/**
 * 把匯入的 ERP 對帳單帶到「對帳作業」：由對應到的出貨明細推出 客戶＋帳款月份（取筆數最多者，
 * 帳款月份走與對帳作業完全相同的 acc_bm_for／billing_month_override），對方紙本合計＝ERP 檔的本期應收合計。
 */
function acc_erp_stmt_recon_target(PDO $db, array $stash): array
{
    $ids = [];
    foreach ($stash['entries'] as $e) if (empty($e['kind']) || $e['kind'] !== 'return') if (!empty($e['is_id'])) $ids[(int)$e['is_id']] = 1;
    if (!$ids) return ['success' => false, 'message' => '這份 ERP 對帳單沒有對應到任何網頁出貨明細，無法帶到對帳作業'];
    $ids = array_keys($ids);
    $global = acc_global_cutoff($db);
    $cust = acc_customer_by_name($db);
    $cnt = []; $names = [];
    for ($o = 0; $o < count($ids); $o += 500) {
        $part = array_slice($ids, $o, 500);
        $ph = implode(',', array_fill(0, count($part), '?'));
        $st = $db->prepare("SELECT Client_name, Order_date, billing_month_override FROM is_list WHERE IS_id IN ($ph)");
        $st->execute($part);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = trim((string)$r['Client_name']);
            if ($n === '') continue;
            $bm = trim((string)$r['billing_month_override']);
            if (!preg_match('/^\d{4}-\d{2}$/', $bm)) $bm = acc_bm_for($db, (string)$r['Order_date'], $cust[$n] ?? null, $global);
            $names[$n] = ($names[$n] ?? 0) + 1;
            $cnt[$bm] = ($cnt[$bm] ?? 0) + 1;
        }
    }
    if (!$names || !$cnt) return ['success' => false, 'message' => '查不到客戶或帳款月份'];
    arsort($names); arsort($cnt);
    // 對帳單涵蓋的日期範圍（含退貨列）：對帳作業要用這個範圍帶入，否則落在帳款月截止日之後的明細會少帶
    $dMin = ''; $dMax = '';
    foreach ($stash['entries'] as $e) {
        $d = (string)($e['erp_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
        if ($dMin === '' || $d < $dMin) $dMin = $d;
        if ($dMax === '' || $d > $dMax) $dMax = $d;
    }
    $party = (string)array_key_first($names); $bm = (string)array_key_first($cnt);
    return ['success' => true, 'token' => (string)($stash['token'] ?? ''), 'side' => 'ar', 'party_id' => $party, 'bm' => $bm,
            'their_total' => $stash['their_total'] ?? null, 'date_from' => $dMin, 'date_to' => $dMax,
            'multi_month' => count($cnt) > 1, 'multi_party' => count($names) > 1];
}

/**
 * ERP 對帳單「已核對完」的明細：料號＋數量＋單價都與 ERP 相同（含剛依 ERP 同步完單價的）。
 * 一律以資料庫「現在」的值對 ERP 值判定（不信前端），退貨單只認已一致的。回傳 ['IS-123','IR-45',…]，
 * 對帳作業開啟時據此把對應列標成「已對到」。
 */
function acc_erp_stmt_checked_keys(PDO $db, array $stash): array
{
    $keys = [];
    $isIds = [];
    foreach ($stash['entries'] as $e) {
        if (($e['kind'] ?? '') === 'return') {
            if (($e['status'] ?? '') === 'same' && !empty($e['ir_id'])) $keys[] = 'IR-' . (int)$e['ir_id'];
            continue;
        }
        if (!empty($e['is_id']) && in_array($e['status'], ['same', 'price'], true)) $isIds[(int)$e['is_id']] = $e;
    }
    // 「網頁查無」且已由匯入自動新增進 is_list 的明細：ERP 與網頁必然一致，也算已對到
    $fnd = $db->prepare("SELECT IS_id FROM is_list WHERE IS_number=? AND Qty=? AND ABS(Unit_price-?)<0.00005
                         AND Note LIKE '匯入ERP自動新增%' AND ? LIKE CONCAT(Product_id,'%') LIMIT 1");
    foreach ($stash['entries'] as $e) {
        if (($e['kind'] ?? '') === 'return' || ($e['status'] ?? '') !== 'erp_only' || (float)($e['erp_qty'] ?? 0) <= 0) continue;
        $fnd->execute([$e['no'], (int)round($e['erp_qty']), (float)$e['erp_price'], (string)$e['erp_spec']]);
        $nid = $fnd->fetchColumn();
        if ($nid) $keys[] = 'IS-' . (int)$nid;
    }
    foreach (array_chunk(array_keys($isIds), 500) as $part) {
        $ph = implode(',', array_fill(0, count($part), '?'));
        $st = $db->prepare("SELECT IS_id, Qty, Unit_price FROM is_list WHERE IS_id IN ($ph)");
        $st->execute($part);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $e = $isIds[(int)$r['IS_id']];
            if (abs(abs((float)$r['Qty']) - abs((float)$e['erp_qty'])) < 0.0001
                && abs((float)$r['Unit_price'] - (float)$e['erp_price']) < 0.00005) $keys[] = 'IS-' . (int)$r['IS_id'];
        }
    }
    return $keys;
}


/** 在出貨明細備註附加一段註記（備註欄只有 100 字：優先保留註記，舊備註從尾端截） */
function acc_erp_stmt_note(PDO $db, int $isId, string $annot): void
{
    $st = $db->prepare("SELECT Note FROM is_list WHERE IS_id=?");
    $st->execute([$isId]);
    $old = trim((string)$st->fetchColumn());
    $old = trim((string)preg_replace('/\s*匯入ERP自動.*$/us', '', $old));   // 重複匯入時不疊加舊的自動註記
    $annot = mb_substr($annot, 0, 90);
    $keep = mb_substr($old, 0, max(0, 99 - mb_strlen($annot)));
    $note = trim($keep . ($keep !== '' ? ' ' : '') . $annot);
    $db->prepare("UPDATE is_list SET Note=? WHERE IS_id=?")->execute([$note, $isId]);
}

/**
 * 帶到對帳作業前的自動同步：①單價差異依 ERP 更正（含綁定鎖定者，只改單價）②ERP 有、網頁查無的出貨明細自動新增。
 * 兩者都在備註註記「匯入ERP自動更正／自動新增」。數量不同的不自動改（可能牽動綁定分配）。
 */
function acc_erp_stmt_sync(PDO $db, array $stash, string $userName): array
{
    $ids = [];
    foreach ($stash['entries'] as $e) {
        if (($e['kind'] ?? '') === 'return' || empty($e['is_id']) || ($e['status'] ?? '') !== 'price') continue;
        if (($e['lock'] ?? '') !== '' && $e['lock'] !== '已鎖定') continue;
        $ids[] = (int)$e['is_id'];
    }
    $r1 = $ids ? acc_erp_stmt_apply($db, $stash, $ids, $userName) : ['success' => true, 'updated' => 0, 'skipped' => []];
    if (empty($r1['success'])) return $r1;
    $r2 = acc_erp_stmt_create_missing($db, $stash, $userName);
    return ['success' => true, 'updated' => (int)($r1['updated'] ?? 0), 'skipped' => array_merge($r1['skipped'] ?? [], $r2['skipped']),
            'created' => $r2['created']];
}

/** ERP 有、網頁查無的出貨明細 → 新增進 is_list（註記匯入ERP自動新增）。料號對不到主檔的略過並回報。 */
function acc_erp_stmt_create_missing(PDO $db, array $stash, string $userName): array
{
    $created = 0; $skip = [];
    $cands = [];
    foreach ($stash['entries'] as $e) if (($e['kind'] ?? '') !== 'return' && ($e['status'] ?? '') === 'erp_only' && (float)$e['erp_qty'] > 0) $cands[] = $e;
    if (!$cands) return ['created' => 0, 'skipped' => []];
    // 同一張出貨單的客戶：優先沿用網頁同單號其他明細的客戶，其次 ERP 檔頭的客戶編號
    $clientOf = [];
    foreach ($stash['entries'] as $e) if (!empty($e['site_client'])) $clientOf[$e['no']] = $e['site_client'];
    $hdrClient = '';
    $hdrCid = (string)($stash['customer_id'] ?? '');
    if ($hdrCid !== '') {
        $q = $db->prepare("SELECT customer FROM customer_list WHERE customer_id=? LIMIT 1");
        $q->execute([$hdrCid]);
        $hdrClient = (string)$q->fetchColumn();
    }
    $pick = $db->prepare("SELECT d_id, D_Setting_Id, Customer_Id FROM d_setting
                          WHERE ? LIKE CONCAT(D_Setting_Id, '%') AND D_Setting_Id <> '' AND (? = '' OR Customer_Id = ?)   -- 有客戶編號時料號必須屬於該客戶，避免短料號誤配
                          ORDER BY (Customer_Id = ?) DESC, CHAR_LENGTH(D_Setting_Id) DESC, d_id DESC LIMIT 1");
    $dup = $db->prepare("SELECT 1 FROM is_list WHERE IS_number=? AND Product_id=? AND Qty=? AND ABS(Unit_price-?)<0.00005 AND Note LIKE '匯入ERP自動新增%' LIMIT 1");
    $ins = $db->prepare("INSERT INTO is_list (Order_date, IS_number, Client_name, d_setting_id, Product_id, Specification, Qty, Unit_price, Warehouse, Note, Created_By)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $log = $db->prepare("INSERT INTO acc_erp_stmt_log (batch_token,file_name,is_id,is_number,part_no,old_qty,new_qty,old_price,new_price,applied_by) VALUES (?,?,?,?,?,NULL,?,NULL,?,?)");
    foreach ($cands as $e) {
        $label = $e['no'] . ' ' . $e['erp_spec'];
        $pick->execute([$e['erp_spec'], $hdrCid, $hdrCid, $hdrCid]);
        $d = $pick->fetch(PDO::FETCH_ASSOC);
        $client = $clientOf[$e['no']] ?? $hdrClient;
        if (!$d) { $skip[] = $label . '：料號對不到主檔，未新增'; continue; }
        if ($client === '') { $skip[] = $label . '：查不到客戶，未新增'; continue; }
        $pid = (string)$d['D_Setting_Id'];
        $dup->execute([$e['no'], $pid, (int)round($e['erp_qty']), (float)$e['erp_price']]);
        if ($dup->fetchColumn()) continue;
        $rest = trim(mb_substr((string)$e['erp_spec'], mb_strlen($pid)));
        try {
            $ins->execute([$e['erp_date'], $e['no'], $client, (int)$d['d_id'], $pid, mb_substr($rest, 0, 80),
                           (int)round($e['erp_qty']), (float)$e['erp_price'], '總倉庫', '匯入ERP自動新增', mb_substr($userName, 0, 11)]);
            $newId = (int)$db->lastInsertId();
            $log->execute([$stash['token'], $stash['file_name'], $newId, $e['no'], $pid, $e['erp_qty'], $e['erp_price'], $userName]);
            $created++;
        } catch (Throwable $ex) { $skip[] = $label . '：新增失敗（' . $ex->getMessage() . '）'; }
    }
    return ['created' => $created, 'skipped' => $skip];
}
